<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'merchant_id' => $this->merchant_id,
            'branch_id' => $this->branch_id,
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            // Sent so the Angular side can hide what the role cannot do. The
            // server still enforces every one of them — this is presentation only.
            'permissions' => array_map(
                fn ($permission) => $permission->value,
                $this->role->permissions(),
            ),

            'merchant' => MerchantResource::make($this->whenLoaded('merchant')),
            'branch' => BranchResource::make($this->whenLoaded('branch')),
        ];
    }
}
