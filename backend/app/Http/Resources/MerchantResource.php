<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @property-read \App\Models\Merchant $resource
 */
class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'city' => $this->city,
            'currency' => $this->currency,
            'logo_path' => $this->logo_path,
            // A URL rather than the stored path: the client should not have to know
            // which disk it lives on or how to build a link to it.
            'logo_url' => $this->logo_path === null
                ? null
                : Storage::disk('public')->url($this->logo_path),
            'status' => $this->status->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'subscription_ends_at' => $this->subscription_ends_at?->toDateString(),
        ];
    }
}
