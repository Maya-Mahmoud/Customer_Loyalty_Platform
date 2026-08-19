<?php

namespace App\Enums;

/**
 * What the customer has to reach to earn a reward (BRD FR-LOY-01).
 */
enum ThresholdType: string
{
    case Amount = 'amount';
    case InvoiceCount = 'invoice_count';
    case Both = 'both';

    public function tracksAmount(): bool
    {
        return $this === self::Amount || $this === self::Both;
    }

    public function tracksInvoiceCount(): bool
    {
        return $this === self::InvoiceCount || $this === self::Both;
    }
}
