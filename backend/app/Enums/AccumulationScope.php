<?php

namespace App\Enums;

/**
 * Whether a customer's progress is pooled across the whole merchant or kept per
 * branch. Merchant-wide is the default (BRD BR-016).
 */
enum AccumulationScope: string
{
    case Merchant = 'merchant';
    case Branch = 'branch';
}
