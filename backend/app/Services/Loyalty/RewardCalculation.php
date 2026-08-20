<?php

namespace App\Services\Loyalty;

use App\Enums\ResetPolicy;
use App\Enums\RewardType;

/**
 * The arithmetic of one redemption, kept as a value object so every figure that
 * went into it can be stored and shown back later.
 *
 * BRD 11.2 asks a customer's question directly: why 50 and not 116? Keeping the
 * uncapped figure alongside the paid one answers it from the record instead of a
 * recalculation that may since have changed.
 */
final readonly class RewardCalculation
{
    public function __construct(
        public RewardType $rewardType,
        /** What the rule produces before any ceiling is applied. */
        public float $computedAmount,
        /** What the customer actually receives (BRD BR-021). */
        public float $discountAmount,
        /** Surplus above the threshold moved into the next cycle (BRD BR-006). */
        public float $carriedOverAmount,
        /** Invoice count moved into the next cycle, under the same policy. */
        public int $carriedOverInvoices,
    ) {
    }

    /**
     * True when the absolute cap of BRD BR-021 actually bit — the one thing a
     * customer notices and asks about.
     */
    public function wasCapped(): bool
    {
        return $this->computedAmount > $this->discountAmount;
    }
}
