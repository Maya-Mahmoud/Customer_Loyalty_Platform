<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One paid reward (BRD FR-RED-04, FR-RED-07).
 *
 * Both the computed and the paid figure are returned. BRD 11.2 poses the customer
 * question directly — why 50 and not 116 — and keeping the uncapped value alongside
 * answers it from the record rather than from a recalculation.
 *
 * @property-read \App\Models\Redemption $resource
 */
class RedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_number' => $this->cycle_number,
            'cycle_total_amount' => $this->cycle_total_amount,
            'cycle_invoice_count' => $this->cycle_invoice_count,

            'reward_type' => $this->reward_type->value,
            'computed_amount' => $this->computed_amount,
            'discount_amount' => $this->discount_amount,
            'was_capped' => $this->wasCapped(),
            'carried_over_amount' => $this->carried_over_amount,

            // BRD BR-014: an exception is visible, with its reason, wherever the
            // redemption is shown.
            'is_override' => $this->is_override,
            'override_reason' => $this->override_reason,

            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'performed_by' => $this->whenLoaded('performedBy', fn () => $this->performedBy?->name),

            'voucher' => $this->whenLoaded('vouchers', fn () => $this->vouchers->first() === null ? null : [
                'code' => $this->vouchers->first()->code,
                'amount' => $this->vouchers->first()->amount,
                'expires_at' => $this->vouchers->first()->expires_at?->toDateString(),
            ]),
        ];
    }
}
