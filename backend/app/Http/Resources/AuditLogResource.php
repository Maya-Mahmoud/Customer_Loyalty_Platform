<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the audit trail (BRD FR-SEC-02).
 *
 * The entity type is sent as a short name rather than the class path: "Redemption"
 * tells the reader what was acted on, while
 * "App\Models\Redemption" tells them how this application is organised, which is
 * neither their business nor useful to them.
 *
 * before and after are passed through as they were recorded. The screen renders them
 * generically, because a trail that only displays the fields somebody remembered to
 * template would quietly hide the rest.
 *
 * @property-read \App\Models\AuditLog $resource
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entity_type === null
                ? null
                : class_basename($this->entity_type),
            'entity_id' => $this->entity_id,
            'before' => $this->before,
            'after' => $this->after,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),

            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role->value,
            ]),

            // Only meaningful to the platform supervisor, who sees more than one.
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant?->name),
        ];
    }
}
