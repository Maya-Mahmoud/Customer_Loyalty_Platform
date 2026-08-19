<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'max_branches',
        'max_users',
        'max_monthly_invoices',
        'monthly_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_branches' => 'integer',
            'max_users' => 'integer',
            'max_monthly_invoices' => 'integer',
            'monthly_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    /**
     * A null cap means unlimited, which is why this is a method and not a raw
     * comparison at the call site.
     */
    public function allows(?int $cap, int $current): bool
    {
        return $cap === null || $current < $cap;
    }
}
