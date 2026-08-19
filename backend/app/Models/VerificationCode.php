<?php

namespace App\Models;

use App\Enums\VerificationChannel;
use App\Enums\VerificationPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A one-time code sent to an email address or a phone number.
 *
 * Not tenant scoped: registration codes are issued before any merchant exists.
 */
class VerificationCode extends Model
{
    protected $fillable = [
        'purpose',
        'channel',
        'destination',
        'code_hash',
        'verifiable_type',
        'verifiable_id',
        'attempts',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => VerificationPurpose::class,
            'channel' => VerificationChannel::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= (int) config('verification.max_attempts');
    }

    /**
     * A code that may still be checked: unused, unexpired, attempts remaining.
     */
    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && ! $this->isExhausted();
    }

    public function scopeFor(
        Builder $query,
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $destination,
    ): void {
        $query->where('purpose', $purpose)
            ->where('channel', $channel)
            ->where('destination', $destination);
    }

    public function scopeUnconsumed(Builder $query): void
    {
        $query->whereNull('consumed_at');
    }
}
