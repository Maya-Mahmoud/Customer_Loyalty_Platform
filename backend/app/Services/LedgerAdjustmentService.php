<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Loyalty\LoyaltyEngine;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Correcting a balance by hand (BRD 7.2, ledger.adjust).
 *
 * The escape hatch. However carefully the rules are written, a shop will hit a case
 * they were not written for — a system outage during a sale, a goodwill gesture, a
 * migration from paper cards — and without a way to put a number right, the staff
 * invent one: a fake invoice. That is the fraud AF-01 describes, caused by the
 * absence of a legitimate path rather than by dishonesty.
 *
 * So the path exists, and it is expensive to use. The owner only, never a manager
 * (BRD 7.2 gives this to no other role). A written reason, always. An audit entry,
 * always. And it is an entry on the ledger like every other movement (BR-008), so it
 * shows up in the reconciliation of BRD 20 rather than hiding inside a stored total.
 */
class LedgerAdjustmentService
{
    /** A negative adjustment can zero a balance, never push it below zero. */
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array{amount: float|string, reason: string, branch_id?: int|null}  $data
     */
    public function adjust(Customer $customer, array $data, User $actor): LedgerEntry
    {
        $amount = round((float) $data['amount'], 2);
        $reason = trim($data['reason']);

        if ($amount === 0.0) {
            throw ValidationException::withMessages([
                'amount' => __('Enter an amount above or below zero.'),
            ]);
        }

        $snapshot = $this->engine->snapshot($customer);

        if ($snapshot === null) {
            throw new ConflictHttpException(
                __('No loyalty rule is published, so there is no balance to adjust.')
            );
        }

        /*
         * A deduction cannot take more than is there. Allowing it would create a
         * negative balance the customer never spent, and the only honest way to
         * take back a reward that was already paid is not to pay it — see the
         * reversal rules of BRD 8.7.
         */
        if ($amount < 0 && abs($amount) > $snapshot->totalAmount) {
            throw ValidationException::withMessages([
                'amount' => __('This is more than the customer has accumulated (:balance).', [
                    'balance' => number_format($snapshot->totalAmount, 2, '.', ''),
                ]),
            ]);
        }

        $branchId = $actor->branch_id ?? ($data['branch_id'] ?? null);

        if ($branchId === null) {
            throw new ConflictHttpException(__('Choose the branch this adjustment belongs to.'));
        }

        $entry = LedgerEntry::create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branchId,
            'cycle_number' => $customer->current_cycle_number,
            'type' => LedgerEntryType::ManualAdjustment,
            'amount' => $amount,
            // The visit count is left alone. An adjustment moves money, and
            // inventing a visit would let a count threshold be reached without
            // anyone walking in (BR-018 and AF-01 both aim at that).
            'invoice_count_delta' => 0,
            'loyalty_rule_id' => $snapshot->rule->getKey(),
            'created_by' => $actor->getKey(),
            'note' => $reason,
        ]);

        $this->audit->record(
            action: 'ledger.adjusted',
            entity: $entry,
            before: ['balance' => round($snapshot->totalAmount, 2)],
            after: [
                'amount' => $amount,
                'balance' => round($snapshot->totalAmount + $amount, 2),
                'cycle_number' => $customer->current_cycle_number,
                'reason' => $reason,
            ],
            actor: $actor,
        );

        return $entry;
    }

    /**
     * Adjustments already made for this customer, so the same correction is not
     * entered twice and so the owner can see their own history of exceptions.
     */
    public function historyFor(Customer $customer, int $limit = 10)
    {
        return LedgerEntry::with(['branch', 'createdBy'])
            ->where('customer_id', $customer->getKey())
            ->where('type', LedgerEntryType::ManualAdjustment)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
