<?php

namespace App\Services\Loyalty;

use App\Enums\AccumulationScope;
use App\Enums\LedgerEntryType;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rule engine of BRD 11 — the calculating half. Persisting invoices and
 * redemptions belongs to the phases that own those flows; this only reads the
 * ledger and answers questions about it.
 *
 * Two decisions shape everything here:
 *
 *  - the balance is summed from the ledger on demand, never stored (BRD 13.3,
 *    BR-008). It costs a query per lookup and buys the ability to explain any
 *    figure and to recompute retroactively if a bug is ever found;
 *  - the rule that applies is the version in force on the invoice date, not the
 *    latest one (BR-015). Raising a threshold must not move the goalposts for a
 *    customer who is already most of the way there.
 */
class LoyaltyEngine
{
    /**
     * The rule governing a given date, or null when the merchant has none yet.
     */
    public function ruleFor(Customer $customer, ?string $date = null): ?LoyaltyRule
    {
        return $customer->merchant->ruleEffectiveOn($date);
    }

    /**
     * State of the customer's open cycle.
     *
     * branchId narrows the sum for a merchant accumulating per branch
     * (BRD FR-LOY-05); it is ignored under the merchant-wide default, because
     * there the customer deals with the brand rather than the branch (BR-016).
     */
    public function snapshot(Customer $customer, ?LoyaltyRule $rule = null, ?int $branchId = null): ?CycleSnapshot
    {
        $rule ??= $this->ruleFor($customer);

        if ($rule === null) {
            return null;
        }

        $totals = $this->cycleTotals($customer, $rule, $branchId);

        return new CycleSnapshot(
            cycleNumber: $customer->current_cycle_number,
            totalAmount: $totals['amount'],
            invoiceCount: $totals['count'],
            rule: $rule,
        );
    }

    /**
     * Whether an invoice of this size counts towards the cycle at all.
     *
     * BRD BR-003: below the minimum it is still recorded, just not accumulated —
     * which is what stops a sale being split into fragments to manufacture visits.
     */
    public function qualifies(LoyaltyRule $rule, float $invoiceAmount): bool
    {
        return $invoiceAmount >= (float) $rule->min_invoice_amount;
    }

    /**
     * Works out what a redemption pays and what rolls forward.
     *
     * Refuses to calculate for a cycle that has not reached the threshold. Paying
     * out early is possible, but only as the owner-approved override of BR-014,
     * which is the redemption flow's decision to make — not a silent outcome here.
     */
    public function reward(CycleSnapshot $snapshot): RewardCalculation
    {
        if (! $snapshot->isEligible()) {
            throw new RuntimeException('The cycle has not reached the threshold.');
        }

        $rule = $snapshot->rule;
        $computed = $this->computeReward($rule, $snapshot->totalAmount);
        $paid = $this->applyCap($rule, $computed);

        [$carriedAmount, $carriedInvoices] = $this->carryOver($snapshot);

        return new RewardCalculation(
            rewardType: $rule->reward_type,
            computedAmount: round($computed, 2),
            discountAmount: round($paid, 2),
            carriedOverAmount: round($carriedAmount, 2),
            carriedOverInvoices: $carriedInvoices,
        );
    }

    /**
     * The cycle's net figures, straight from the ledger.
     *
     * Reversals are ordinary rows with negative values (BR-009), so cancelling an
     * invoice needs no special case: it simply lowers the sum.
     *
     * @return array{amount: float, count: int}
     */
    private function cycleTotals(Customer $customer, LoyaltyRule $rule, ?int $branchId): array
    {
        $scopedBranch = $rule->accumulation_scope === AccumulationScope::Branch ? $branchId : null;

        /*
         * Every entry type is summed, closing entries included, which keeps one
         * invariant true everywhere: the sum of a customer's entries equals their
         * balance, whether taken over the open cycle or over their whole history.
         *
         * A closing entry carries the negative of the cycle it settled, so a closed
         * cycle nets to zero and the surplus reappears as a carry-over in the next
         * one. The open cycle never holds a closing entry, so nothing here needs to
         * be filtered out — and the reconciliation of BRD 20 can use plain SUM.
         */
        $row = LedgerEntry::query()
            ->where('customer_id', $customer->getKey())
            ->forCycle($customer->current_cycle_number)
            ->forBranch($scopedBranch)
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(invoice_count_delta), 0) AS count')
            ->first();

        return [
            'amount' => (float) ($row->amount ?? 0),
            'count' => (int) ($row->count ?? 0),
        ];
    }

    /**
     * BRD FR-LOY-02. A voucher is a flat amount the owner sets, so it computes
     * exactly like a fixed discount; what differs is that the customer is handed
     * spending credit rather than money off the invoice in front of them.
     */
    private function computeReward(LoyaltyRule $rule, float $cycleTotal): float
    {
        return match ($rule->reward_type) {
            RewardType::Percentage => $cycleTotal * ((float) $rule->reward_value / 100),
            RewardType::FixedAmount, RewardType::Voucher => (float) $rule->reward_value,
        };
    }

    /**
     * BRD BR-021 and FR-LOY-03: the ceiling exists to protect the merchant's
     * margin on a large cycle, so it applies only where the figure scales with the
     * cycle. A flat amount is already bounded by its own value.
     */
    private function applyCap(LoyaltyRule $rule, float $computed): float
    {
        if (! $rule->reward_type->needsCap() || $rule->max_discount_amount === null) {
            return $computed;
        }

        return min($computed, (float) $rule->max_discount_amount);
    }

    /**
     * What moves into the next cycle.
     *
     * BRD BR-006 makes carrying the surplus the default because it is fairer and
     * keeps the customer's trust: reaching 1,160 against a 1,000 threshold should
     * not quietly cost them 160. Full reset is the owner's alternative.
     *
     * @return array{0: float, 1: int}
     */
    private function carryOver(CycleSnapshot $snapshot): array
    {
        if ($snapshot->rule->reset_policy === ResetPolicy::FullReset) {
            return [0.0, 0];
        }

        $rule = $snapshot->rule;

        $amount = $rule->threshold_type->tracksAmount()
            ? max(0.0, $snapshot->totalAmount - $snapshot->thresholdAmount())
            : 0.0;

        $invoices = $rule->threshold_type->tracksInvoiceCount()
            ? max(0, $snapshot->invoiceCount - $snapshot->thresholdCount())
            : 0;

        /*
         * BRD BR-007: the purchases that earned this reward must not earn another.
         * Under an amount threshold the surplus is what is left after the
         * threshold is consumed, so the invoice count restarts from zero — the
         * money has already been counted once.
         */
        if ($rule->threshold_type === ThresholdType::Amount) {
            $invoices = 0;
        }

        return [$amount, $invoices];
    }
}
