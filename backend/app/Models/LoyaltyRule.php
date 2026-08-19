<?php

namespace App\Models;

use App\Enums\AccumulationScope;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a merchant's loyalty rule (BRD 11). Rows are never edited in
 * place; a change closes the current version and inserts the next one, which is
 * what keeps BR-015 (no retroactive effect) true by construction.
 */
class LoyaltyRule extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'version',
        'threshold_type',
        'threshold_amount',
        'threshold_invoice_count',
        'reward_type',
        'reward_value',
        'max_discount_amount',
        'min_invoice_amount',
        'accumulation_scope',
        'reset_policy',
        'balance_validity_months',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'threshold_type' => ThresholdType::class,
            'threshold_amount' => 'decimal:2',
            'threshold_invoice_count' => 'integer',
            'reward_type' => RewardType::class,
            'reward_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_invoice_amount' => 'decimal:2',
            'accumulation_scope' => AccumulationScope::class,
            'reset_policy' => ResetPolicy::class,
            'balance_validity_months' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * BRD BR-003: an invoice under the minimum is stored but never accumulated.
     */
    public function qualifies(float $invoiceAmount): bool
    {
        return $invoiceAmount >= (float) $this->min_invoice_amount;
    }

    /**
     * The defaults of BRD 11.1, used when a merchant is set up before the owner
     * has configured anything.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'version' => 1,
            'threshold_type' => ThresholdType::Amount,
            'threshold_amount' => 1000,
            'threshold_invoice_count' => null,
            'reward_type' => RewardType::Percentage,
            'reward_value' => 10,
            'max_discount_amount' => 50,
            'min_invoice_amount' => 10,
            'accumulation_scope' => AccumulationScope::Merchant,
            'reset_policy' => ResetPolicy::CarryOver,
            'balance_validity_months' => 12,
        ];
    }
}
