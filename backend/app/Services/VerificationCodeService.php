<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Enums\VerificationChannel;
use App\Enums\VerificationPurpose;
use App\Exceptions\VerificationException;
use App\Mail\VerificationCodeMail;
use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Issues and checks the one-time codes behind BRD FR-MER-02.
 *
 * The code is hashed on the way in, so a leaked row cannot be read back and
 * replayed. Attempts and resends are both capped, which is the control BRD AF-12
 * describes for any code-protected screen.
 */
class VerificationCodeService
{
    public function __construct(private readonly SmsGateway $sms)
    {
    }

    /**
     * Creates a code, invalidates any earlier one for the same destination, and
     * delivers it. The plaintext is returned only so tests and the local log
     * driver can assert on it; nothing in the app may persist it.
     */
    public function issue(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
        ?Model $verifiable = null,
        ?string $field = null,
        ?string $ipAddress = null,
    ): string {
        $field ??= $channel->value;

        $this->guardAgainstFlooding($purpose, $channel, $destination, $field);

        $code = $this->generateCode();

        /*
         * Retiring the old code, storing the new one and delivering it are one
         * unit. If delivery fails, rolling back leaves the previous code working
         * rather than stranding the user with a dead code and a new one that
         * never arrived.
         */
        DB::transaction(function () use ($purpose, $channel, $destination, $verifiable, $ipAddress, $code): void {
            VerificationCode::query()
                ->for($purpose, $channel, $destination)
                ->unconsumed()
                ->update(['consumed_at' => now()]);

            $record = VerificationCode::create([
                'purpose' => $purpose,
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'verifiable_type' => $verifiable !== null ? $verifiable::class : null,
                'verifiable_id' => $verifiable?->getKey(),
                'expires_at' => now()->addMinutes((int) config('verification.ttl_minutes')),
                'ip_address' => $ipAddress,
            ]);

            $this->deliver($record, $code);
        });

        return $code;
    }

    /**
     * Validates a code without spending it, throwing a validation exception when
     * it does not match.
     *
     * Kept separate from consuming it because registration checks two codes at
     * once: burning a correct email code because the phone code had one wrong
     * digit would force the applicant through a full resend.
     */
    public function check(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
        string $code,
        ?string $field = null,
    ): VerificationCode {
        $field ??= $channel->value;

        $record = VerificationCode::query()
            ->for($purpose, $channel, $destination)
            ->unconsumed()
            ->latest('id')
            ->first();

        if ($record === null || ! $record->isUsable()) {
            throw VerificationException::invalidCode($field);
        }

        if (! Hash::check($code, $record->code_hash)) {
            // Counted before throwing, so a guessing run runs out of attempts.
            $record->increment('attempts');

            throw VerificationException::invalidCode($field);
        }

        return $record;
    }

    /**
     * Marks a checked code as spent. Idempotent, so a retry cannot double-consume.
     */
    public function consume(VerificationCode $record): void
    {
        if ($record->isConsumed()) {
            return;
        }

        $record->forceFill(['consumed_at' => now()])->save();
    }

    /**
     * Check and consume in one step, for flows that only have a single code.
     */
    public function confirm(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
        string $code,
        ?string $field = null,
    ): VerificationCode {
        $record = $this->check($purpose, $channel, $destination, $code, $field);

        $this->consume($record);

        return $record;
    }

    /**
     * Whether a destination already proved itself in this flow, without
     * consuming anything. Lets a half-finished registration be told apart from an
     * untouched one.
     */
    public function isConfirmed(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
    ): bool {
        return VerificationCode::query()
            ->for($purpose, $channel, $destination)
            ->whereNotNull('consumed_at')
            ->where('attempts', '<', (int) config('verification.max_attempts'))
            ->exists();
    }

    private function guardAgainstFlooding(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
        string $field,
    ): void {
        $latest = VerificationCode::query()
            ->for($purpose, $channel, $destination)
            ->latest('id')
            ->first();

        $cooldown = (int) config('verification.resend_cooldown_seconds');

        if ($latest !== null) {
            $elapsed = (int) $latest->created_at->diffInSeconds(now(), absolute: true);

            if ($elapsed < $cooldown) {
                throw VerificationException::tooSoon($field, max($cooldown - $elapsed, 1));
            }
        }

        $sentThisHour = VerificationCode::query()
            ->for($purpose, $channel, $destination)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sentThisHour >= (int) config('verification.max_per_hour')) {
            throw VerificationException::hourlyLimitReached($field);
        }
    }

    private function deliver(VerificationCode $record, string $code): void
    {
        $minutes = (int) config('verification.ttl_minutes');

        match ($record->channel) {
            VerificationChannel::Email => Mail::to($record->destination)
                ->send(new VerificationCodeMail($code, $minutes)),
            VerificationChannel::Sms => $this->sms->send(
                $record->destination,
                __('Your verification code is :code. It expires in :minutes minutes.', [
                    'code' => $code,
                    'minutes' => $minutes,
                ]),
            ),
        };
    }

    private function generateCode(): string
    {
        $length = (int) config('verification.code_length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
