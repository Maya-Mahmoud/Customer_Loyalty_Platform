<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Development gateway: writes the message to the log instead of sending it, so
 * the whole verification flow is testable before a provider is contracted.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        Log::channel(config('sms.log_channel'))->info('SMS', [
            'to' => $phone,
            'message' => $message,
        ]);

        return true;
    }
}
