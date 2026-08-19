<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement on a customer balance (BRD 13.3).
 *
 * Entries are append-only: a mistake is corrected by adding a reversing entry,
 * never by editing or deleting a row. Everything the customer card and the
 * eligibility check show is summed from here.
 */
class LedgerEntry extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'branch_id',
        'cycle_number',
        'type',
        'amount',
        'invoice_count_delta',
        'source_type',
        'source_id',
        'loyalty_rule_id',
        'created_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'type' => LedgerEntryType::class,
            'amount' => 'decimal:2',
            'invoice_count_delta' => 'integer',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The invoice, redemption or correction that produced the entry.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForCycle(Builder $query, int $cycleNumber): void
    {
        $query->where('cycle_number', $cycleNumber);
    }

    /**
     * Restricts the sum to one branch, for merchants that keep accumulation per
     * branch instead of merchant-wide (BRD FR-LOY-05).
     */
    public function scopeForBranch(Builder $query, ?int $branchId): void
    {
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
    }
}
