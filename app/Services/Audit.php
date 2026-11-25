<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;

final class Audit
{
    /** $metadata must not contain secrets or tokens. */
    public static function log(?int $actorId, string $action, string $entity, ?int $entityId, array $metadata = []): void
    {
        Database::insert('audit_logs', [
            'actor_user_id' => $actorId,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'safe_metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => Clock::now(),
        ]);
    }
}
