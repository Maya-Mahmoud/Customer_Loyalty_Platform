<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when code tries to persist a row belonging to a different merchant than
 * the one in scope. It means a bug in the calling code, not bad user input, so it
 * fails loudly instead of silently writing across the tenant boundary.
 */
class CrossTenantWriteException extends RuntimeException
{
    public static function for(string $model, ?int $attempted, ?int $current): self
    {
        return new self(sprintf(
            'Refused to write %s for merchant %s while merchant %s is in scope.',
            $model,
            $attempted ?? 'null',
            $current ?? 'null',
        ));
    }
}
