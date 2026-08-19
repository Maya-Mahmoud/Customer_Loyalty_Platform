<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;

/**
 * Discards every message. Used in tests, and as a safe default if the SMS budget
 * cap of BRD FR-NOT-06 is ever reached.
 */
class NullSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        return true;
    }
}
