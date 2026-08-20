<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of a merchant's rule, with the trail BRD FR-LOY-08 asks for.
 *
 * @property-read \App\Models\LoyaltyRule $resource
 */
class LoyaltyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,

            'threshold_type' => $this->threshold_type->value,
            'threshold_amount' => $this->threshold_amount,
            'threshold_invoice_count' => $this->threshold_invoice_count,

            'reward_type' => $this->reward_type->value,
            'reward_value' => $this->reward_value,
            'max_discount_amount' => $this->max_discount_amount,
            'min_invoice_amount' => $this->min_invoice_amount,

            'accumulation_scope' => $this->accumulation_scope->value,
            'reset_policy' => $this->reset_policy->value,
            'balance_validity_months' => $this->balance_validity_months,

            'effective_from' => $this->effective_from?->toDateString(),
            // Null on the version in force; set on every superseded one.
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
