<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;
use App\Services\TokenHasher;

final class ShareLinkRepository extends BaseRepository
{
    public function list(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, label, token_prefix, visibility, permissions, account_ids, status,
                    view_count, last_viewed_at, expires_at, revoked_at, created_at, updated_at
             FROM share_links WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    /** @return array{row: array, plain: string} */
    public function create(string $userId, array $data): array
    {
        $generated = TokenHasher::generateShareToken();
        $id = Uuid::v4();
        $permissions = $data['permissions'] ?? [
            'balance' => true,
            'equity' => true,
            'pnl' => true,
            'tradeHistory' => true,
            'accountName' => true,
            'broker' => true,
            'volume' => true,
            'entry' => true,
            'stopLoss' => true,
            'takeProfit' => true,
            'performance' => true,
        ];
        $stmt = $this->db()->prepare(
            'INSERT INTO share_links (
                id, user_id, label, token_hash, token_prefix, visibility, permissions, account_ids, status, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $data['label'] ?? 'Live monitoring',
            TokenHasher::sha256($generated['token']),
            $generated['prefix'],
            $data['visibility'] ?? 'full',
            $this->encodeJson($permissions),
            $this->encodeJson($data['account_ids'] ?? []),
            $data['expires_at'] ?? null,
        ]);

        return ['row' => $this->findById($userId, $id), 'plain' => $generated['token']];
    }

    public function revoke(string $userId, string $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE share_links SET status = \'revoked\', revoked_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND user_id = ? AND status = \'active\''
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function findActiveByPlainToken(string $plain): ?array
    {
        $hash = TokenHasher::sha256($plain);
        $stmt = $this->db()->prepare(
            'SELECT * FROM share_links
             WHERE token_hash = ? AND status = \'active\'
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3))
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return $row ? $this->map($row) : null;
    }

    public function incrementView(string $id): void
    {
        $this->db()->prepare(
            'UPDATE share_links SET view_count = view_count + 1, last_viewed_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute([$id]);
    }

    private function findById(string $userId, string $id): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, label, token_prefix, visibility, permissions, account_ids, status,
                    view_count, last_viewed_at, expires_at, revoked_at, created_at, updated_at
             FROM share_links WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);

        return $this->map($stmt->fetch());
    }

    private function map(array $row): array
    {
        return $this->mapJsonFields($row, ['permissions', 'account_ids']);
    }
}
