<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class AccountRepository extends BaseRepository
{
    public function listForUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM trading_accounts WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'map'], $rows);
    }

    public function findForUser(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM trading_accounts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row ? $this->map($row) : null;
    }

    public function create(string $userId, array $data): array
    {
        $id = Uuid::v4();
        $stmt = $this->db()->prepare(
            'INSERT INTO trading_accounts (
                id, user_id, label, platform, broker, account_identifier, currency,
                balance, equity, margin, free_margin, margin_level, connection_status, is_demo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $data['label'],
            $data['platform'],
            $data['broker'] ?? null,
            $data['account_identifier'] ?? null,
            $data['currency'] ?? 'USD',
            $data['balance'] ?? 0,
            $data['equity'] ?? 0,
            $data['margin'] ?? 0,
            $data['free_margin'] ?? 0,
            $data['margin_level'] ?? null,
            $data['connection_status'] ?? 'pending',
            $this->boolInt($data['is_demo'] ?? false),
        ]);

        return $this->findForUser($userId, $id);
    }

    public function update(string $userId, string $id, array $fields): ?array
    {
        if (!$this->findForUser($userId, $id)) {
            return null;
        }

        $allowed = [
            'label', 'platform', 'broker', 'account_identifier', 'currency',
            'balance', 'equity', 'margin', 'free_margin', 'margin_level',
            'server_time', 'connection_status', 'last_seen_at', 'is_demo',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = ?";
            $params[] = $key === 'is_demo' ? $this->boolInt($fields[$key]) : $fields[$key];
        }
        if ($sets === []) {
            return $this->findForUser($userId, $id);
        }
        $params[] = $id;
        $params[] = $userId;
        $this->db()->prepare(
            'UPDATE trading_accounts SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        )->execute($params);

        return $this->findForUser($userId, $id);
    }

    public function delete(string $userId, string $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM trading_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    private function map(array $row): array
    {
        $row['is_demo'] = (bool) $row['is_demo'];
        foreach (['balance', 'equity', 'margin', 'free_margin', 'margin_level'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }
}
