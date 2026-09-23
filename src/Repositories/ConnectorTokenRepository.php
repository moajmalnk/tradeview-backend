<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;
use App\Services\TokenHasher;

final class ConnectorTokenRepository extends BaseRepository
{
    public function list(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, account_id, token_prefix, platform, status, created_at, expires_at, last_used_at, revoked_at
             FROM connector_tokens WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return array{row: array, plain: string} */
    public function create(string $userId, string $platform, ?string $accountId = null): array
    {
        $generated = TokenHasher::generateConnectorToken();
        $id = Uuid::v4();
        $stmt = $this->db()->prepare(
            'INSERT INTO connector_tokens (id, user_id, account_id, token_hash, token_prefix, platform, status)
             VALUES (?, ?, ?, ?, ?, ?, \'active\')'
        );
        $stmt->execute([
            $id,
            $userId,
            $accountId,
            TokenHasher::sha256($generated['token']),
            $generated['prefix'],
            $platform,
        ]);

        $row = $this->findById($userId, $id);

        return ['row' => $row, 'plain' => $generated['token']];
    }

    public function revoke(string $userId, string $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE connector_tokens SET status = \'revoked\', revoked_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND user_id = ? AND status = \'active\''
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function findActiveByPlainToken(string $plain): ?array
    {
        $hash = TokenHasher::sha256($plain);
        $stmt = $this->db()->prepare(
            'SELECT * FROM connector_tokens
             WHERE token_hash = ? AND status = \'active\'
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3))
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function touch(string $id): void
    {
        $this->db()->prepare(
            'UPDATE connector_tokens SET last_used_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute([$id]);
    }

    public function bindAccount(string $id, string $accountId): void
    {
        $this->db()->prepare(
            'UPDATE connector_tokens SET account_id = ? WHERE id = ?'
        )->execute([$accountId, $id]);
    }

    private function findById(string $userId, string $id): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, account_id, token_prefix, platform, status, created_at, expires_at, last_used_at, revoked_at
             FROM connector_tokens WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->fetch();
    }
}
