<?php

namespace App\Models;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant root (BRD 13.1). It does not use BelongsToMerchant — it *is* the
 * merchant, and the platform supervisor needs to list every one of them.
 */
class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'trade_name',
        'commercial_register',
        'owner_name',
        'email',
        'phone',
        'city',
        'currency',
        'logo_path',
        'status',
        'status_reason',
        'status_changed_at',
        'activated_at',
        'email_verified_at',
        'phone_verified_at',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'subscription_plan_id',
        'subscription_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MerchantStatus::class,
            'status_changed_at' => 'datetime',
            'activated_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'subscription_ends_at' => 'date',
        ];
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /** The supervisor who approved or declined the registration (BRD FR-ADM-02). */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The single owner account created with the registration (BRD 8.1). */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::MerchantOwner);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function loyaltyRules(): HasMany
    {
        return $this->hasMany(LoyaltyRule::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * The rule that governs sales made on the given day. Rules are versioned and
     * never applied retroactively (BRD BR-015), so the date decides the version.
     */
    public function ruleEffectiveOn(?string $date = null): ?LoyaltyRule
    {
        $date ??= now()->toDateString();

        /*
         * whereDate, not where. A `date` column is written through the datetime
         * format, so the stored value is "2026-08-20 00:00:00" while the bound
         * parameter is "2026-08-20". Compared as strings the timestamp sorts after
         * the bare date, so `effective_from <= today` excludes a rule starting
         * today — and with no rule found, nothing would ever accumulate.
         */
        return $this->loyaltyRules()
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    public function isActive(): bool
    {
        return $this->status->allowsAccess();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', MerchantStatus::Active);
    }
}
