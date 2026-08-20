<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Branch $resource
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            // Shown on the branch list, and it is what makes the refusal to switch
            // off a branch with staff still on it understandable.
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
