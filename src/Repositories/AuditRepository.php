<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class AuditRepository extends BaseRepository
{
    public function log(?string $userId, string $action, ?string $target = null, array $metadata = []): void
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO audit_logs (id, user_id, action, target, metadata) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Uuid::v4(),
            $userId,
            $action,
            $target,
            $this->encodeJson($metadata),
        ]);
    }
}
