<?php

namespace App\Services\Loyalty;

use App\Enums\ThresholdType;
use App\Models\LoyaltyRule;

/**
 * What a customer has accumulated in the cycle that is currently open, and what
 * that means against the rule in force.
 *
 * Read-only and derived entirely from the ledger (BRD 13.3): nothing here is
 * stored, so it cannot drift out of step with the entries it was computed from.
 * Everything the customer card of BRD FR-CUS-05 shows comes from this object.
 */
final readonly class CycleSnapshot
{
    public function __construct(
        public int $cycleNumber,
        /** Sum of qualifying invoices in the cycle, net of reversals. */
        public float $totalAmount,
        /** Count of qualifying invoices in the cycle, net of reversals. */
        public int $invoiceCount,
        public LoyaltyRule $rule,
    ) {
    }

    /**
     * Whether the customer has reached the threshold (BRD FR-RED-01).
     *
     * A rule counting both money and visits requires both: the "both" option of
     * FR-LOY-01 exists to tighten the bar, not to widen it.
     */
    public function isEligible(): bool
    {
        return match ($this->rule->threshold_type) {
            ThresholdType::Amount => $this->amountReached(),
            ThresholdType::InvoiceCount => $this->countReached(),
            ThresholdType::Both => $this->amountReached() && $this->countReached(),
        };
    }

    /**
     * Money still needed, or null when the rule does not track money.
     * Never negative: past the threshold the answer is zero, not a surplus.
     */
    public function amountRemaining(): ?float
    {
        if (! $this->rule->threshold_type->tracksAmount()) {
            return null;
        }

        return max(0.0, $this->thresholdAmount() - $this->totalAmount);
    }

    /**
     * Visits still needed, or null when the rule does not count visits.
     */
    public function invoicesRemaining(): ?int
    {
        if (! $this->rule->threshold_type->tracksInvoiceCount()) {
            return null;
        }

        return max(0, $this->thresholdCount() - $this->invoiceCount);
    }

    /**
     * How far along the cycle is, as a fraction between 0 and 1, for the progress
     * bar of BRD FR-CUS-06.
     *
     * With both conditions active the lower of the two is used: the bar should
     * show the condition still holding the customer back, not the flattering one.
     */
    public function progress(): float
    {
        $ratios = [];

        if ($this->rule->threshold_type->tracksAmount() && $this->thresholdAmount() > 0) {
            $ratios[] = $this->totalAmount / $this->thresholdAmount();
        }

        if ($this->rule->threshold_type->tracksInvoiceCount() && $this->thresholdCount() > 0) {
            $ratios[] = $this->invoiceCount / $this->thresholdCount();
        }

        if ($ratios === []) {
            return 0.0;
        }

        return min(1.0, min($ratios));
    }

    private function amountReached(): bool
    {
        return $this->totalAmount >= $this->thresholdAmount();
    }

    private function countReached(): bool
    {
        return $this->invoiceCount >= $this->thresholdCount();
    }

    public function thresholdAmount(): float
    {
        return (float) ($this->rule->threshold_amount ?? 0);
    }

    public function thresholdCount(): int
    {
        return (int) ($this->rule->threshold_invoice_count ?? 0);
    }
}
