<?php

namespace App\Enums;

/**
 * The customer never touches the system, so the sales rep records the verbal
 * consent on their behalf (BRD FR-CUS-07, section 16). It must stay withdrawable
 * at any time.
 */
enum ConsentStatus: string
{
    case NotCollected = 'not_collected';
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';

    public function allowsNotifications(): bool
    {
        return $this === self::Granted;
    }
}
