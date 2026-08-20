<?php

namespace App\Enums;

/**
 * Lifecycle of a purchase voucher.
 *
 * Expiry is deliberately absent. It follows from expires_at, so deriving it cannot
 * drift the way a stored flag does when a scheduled job fails to run — and no job
 * is needed at all.
 */
enum VoucherStatus: string
{
    case Issued = 'issued';
    case Used = 'used';
    /** Withdrawn by the owner, e.g. after the invoice that earned it was cancelled. */
    case Cancelled = 'cancelled';
}
