<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'user_id',
        'customer_id',
        'invoice_number',
        'amount',
        'invoice_date',
        'status',
        'qualifies_for_accumulation',
        'cancelled_at',
    ];

    /**
     * Mirrors the column defaults so a newly created instance carries them in
     * memory too. Otherwise the caller reads null back for a field the database has
     * already filled — and the resource serialising it crashes on the enum cast.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => InvoiceStatus::Active->value,
        'qualifies_for_accumulation' => true,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'invoice_date' => 'date',
            'status' => InvoiceStatus::class,
            'qualifies_for_accumulation' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The user who keyed the invoice in (BRD FR-INV-03).
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(InvoiceCorrection::class);
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /**
     * An invoice with no customer is a plain sale: recorded for the reports, but
     * outside every accumulation (BRD BR-022).
     */
    public function isLinkedToCustomer(): bool
    {
        return $this->customer_id !== null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Active);
    }

    public function scopeAccumulating(Builder $query): void
    {
        $query->active()
            ->whereNotNull('customer_id')
            ->where('qualifies_for_accumulation', true);
    }
}
