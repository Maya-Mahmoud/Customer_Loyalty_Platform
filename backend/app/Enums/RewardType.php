<?php

namespace App\Enums;

/**
 * How the earned reward is expressed (BRD FR-LOY-02).
 */
enum RewardType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case Voucher = 'voucher';

    /**
     * Only a percentage reward needs the absolute cap of BRD BR-021.
     */
    public function needsCap(): bool
    {
        return $this === self::Percentage;
    }
}
