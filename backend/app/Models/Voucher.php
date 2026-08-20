<?php

namespace App\Models;

use App\Enums\VoucherStatus;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spending credit issued as a reward and redeemed on a later visit.
 *
 * The state a screen should show is not the status column alone: an issued voucher
 * past its expiry is expired, and storing that would need a job that can fail.
 * `state()` derives it instead, so the answer cannot go stale.
 */
class Voucher extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'redemption_id',
        'code',
        'amount',
        'status',
        'issued_at',
        'expires_at',
        'used_at',
        'used_on_invoice_id',
        'used_at_branch_id',
        'accepted_by',
        'cancellation_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => VoucherStatus::Issued->value,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => VoucherStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function usedOnInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'used_on_invoice_id');
    }

    public function usedAtBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'used_at_branch_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether it can be accepted right now.
     */
    public function isUsable(): bool
    {
        return $this->status === VoucherStatus::Issued && ! $this->isExpired();
    }

    /**
     * What to show the user: the stored status, unless it is an issued voucher
     * whose date has passed.
     */
    public function state(): string
    {
        if ($this->status === VoucherStatus::Issued && $this->isExpired()) {
            return 'expired';
        }

        return $this->status->value;
    }

    /**
     * Vouchers still worth something to the customer — what the customer card and
     * the self-service lookup should list.
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', VoucherStatus::Issued)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }
}
