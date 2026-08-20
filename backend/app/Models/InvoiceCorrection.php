<?php

namespace App\Models;

use App\Enums\CorrectionStatus;
use App\Enums\CorrectionType;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A request to cancel or return an invoice, pending approval (BRD 8.7).
 */
class InvoiceCorrection extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'invoice_id',
        'type',
        'amount',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'type' => CorrectionType::class,
            'amount' => 'decimal:2',
            'status' => CorrectionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }

    public function isPending(): bool
    {
        return $this->status === CorrectionStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === CorrectionStatus::Approved;
    }

    /**
     * The value the reversing entry has to carry: the requested amount for a
     * partial return, the whole invoice otherwise (BRD FR-INV-07).
     */
    public function reversalAmount(): float
    {
        return $this->type->needsAmount()
            ? (float) $this->amount
            : (float) $this->invoice->amount;
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', CorrectionStatus::Pending);
    }
}
