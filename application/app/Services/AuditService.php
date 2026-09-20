<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use DomainException;

class AuditService
{
    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function record(
        string $entityType,
        ?int $entityId,
        string $action,
        array $metadata = [],
        ?User $actor = null,
        string $actorType = 'user',
    ): AuditLog {
        if (! in_array($actorType, ['user', 'system'], true)) {
            throw new DomainException('Audit actor type must be user or system.');
        }

        if ($actorType === 'user' && $actor === null) {
            throw new DomainException('A user actor is required for a user audit entry.');
        }

        return AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actorType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
