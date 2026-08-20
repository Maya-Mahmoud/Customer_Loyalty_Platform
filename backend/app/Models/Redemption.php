<?php

namespace App\Models;

use App\Enums\RewardType;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A discount actually handed to a customer (BRD 8.6). Restricted to a branch
 * manager or the owner (BR-013).
 */
class Redemption extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'branch_id',
        'loyalty_rule_id',
        'cycle_number',
        'cycle_total_amount',
        'cycle_invoice_count',
        'reward_type',
        'computed_amount',
        'discount_amount',
        'carried_over_amount',
        'performed_by',
        'is_override',
        'override_reason',
        'override_approved_by',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'cycle_total_amount' => 'decimal:2',
            'cycle_invoice_count' => 'integer',
            'reward_type' => RewardType::class,
            'computed_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'carried_over_amount' => 'decimal:2',
            'is_override' => 'boolean',
            'redeemed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loyaltyRule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function overrideApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_approved_by');
    }

    /**
     * The voucher this redemption issued, when the reward took that shape. A
     * relation rather than a column, because a voucher has its own lifecycle once
     * it leaves the counter.
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }

    /**
     * True when the percentage cap of BRD BR-021 actually bit, which is the
     * question a customer asks when the discount is lower than they expected.
     */
    public function wasCapped(): bool
    {
        return (float) $this->computed_amount > (float) $this->discount_amount;
    }
}
