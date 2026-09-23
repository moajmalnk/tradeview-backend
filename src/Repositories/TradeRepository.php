<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class TradeRepository extends BaseRepository
{
    public function list(string $userId, ?string $accountId, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM trades WHERE user_id = ?';
        $params = [$userId];
        if ($accountId) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY COALESCE(close_time, open_time) DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    public function find(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM trades WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row ? $this->map($row) : null;
    }

    public function create(string $userId, array $data): array
    {
        $id = Uuid::v4();
        $stmt = $this->db()->prepare(
            'INSERT INTO trades (
                id, user_id, account_id, ticket, symbol, direction, volume, entry_price, exit_price,
                profit, commission, swap, open_time, close_time, status, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $data['account_id'],
            $data['ticket'],
            $data['symbol'],
            $data['direction'],
            $data['volume'],
            $data['entry_price'],
            $data['exit_price'] ?? null,
            $data['profit'] ?? 0,
            $data['commission'] ?? 0,
            $data['swap'] ?? 0,
            $data['open_time'],
            $data['close_time'] ?? null,
            $data['status'] ?? 'closed',
            $this->encodeJson($data['metadata'] ?? []),
        ]);

        return $this->find($userId, $id);
    }

    public function update(string $userId, string $id, array $fields): ?array
    {
        if (!$this->find($userId, $id)) {
            return null;
        }
        $allowed = [
            'symbol', 'direction', 'volume', 'entry_price', 'exit_price',
            'profit', 'commission', 'swap', 'open_time', 'close_time', 'status', 'metadata',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = ?";
            $params[] = $key === 'metadata' ? $this->encodeJson($fields[$key]) : $fields[$key];
        }
        if ($sets === []) {
            return $this->find($userId, $id);
        }
        $params[] = $id;
        $params[] = $userId;
        $this->db()->prepare(
            'UPDATE trades SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        )->execute($params);

        return $this->find($userId, $id);
    }

    public function delete(string $userId, string $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM trades WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function upsert(string $userId, string $accountId, array $data): void
    {
        $existing = $this->db()->prepare(
            'SELECT id FROM trades WHERE account_id = ? AND ticket = ? LIMIT 1'
        );
        $existing->execute([$accountId, $data['ticket']]);
        $row = $existing->fetch();

        if ($row) {
            $this->update($userId, $row['id'], $data);
        } else {
            $data['account_id'] = $accountId;
            $this->create($userId, $data);
        }
    }

    private function map(array $row): array
    {
        $row = $this->mapJsonFields($row, ['metadata']);
        foreach (['volume', 'entry_price', 'exit_price', 'profit', 'commission', 'swap'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }
}
