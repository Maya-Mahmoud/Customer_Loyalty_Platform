<?php

namespace App\Enums;

/**
 * A user starts as Invited and only becomes Active once they set their own
 * password (BRD FR-BRN-04). Disabling keeps history intact (BRD FR-BRN-05).
 */
enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';

    public function allowsAccess(): bool
    {
        return $this === self::Active;
    }
}
