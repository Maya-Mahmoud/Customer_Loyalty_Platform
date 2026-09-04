<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\VoucherStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Services\Loyalty\LoyaltyEngine;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * The customer looking themselves up (BRD FR-CUS-12).
 *
 * The customer has no account and never will (BR-001), so there is no session to
 * sign in to and nothing that behaves like a password. What stands in for one is a
 * receipt: they name the shop, give the number they are registered under, and quote
 * something only somebody holding a receipt from that shop would know.
 *
 * That is weaker than a one-time code sent to the phone, and it is a deliberate
 * interim: no SMS provider is contracted (BRD OD-08, section 5.5), and a lookup that
 * asked for nothing but a phone number would let anyone type numbers until they
 * learned where a person shops and how much they spend. When the provider is signed,
 * the code becomes the proof and this becomes the fallback.
 *
 * Two consequences shape the whole class:
 *
 *  - proof is per shop. A receipt from one store shows that store's card and no
 *    other, because holding it says nothing about the customer's relationship with
 *    anybody else. The customer repeats the step per shop, which is also exactly the
 *    form the question takes ("what is my balance at this shop").
 *  - the answer contains the customer's own position only — never an invoice list,
 *    a staff name, or any figure that describes the merchant's business.
 */
class CustomerSelfServiceLookup
{
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Shops a customer can choose from. Public on purpose: a shop's name and city are
     * on its sign, and the list has to exist for the customer to name one.
     *
     * @return list<array<string, mixed>>
     */
    public function stores(): array
    {
        return Merchant::withoutGlobalScopes()
            ->where('status', \App\Enums\MerchantStatus::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'trade_name', 'city'])
            ->map(fn (Merchant $merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->trade_name ?: $merchant->name,
                'city' => $merchant->city,
            ])
            ->all();
    }

    /**
     * The card, if the three answers agree.
     *
     * @param  array{merchant_id: int, phone: string, invoice_number?: string|null, invoice_date?: string|null, amount?: float|string|null}  $claim
     * @return array<string, mixed>|null
     */
    public function verify(array $claim): ?array
    {
        $phone = PhoneNumber::normalize($claim['phone'] ?? null);

        if ($phone === null) {
            return null;
        }

        $merchant = Merchant::withoutGlobalScopes()->find($claim['merchant_id']);

        // A suspended or pending store is not open for business, and quoting a
        // balance nobody there can honour today would be a promise, not an answer.
        if ($merchant === null || ! $merchant->isActive()) {
            return null;
        }

        return $this->tenant->for($merchant->id, function () use ($claim, $phone, $merchant) {
            $customer = Customer::query()
                ->where('phone', $phone)
                ->whereNull('anonymized_at')
                ->where('is_active', true)
                ->first();

            if ($customer === null) {
                return null;
            }

            if (! $this->proofMatches($customer, $claim)) {
                return null;
            }

            return $this->card($customer, $merchant);
        });
    }

    /**
     * Whether the claim is backed by something only the customer would have.
     *
     * The receipt number is the strong form. The fallback — the date and the amount
     * of the last purchase — exists because receipts get thrown away, and it is
     * accepted knowing it is weaker: a person in the queue behind them could have
     * seen both. It is bounded by only ever matching the *latest* purchase, so it
     * cannot be walked backwards through a customer's history.
     *
     * @param  array<string, mixed>  $claim
     */
    private function proofMatches(Customer $customer, array $claim): bool
    {
        $number = trim((string) ($claim['invoice_number'] ?? ''));

        if ($number !== '') {
            return Invoice::query()
                ->where('customer_id', $customer->getKey())
                ->where('invoice_number', $number)
                ->exists();
        }

        $date = $claim['invoice_date'] ?? null;
        $amount = $claim['amount'] ?? null;

        if ($date === null || $amount === null) {
            return false;
        }

        $latest = Invoice::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', InvoiceStatus::Active)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return false;
        }

        return $latest->invoice_date?->toDateString() === (string) $date
            && abs((float) $latest->amount - (float) $amount) < 0.01;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Customer $customer, Merchant $merchant): array
    {
        $snapshot = $this->engine->snapshot($customer);

        $vouchers = Voucher::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', VoucherStatus::Issued)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->orderBy('expires_at')
            ->get();

        return [
            'store' => $merchant->trade_name ?: $merchant->name,
            'city' => $merchant->city,
            'currency' => $merchant->currency,
            'logo_url' => $merchant->logo_path === null
                ? null
                : Storage::disk('public')->url($merchant->logo_path),

            'name' => $customer->name,
            'balance' => $snapshot === null ? 0.0 : round($snapshot->totalAmount, 2),
            'invoice_count' => $snapshot?->invoiceCount ?? 0,
            'is_eligible' => $snapshot?->isEligible() ?? false,
            'amount_remaining' => $snapshot?->amountRemaining() !== null
                ? round($snapshot->amountRemaining(), 2)
                : null,
            'invoices_remaining' => $snapshot?->invoicesRemaining(),
            'progress' => $snapshot === null ? 0.0 : round($snapshot->progress(), 4),

            /*
             * What the reward will be, in the customer's own terms. A balance without
             * it is a number with no meaning, and "what do I get" is the entire
             * question being asked here.
             */
            'reward' => $snapshot === null ? null : [
                'type' => $snapshot->rule->reward_type->value,
                'value' => $snapshot->rule->reward_value,
                'max_discount' => $snapshot->rule->max_discount_amount,
            ],

            'vouchers' => $vouchers->map(fn (Voucher $voucher) => [
                'code' => $voucher->code,
                'amount' => $voucher->amount,
                'expires_at' => $voucher->expires_at?->toDateString(),
            ])->all(),

            'last_purchase_at' => $customer->last_purchase_at?->toDateString(),
        ];
    }
}
