<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer record, always created by a sales rep (BRD BR-001). The customer
 * never authenticates and never owns a password.
 */
class Customer extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'phone',
        'name',
        'registered_by_user_id',
        'registered_at_branch_id',
        'consent_status',
        'consent_recorded_at',
        'phone_verified_at',
        'current_cycle_number',
        'last_purchase_at',
        'is_active',
        'anonymized_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_status' => ConsentStatus::class,
            'consent_recorded_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'current_cycle_number' => 'integer',
            'last_purchase_at' => 'datetime',
            'is_active' => 'boolean',
            'anonymized_at' => 'datetime',
        ];
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function registeredAtBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'registered_at_branch_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Entries of the cycle that is currently open. The balance is always derived
     * from these rather than stored (BRD 13.3).
     */
    public function currentCycleEntries(): HasMany
    {
        return $this->ledgerEntries()->where('cycle_number', $this->current_cycle_number);
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function scopeWithPhone(Builder $query, string $phone): void
    {
        $query->where('phone', $phone);
    }
}
