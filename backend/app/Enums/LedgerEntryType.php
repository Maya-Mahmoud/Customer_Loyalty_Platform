<?php

namespace App\Enums;

/**
 * Every movement on a customer balance is one of these entries; the balance
 * itself is never stored as an updatable number (BRD 13.3, BR-008).
 */
enum LedgerEntryType: string
{
    /** A qualifying invoice adds to the current cycle. */
    case Accrual = 'accrual';

    /** A cancellation or return, always negative (BRD BR-009). */
    case Reversal = 'reversal';

    /** Closes the cycle when a discount is redeemed (BRD BR-005). */
    case CycleClose = 'cycle_close';

    /** Opens the next cycle with the surplus (BRD BR-006). */
    case CarryOver = 'carry_over';

    /** Owner-approved manual correction, always with a reason (BRD BR-014). */
    case ManualAdjustment = 'manual_adjustment';

    /** Balance written off after the inactivity window (BRD BR-017). */
    case Expiry = 'expiry';
}
