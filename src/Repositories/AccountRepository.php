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

        return $this->withSyncActivity($userId, array_map([$this, 'map'], $rows));
    }

    public function findForUser(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM trading_accounts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->withSyncActivity($userId, [$this->map($row)])[0];
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

    /**
     * Mark live accounts as disconnected when last_seen_at is older than $staleSeconds.
     *
     * @return list<array<string, mixed>> Newly stale accounts
     */
    public function markStaleConnections(string $userId, int $staleSeconds = 120): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM trading_accounts
             WHERE user_id = ?
               AND connection_status = \'live\'
               AND last_seen_at IS NOT NULL
               AND last_seen_at < (NOW(3) - INTERVAL ? SECOND)'
        );
        $stmt->execute([$userId, $staleSeconds]);
        $rows = array_map([$this, 'map'], $stmt->fetchAll());
        if ($rows === []) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], $ids);
        $this->db()->prepare(
            "UPDATE trading_accounts SET connection_status = 'disconnected'
             WHERE user_id = ? AND id IN ($placeholders)"
        )->execute($params);

        return $rows;
    }

    /**
     * Connector heartbeats are not the only sync. Journal and history writes
     * update book rows without setting last_seen_at, which left the desk on
     * "awaiting data" after trades already existed.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withSyncActivity(string $userId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $stmt = $this->db()->prepare(
            'SELECT account_id, MAX(seen_at) AS seen_at FROM (
                SELECT account_id, MAX(updated_at) AS seen_at
                  FROM trades WHERE user_id = ? GROUP BY account_id
                UNION ALL
                SELECT account_id, MAX(updated_at) AS seen_at
                  FROM positions WHERE user_id = ? GROUP BY account_id
                UNION ALL
                SELECT account_id, MAX(updated_at) AS seen_at
                  FROM pending_orders WHERE user_id = ? GROUP BY account_id
                UNION ALL
                SELECT account_id, MAX(last_used_at) AS seen_at
                  FROM connector_tokens
                 WHERE user_id = ? AND account_id IS NOT NULL AND last_used_at IS NOT NULL
                 GROUP BY account_id
            ) activity
            GROUP BY account_id'
        );
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $byAccount = [];
        foreach ($stmt->fetchAll() as $activity) {
            if (!isset($activity['account_id'], $activity['seen_at'])) {
                continue;
            }
            $byAccount[(string) $activity['account_id']] = (string) $activity['seen_at'];
        }

        foreach ($rows as &$row) {
            $id = (string) $row['id'];
            $heartbeat = isset($row['last_seen_at']) && $row['last_seen_at'] !== ''
                ? (string) $row['last_seen_at']
                : null;
            $row['last_seen_at'] = $this->laterTimestamp($heartbeat, $byAccount[$id] ?? null);
        }
        unset($row);

        return $rows;
    }

    private function laterTimestamp(?string $left, ?string $right): ?string
    {
        if ($left === null || $left === '') {
            return ($right === null || $right === '') ? null : $right;
        }
        if ($right === null || $right === '') {
            return $left;
        }

        return strcmp($left, $right) >= 0 ? $left : $right;
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
