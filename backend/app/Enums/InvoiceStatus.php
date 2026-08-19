<?php

namespace App\Enums;

/**
 * Invoices are never deleted, only marked cancelled (BRD BR-010).
 */
enum InvoiceStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
