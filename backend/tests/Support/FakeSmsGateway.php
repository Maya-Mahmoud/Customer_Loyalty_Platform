<?php

namespace Tests\Support;

use App\Contracts\SmsGateway;

/**
 * Records messages instead of sending them, so a test can read the one-time code
 * the same way a real recipient would.
 */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $messages = [];

    public function send(string $phone, string $message): bool
    {
        $this->messages[] = ['phone' => $phone, 'message' => $message];

        return true;
    }

    public function lastCode(?string $phone = null): ?string
    {
        $candidates = $phone === null
            ? $this->messages
            : array_values(array_filter($this->messages, fn (array $m) => $m['phone'] === $phone));

        $last = end($candidates);

        if ($last === false) {
            return null;
        }

        preg_match('/(\d{'.config('verification.code_length').'})/', $last['message'], $matches);

        return $matches[1] ?? null;
    }

    public function countFor(string $phone): int
    {
        return count(array_filter($this->messages, fn (array $m) => $m['phone'] === $phone));
    }
}
