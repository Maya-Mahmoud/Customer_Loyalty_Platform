<?php

namespace App\Enums;

/**
 * What happens to the surplus above the threshold once a discount is redeemed.
 * Carrying it over is the default (BRD BR-006).
 */
enum ResetPolicy: string
{
    case CarryOver = 'carry_over';
    case FullReset = 'full_reset';
}
