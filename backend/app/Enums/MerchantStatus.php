<?php

namespace App\Enums;

/**
 * Lifecycle of a merchant account as described in BRD 8.1.
 */
enum MerchantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * Only an active merchant may sign in and record business data
     * (BRD FR-ADM-03).
     */
    public function allowsAccess(): bool
    {
        return $this === self::Active;
    }
}
