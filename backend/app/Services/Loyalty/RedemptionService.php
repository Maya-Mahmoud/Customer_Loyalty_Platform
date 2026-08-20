<?php

namespace App\Services\Loyalty;

use App\Enums\LedgerEntryType;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Paying out a reward and opening the next cycle (BRD 8.6).
 *
 * This is where the system gives money away, so it is the operation most worth
 * getting exactly right. Six things happen as one unit: the reward is calculated,
 * a redemption is recorded, the cycle is closed with a ledger entry, the surplus is
 * carried into a new cycle, a voucher is issued if that is the reward shape, and
 * the whole thing is audited. A partial success would leave a customer either paid
 * twice or paid nothing with their balance gone.
 *
 * The cycle number is what makes it safe against two managers pressing redeem at
 * the same moment. Advancing it is a conditional update, so exactly one of them
 * wins and the loser is told why.
 */
class RedemptionService
{
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly VoucherService $vouchers,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * What the customer would receive right now, without paying anything out.
     *
     * The confirmation step of BRD 8.6 step 4 shows this first: a manager should
     * see the figure before authorising it, not after.
     */
    public function preview(Customer $customer, ?int $branchId = null): ?array
    {
        $snapshot = $this->engine->snapshot($customer, branchId: $branchId);

        if ($snapshot === null || ! $snapshot->isEligible()) {
            return null;
        }

        $reward = $this->engine->reward($snapshot);

        return [
            'snapshot' => $snapshot,
            'reward' => $reward,
        ];
    }

    /**
     * Pays the reward out.
     *
     * @param  array{override?: bool, override_reason?: string|null}  $options
     */
    public function redeem(Customer $customer, User $performedBy, array $options = []): Redemption
    {
        $override = (bool) ($options['override'] ?? false);
        $reason = $options['override_reason'] ?? null;

        $branchId = $performedBy->branch_id ?? ($options['branch_id'] ?? null);

        if ($branchId === null) {
            throw new ConflictHttpException(__('Choose the branch this redemption happens at.'));
        }

        $rule = $this->engine->ruleFor($customer);

        if ($rule === null) {
            throw new ConflictHttpException(
                __('No loyalty rule is published, so there is nothing to pay out.')
            );
        }

        $snapshot = $this->engine->snapshot($customer, $rule, $branchId);

        $this->guardEligibility($snapshot, $override, $reason, $performedBy);
        $this->guardOnePerDay($customer, $override, $reason, $performedBy);

        /*
         * An override pays out a cycle that has not reached the threshold, so the
         * normal calculation refuses it. The reward is taken at face value instead:
         * a percentage of what was actually accumulated, or the flat amount.
         */
        $reward = $snapshot->isEligible()
            ? $this->engine->reward($snapshot)
            : $this->overrideReward($snapshot);

        $closingCycle = $customer->current_cycle_number;

        $redemption = DB::transaction(function () use (
            $customer, $performedBy, $rule, $snapshot, $reward, $branchId, $closingCycle, $override, $reason
        ): Redemption {
            /*
             * The claim. Only a customer still sitting on the cycle we calculated
             * advances, so a second manager racing us matches nothing and is
             * refused rather than paying the same cycle out twice.
             */
            $claimed = Customer::whereKey($customer->getKey())
                ->where('current_cycle_number', $closingCycle)
                ->update([
                    'current_cycle_number' => $closingCycle + 1,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new ConflictHttpException(
                    __('This cycle has just been redeemed elsewhere. Look the customer up again.')
                );
            }

            $redemption = Redemption::create([
                'customer_id' => $customer->getKey(),
                'branch_id' => $branchId,
                'loyalty_rule_id' => $rule->getKey(),
                'cycle_number' => $closingCycle,
                // A snapshot of the cycle as it stood, so the figure can be
                // explained later without recomputing against a rule that may
                // since have changed (BRD 11.2).
                'cycle_total_amount' => $snapshot->totalAmount,
                'cycle_invoice_count' => $snapshot->invoiceCount,
                'reward_type' => $reward->rewardType,
                'computed_amount' => $reward->computedAmount,
                'discount_amount' => $reward->discountAmount,
                'carried_over_amount' => $reward->carriedOverAmount,
                'performed_by' => $performedBy->getKey(),
                'is_override' => $override,
                'override_reason' => $override ? $reason : null,
                // BRD BR-014: an override is the owner's own decision, so they are
                // both the approver and, here, the actor.
                'override_approved_by' => $override ? $performedBy->getKey() : null,
                'redeemed_at' => now(),
            ]);

            $this->closeCycle($customer, $redemption, $snapshot, $branchId, $closingCycle, $performedBy);
            $this->openNextCycle($customer, $redemption, $reward, $branchId, $closingCycle + 1, $performedBy);

            if ($reward->rewardType === RewardType::Voucher) {
                $this->vouchers->issue($redemption, $rule);
            }

            return $redemption;
        });

        $this->audit->record(
            action: $override ? 'redemption.override' : 'redemption.paid',
            entity: $redemption,
            after: [
                'cycle_number' => $closingCycle,
                'cycle_total' => $snapshot->totalAmount,
                'computed_amount' => $reward->computedAmount,
                'discount_amount' => $reward->discountAmount,
                'carried_over' => $reward->carriedOverAmount,
                'reward_type' => $reward->rewardType->value,
                'override_reason' => $override ? $reason : null,
            ],
            actor: $performedBy,
        );

        return $redemption;
    }

    /**
     * Past rewards for the customer card (BRD FR-RED-07).
     */
    public function historyFor(Customer $customer, int $limit = 10)
    {
        return Redemption::with(['branch', 'performedBy'])
            ->where('customer_id', $customer->getKey())
            ->orderByDesc('redeemed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * BRD BR-005: the closing entry settles the cycle. It carries the negative of
     * what was accumulated, so the closed cycle nets to zero and the sum of a
     * customer's entries stays equal to their balance.
     */
    private function closeCycle(
        Customer $customer,
        Redemption $redemption,
        CycleSnapshot $snapshot,
        int $branchId,
        int $cycleNumber,
        User $performedBy,
    ): void {
        LedgerEntry::create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branchId,
            'cycle_number' => $cycleNumber,
            'type' => LedgerEntryType::CycleClose,
            'amount' => -$snapshot->totalAmount,
            'invoice_count_delta' => -$snapshot->invoiceCount,
            'source_type' => $redemption::class,
            'source_id' => $redemption->getKey(),
            'loyalty_rule_id' => $redemption->loyalty_rule_id,
            'created_by' => $performedBy->getKey(),
        ]);
    }

    /**
     * BRD BR-006: the surplus above the threshold opens the next cycle, unless the
     * owner chose a full reset. Nothing is written when there is nothing to carry,
     * so the ledger does not fill with zero-value rows.
     *
     * BRD BR-007 is why the invoice count does not follow the money under an amount
     * threshold: those purchases have already earned a reward once.
     */
    private function openNextCycle(
        Customer $customer,
        Redemption $redemption,
        RewardCalculation $reward,
        int $branchId,
        int $cycleNumber,
        User $performedBy,
    ): void {
        if ($reward->carriedOverAmount <= 0 && $reward->carriedOverInvoices <= 0) {
            return;
        }

        LedgerEntry::create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branchId,
            'cycle_number' => $cycleNumber,
            'type' => LedgerEntryType::CarryOver,
            'amount' => $reward->carriedOverAmount,
            'invoice_count_delta' => $reward->carriedOverInvoices,
            'source_type' => $redemption::class,
            'source_id' => $redemption->getKey(),
            'loyalty_rule_id' => $redemption->loyalty_rule_id,
            'created_by' => $performedBy->getKey(),
            'note' => __('Surplus carried from cycle :cycle.', ['cycle' => $redemption->cycle_number]),
        ]);
    }

    /**
     * What an override pays. The cycle never reached the threshold, so there is no
     * surplus to carry and the reward is not scaled up to one — a percentage
     * applies to what was actually accumulated.
     */
    private function overrideReward(CycleSnapshot $snapshot): RewardCalculation
    {
        $rule = $snapshot->rule;

        $computed = $rule->reward_type === RewardType::Percentage
            ? $snapshot->totalAmount * ((float) $rule->reward_value / 100)
            : (float) $rule->reward_value;

        $paid = $rule->reward_type->needsCap() && $rule->max_discount_amount !== null
            ? min($computed, (float) $rule->max_discount_amount)
            : $computed;

        return new RewardCalculation(
            rewardType: $rule->reward_type,
            computedAmount: round($computed, 2),
            discountAmount: round($paid, 2),
            carriedOverAmount: 0.0,
            carriedOverInvoices: 0,
        );
    }

    /**
     * BRD FR-RED-06 and BR-014: an ineligible customer is paid only through an
     * override that the store owner approves, with a written reason.
     *
     * A branch manager cannot approve their own exception — that is the whole point
     * of requiring approval, and BR-013 already keeps the payout away from the rep.
     */
    private function guardEligibility(
        ?CycleSnapshot $snapshot,
        bool $override,
        ?string $reason,
        User $performedBy,
    ): void {
        if ($snapshot === null) {
            throw new ConflictHttpException(
                __('No loyalty rule is published, so there is nothing to pay out.')
            );
        }

        if ($snapshot->isEligible()) {
            return;
        }

        if (! $override) {
            throw new ConflictHttpException(
                __('This customer has not reached the threshold yet.')
            );
        }

        $this->guardOverrideAuthority($reason, $performedBy);
    }

    /**
     * BRD BR-018: one reward a day, unless the owner decides otherwise. It stops a
     * customer — or a colluding rep — draining the programme in an afternoon.
     */
    private function guardOnePerDay(
        Customer $customer,
        bool $override,
        ?string $reason,
        User $performedBy,
    ): void {
        $alreadyToday = Redemption::where('customer_id', $customer->getKey())
            ->whereDate('redeemed_at', now()->toDateString())
            ->exists();

        if (! $alreadyToday) {
            return;
        }

        if (! $override) {
            throw new ConflictHttpException(
                __('This customer has already received a reward today.')
            );
        }

        $this->guardOverrideAuthority($reason, $performedBy);
    }

    private function guardOverrideAuthority(?string $reason, User $performedBy): void
    {
        if (! $performedBy->isMerchantOwner()) {
            throw new ConflictHttpException(
                __('Only the store owner can authorise an exception. Ask them to approve it.')
            );
        }

        if ($reason === null || mb_strlen(trim($reason)) < 5) {
            throw new ConflictHttpException(
                __('An exception needs a written reason, which is kept in the audit log.')
            );
        }
    }
}
