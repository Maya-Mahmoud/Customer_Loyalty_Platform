<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Adds the merchant filter to every query on a tenant-owned model.
 *
 * The column is qualified with the table name because these models are joined
 * often, and an unqualified merchant_id would be ambiguous.
 */
class MerchantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->isActive()) {
            return;
        }

        $builder->where($model->qualifyColumn('merchant_id'), $tenant->id());
    }
}
