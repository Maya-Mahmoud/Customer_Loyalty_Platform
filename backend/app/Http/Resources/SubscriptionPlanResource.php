<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\SubscriptionPlan $resource
 */
class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            // Null means unlimited (BRD FR-ADM-04).
            'max_branches' => $this->max_branches,
            'max_users' => $this->max_users,
            'max_monthly_invoices' => $this->max_monthly_invoices,
            'monthly_price' => $this->monthly_price,
            'is_active' => $this->is_active,
        ];
    }
}
