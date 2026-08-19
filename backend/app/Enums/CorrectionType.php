<?php

namespace App\Enums;

/**
 * What a sales rep is asking to do to an already-saved invoice (BRD 8.7).
 */
enum CorrectionType: string
{
    case Cancel = 'cancel';
    case FullReturn = 'full_return';
    case PartialReturn = 'partial_return';

    /**
     * A partial return carries its own amount; the others reverse the invoice
     * in full (BRD FR-INV-07).
     */
    public function needsAmount(): bool
    {
        return $this === self::PartialReturn;
    }
}
