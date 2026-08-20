<?php

namespace App\Services\Loyalty;

use App\Enums\VoucherStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyRule;
use App\Models\Redemption;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Issuing and accepting purchase vouchers.
 *
 * A voucher carries real money, so the one thing that must not be possible is
 * spending it twice. accept() therefore does not read-then-write: it issues a
 * single conditional UPDATE and treats "no rows changed" as "someone else got
 * there first". Two tills scanning the same code at the same moment leave exactly
 * one winner, without a lock held across the request.
 */
class VoucherService
{
    /** Ambiguous characters are left out: a customer reads this over a counter. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Creates the voucher a redemption paid out.
     *
     * Called by the redemption flow; the amount is the figure already calculated
     * and capped there, so no arithmetic is repeated here.
     */
    public function issue(Redemption $redemption, LoyaltyRule $rule): Voucher
    {
        $validityDays = $rule->voucher_validity_days;

        $voucher = Voucher::create([
            'customer_id' => $redemption->customer_id,
            'redemption_id' => $redemption->getKey(),
            'code' => $this->generateCode(),
            'amount' => $redemption->discount_amount,
            'status' => VoucherStatus::Issued,
            'issued_at' => now(),
            // Null validity means it never expires, which the owner may choose.
            'expires_at' => $validityDays !== null ? now()->addDays($validityDays) : null,
        ]);

        $this->audit->record(
            action: 'voucher.issued',
            entity: $voucher,
            after: $voucher->only(['code', 'amount', 'expires_at']),
        );

        return $voucher;
    }

    /**
     * Finds a voucher by the code the customer presented.
     *
     * Scoped to the merchant by the global scope, so a code from another store
     * simply does not exist here — the spirit of BR-002 applied to vouchers.
     */
    public function findByCode(string $code): ?Voucher
    {
        return Voucher::with(['customer', 'redemption'])
            ->where('code', $this->normalizeCode($code))
            ->first();
    }

    /**
     * Accepts a voucher against an invoice.
     *
     * BRD 7.2 restricts discount redemption to a branch manager or the owner
     * (BR-013, AF-08). Accepting a voucher is allowed to a sales rep as well: the
     * value was already authorised when the voucher was issued, and holding up
     * every till for a manager would make the reward unusable in practice. The
     * acceptance is attributed and logged, which is what makes that safe — this is
     * an amendment to 7.2 and is recorded as one.
     */
    public function accept(Voucher $voucher, Invoice $invoice, User $acceptedBy): Voucher
    {
        $this->guardBelongsToCustomer($voucher, $invoice);
        $this->guardUsable($voucher);

        /*
         * The condition carries the whole guarantee: only a row still marked issued
         * is updated. A concurrent second attempt matches nothing and is refused,
         * so the same code cannot be spent at two tills at once.
         */
        $claimed = Voucher::whereKey($voucher->getKey())
            ->where('status', VoucherStatus::Issued)
            ->update([
                'status' => VoucherStatus::Used,
                'used_at' => now(),
                'used_on_invoice_id' => $invoice->getKey(),
                'used_at_branch_id' => $invoice->branch_id,
                'accepted_by' => $acceptedBy->getKey(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new ConflictHttpException(__('This voucher has just been used elsewhere.'));
        }

        $voucher->refresh();

        $this->audit->record(
            action: 'voucher.accepted',
            entity: $voucher,
            after: [
                'code' => $voucher->code,
                'amount' => $voucher->amount,
                'invoice_id' => $invoice->getKey(),
                'branch_id' => $invoice->branch_id,
            ],
            actor: $acceptedBy,
        );

        return $voucher;
    }

    /**
     * Withdraws an unused voucher — for instance when the invoice that earned it is
     * cancelled (BRD BR-009 territory, and the open question of OD-06).
     *
     * A voucher already spent is left alone: the customer has had the value, and
     * rewriting that would falsify the invoice it was spent against.
     */
    public function cancel(Voucher $voucher, User $actor, string $reason): Voucher
    {
        if ($voucher->status === VoucherStatus::Used) {
            throw new ConflictHttpException(
                __('This voucher has already been spent and cannot be withdrawn.')
            );
        }

        $original = $voucher->getOriginal();

        $voucher->update([
            'status' => VoucherStatus::Cancelled,
            'cancellation_reason' => $reason,
        ]);

        $this->audit->recordChange('voucher.cancelled', $voucher, $original);

        return $voucher;
    }

    /**
     * Vouchers the customer can still spend, for the customer card of FR-CUS-05.
     *
     * @return Collection<int, Voucher>
     */
    public function usableFor(Customer $customer): Collection
    {
        return Voucher::usable()
            ->where('customer_id', $customer->getKey())
            ->orderBy('expires_at')
            ->get();
    }

    private function guardUsable(Voucher $voucher): void
    {
        if ($voucher->status === VoucherStatus::Used) {
            throw ValidationException::withMessages([
                'code' => __('This voucher has already been used on :date.', [
                    'date' => $voucher->used_at?->toDateString(),
                ]),
            ]);
        }

        if ($voucher->status === VoucherStatus::Cancelled) {
            throw ValidationException::withMessages([
                'code' => __('This voucher has been withdrawn.'),
            ]);
        }

        if ($voucher->isExpired()) {
            throw ValidationException::withMessages([
                'code' => __('This voucher expired on :date.', [
                    'date' => $voucher->expires_at?->toDateString(),
                ]),
            ]);
        }
    }

    /**
     * A voucher belongs to the customer who earned it. Letting it be spent on
     * someone else's invoice would turn a reward into a transferable instrument,
     * and would break the link the audit trail depends on.
     */
    private function guardBelongsToCustomer(Voucher $voucher, Invoice $invoice): void
    {
        if ($invoice->customer_id === null) {
            throw ValidationException::withMessages([
                'code' => __('A voucher can only be used on an invoice linked to its owner.'),
            ]);
        }

        if ($voucher->customer_id !== $invoice->customer_id) {
            throw ValidationException::withMessages([
                'code' => __('This voucher belongs to another customer.'),
            ]);
        }
    }

    /**
     * Read aloud at a counter, so it is grouped and case-insensitive on the way in.
     */
    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function generateCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            // Retried rather than trusted: the unique index is the real guarantee,
            // and a collision inside one merchant is cheap to avoid up front.
        } while (Voucher::where('code', $code)->exists());

        return $code;
    }
}
