<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of every sensitive operation (BRD FR-SEC-02).
 *
 * It does not use BelongsToMerchant: platform-level actions carry no merchant,
 * and the log must never be filtered away from the platform supervisor who is
 * entitled to read all of it (BRD 7.2). Reads are scoped in the controller
 * instead, where the intent is explicit.
 */
class AuditLog extends Model
{
    /** Rows are immutable, so there is no updated_at to maintain. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(type: 'entity_type', id: 'entity_id');
    }
}
