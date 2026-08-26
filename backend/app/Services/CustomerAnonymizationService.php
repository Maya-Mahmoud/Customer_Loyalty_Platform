<?php

namespace App\Services;

use App\Enums\ConsentStatus;
use App\Enums\LedgerEntryType;
use App\Enums\VoucherStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Loyalty\CycleSnapshot;
use App\Services\Loyalty\LoyaltyEngine;
use App\Services\Loyalty\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Erasing a customer at their request (BRD FR-CUS-10, section 16).
 *
 * Two obligations pull against each other here, and the resolution is the whole
 * design. Section 16 gives the customer the right to have their personal data
 * removed. Accounting gives the merchant the duty to keep a record of sales that
 * actually happened — an invoice cannot be unmade because the buyer later asked.
 *
 * So the record is not deleted: it is stripped of everything that identifies a
 * person, and the sales, the entries and the rewards stay exactly as they were. What
 * remains is a numbered party to a set of transactions, which is what an accountant
 * needs and no longer personal data.
 *
 * Anything still owed is settled first, on purpose. A balance or a live voucher left
 * behind would belong to somebody who no longer exists in the system — unclaimable
 * by them and unexplainable to an auditor. Closing them out is recorded in the
 * ledger like every other movement (BR-008).
 *
 * There is no undo. The one thing that could reverse it is the personal data, and
 * that is precisely what was removed.
 */
class CustomerAnonymizationService
{
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly VoucherService $vouchers,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{customer: Customer, balance_written_off: float, vouchers_cancelled: int}
     */
    public function anonymize(Customer $customer, User $actor, string $reason): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => __('Record what the customer asked for, in a sentence.'),
            ]);
        }

        if ($customer->isAnonymized()) {
            throw new ConflictHttpException(__('This customer has already been anonymised.'));
        }

        $snapshot = $this->engine->snapshot($customer);
        $balance = $snapshot === null ? 0.0 : $snapshot->totalAmount;

        $result = DB::transaction(function () use ($customer, $actor, $reason, $snapshot, $balance): array {
            $cancelled = $this->settleVouchers($customer, $actor);

            if ($snapshot !== null && ($balance > 0 || $snapshot->invoiceCount > 0)) {
                $this->closeBalance($customer, $snapshot, $actor);
            }

            $this->strip($customer);

            return [
                'customer' => $customer,
                'balance_written_off' => round($balance, 2),
                'vouchers_cancelled' => $cancelled,
            ];
        });

        /*
         * The audit entry records the act, never the person. Writing the old phone
         * number into the log would move the personal data from one table to another
         * and leave the request unfulfilled — which is exactly the mistake this
         * comment exists to stop someone making later.
         */
        $this->audit->record(
            action: 'customer.anonymized',
            entity: $customer,
            after: [
                'customer_id' => $customer->getKey(),
                'reason' => $reason,
                'balance_written_off' => $result['balance_written_off'],
                'vouchers_cancelled' => $result['vouchers_cancelled'],
            ],
            actor: $actor,
        );

        return $result;
    }

    /**
     * Withdraws anything still spendable. A used voucher is left alone: it was
     * already honoured, and the sale it paid for is part of the record.
     */
    private function settleVouchers(Customer $customer, User $actor): int
    {
        $cancelled = 0;

        $live = Voucher::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', VoucherStatus::Issued)
            ->get();

        foreach ($live as $voucher) {
            $this->vouchers->cancel($voucher, $actor, __('Withdrawn at the customer\'s own request.'));
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Writes the open cycle off with an entry of its own, so the sum of the
     * customer's entries still equals their balance — zero (BRD 20).
     */
    private function closeBalance(Customer $customer, CycleSnapshot $snapshot, User $actor): void
    {
        $branchId = LedgerEntry::query()
            ->where('customer_id', $customer->getKey())
            ->forCycle($snapshot->cycleNumber)
            ->orderByDesc('id')
            ->value('branch_id') ?? $customer->registered_at_branch_id;

        LedgerEntry::create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branchId,
            'cycle_number' => $snapshot->cycleNumber,
            /*
             * Expiry rather than a manual adjustment: nobody decided this balance
             * was wrong, it simply stopped being claimable. The type is what a
             * report will group it under later.
             */
            'type' => LedgerEntryType::Expiry,
            'amount' => -$snapshot->totalAmount,
            'invoice_count_delta' => -$snapshot->invoiceCount,
            'loyalty_rule_id' => $snapshot->rule->getKey(),
            'created_by' => $actor->getKey(),
            'note' => __('Balance closed on a data erasure request.'),
        ]);
    }

    /**
     * Removes the identity and leaves the transactions.
     *
     * The phone becomes a unique placeholder rather than null: BR-002 keys a customer
     * on (merchant, phone), so a null would collide with the next erasure and a blank
     * would collide with itself. Deriving it from the id keeps it unique for free —
     * and it reads as what it is when someone opens the table.
     */
    private function strip(Customer $customer): void
    {
        $customer->forceFill([
            'phone' => 'ANON-' . $customer->getKey(),
            'name' => null,
            'consent_status' => ConsentStatus::Withdrawn,
            'consent_recorded_at' => now(),
            'phone_verified_at' => null,
            // Nothing can be recorded against them again, which is the point: a new
            // sale would start rebuilding the profile that was just removed.
            'is_active' => false,
            'anonymized_at' => now(),
        ])->save();
    }
}
