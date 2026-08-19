<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Merchant;
use App\Models\Scopes\MerchantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that belongs to a merchant. It does three things so no
 * feature has to remember them:
 *
 *  1. reads are filtered to the merchant in scope;
 *  2. writes get merchant_id filled in automatically;
 *  3. a write aimed at a different merchant throws instead of succeeding.
 *
 * Point 3 is the one that turns BRD FR-ADM-06 from a convention into a
 * guarantee, and it is what the cross-tenant acceptance test in BRD 20 exercises.
 */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope(new MerchantScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('merchant_id') === null) {
                $model->setAttribute('merchant_id', app(TenantContext::class)->id());
            }
        });

        static::saving(function (Model $model): void {
            $tenant = app(TenantContext::class);
            $merchantId = $model->getAttribute('merchant_id');

            if (! $tenant->isActive() || $merchantId === null) {
                return;
            }

            if ((int) $merchantId !== $tenant->id()) {
                throw CrossTenantWriteException::for($model::class, (int) $merchantId, $tenant->id());
            }
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
