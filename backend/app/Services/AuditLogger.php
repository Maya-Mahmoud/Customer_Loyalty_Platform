<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the audit trail required by BRD FR-SEC-02 and AF-09.
 *
 * Every sensitive operation goes through here rather than each feature building
 * its own log row, so the recorded shape — who, what, when, before, after — stays
 * consistent enough to be reportable.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'merchant_id' => $actor?->merchant_id,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entity !== null ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Records a change on a model by diffing it, so callers do not have to
     * assemble before/after arrays by hand.
     *
     * @param  array<string, mixed>  $original
     */
    public function recordChange(string $action, Model $entity, array $original): AuditLog
    {
        $changed = array_keys($entity->getChanges());

        return $this->record(
            action: $action,
            entity: $entity,
            before: array_intersect_key($original, array_flip($changed)),
            after: $entity->getChanges(),
        );
    }
}
