<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class PositionRepository extends BaseRepository
{
    public function listOpen(string $userId, ?string $accountId = null): array
    {
        $sql = 'SELECT * FROM positions WHERE user_id = ? AND status = \'open\'';
        $params = [$userId];
        if ($accountId) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY open_time DESC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    public function upsert(string $userId, string $accountId, array $data): void
    {
        $existing = $this->db()->prepare(
            'SELECT id FROM positions WHERE account_id = ? AND ticket = ? LIMIT 1'
        );
        $existing->execute([$accountId, $data['ticket']]);
        $row = $existing->fetch();

        if ($row) {
            $stmt = $this->db()->prepare(
                'UPDATE positions SET
                    symbol = ?, direction = ?, volume = ?, entry_price = ?, current_price = ?,
                    stop_loss = ?, take_profit = ?, profit = ?, swap = ?, commission = ?,
                    open_time = ?, status = ?, metadata = ?
                 WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([
                $data['symbol'],
                $data['direction'],
                $data['volume'],
                $data['entry_price'],
                $data['current_price'] ?? null,
                $data['stop_loss'] ?? null,
                $data['take_profit'] ?? null,
                $data['profit'] ?? 0,
                $data['swap'] ?? 0,
                $data['commission'] ?? 0,
                $data['open_time'] ?? date('Y-m-d H:i:s.v'),
                $data['status'] ?? 'open',
                $this->encodeJson($data['metadata'] ?? []),
                $row['id'],
                $userId,
            ]);
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO positions (
                    id, user_id, account_id, ticket, symbol, direction, volume, entry_price,
                    current_price, stop_loss, take_profit, profit, swap, commission, open_time, status, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                Uuid::v4(),
                $userId,
                $accountId,
                $data['ticket'],
                $data['symbol'],
                $data['direction'],
                $data['volume'],
                $data['entry_price'],
                $data['current_price'] ?? null,
                $data['stop_loss'] ?? null,
                $data['take_profit'] ?? null,
                $data['profit'] ?? 0,
                $data['swap'] ?? 0,
                $data['commission'] ?? 0,
                $data['open_time'] ?? date('Y-m-d H:i:s.v'),
                $data['status'] ?? 'open',
                $this->encodeJson($data['metadata'] ?? []),
            ]);
        }
    }

    public function closeMissing(string $userId, string $accountId, array $activeTickets): void
    {
        if ($activeTickets === []) {
            $stmt = $this->db()->prepare(
                'UPDATE positions SET status = \'closed\' WHERE user_id = ? AND account_id = ? AND status = \'open\''
            );
            $stmt->execute([$userId, $accountId]);

            return;
        }

        $placeholders = implode(',', array_fill(0, count($activeTickets), '?'));
        $params = array_merge([$userId, $accountId], $activeTickets);
        $stmt = $this->db()->prepare(
            "UPDATE positions SET status = 'closed'
             WHERE user_id = ? AND account_id = ? AND status = 'open' AND ticket NOT IN ($placeholders)"
        );
        $stmt->execute($params);
    }

    private function map(array $row): array
    {
        $row = $this->mapJsonFields($row, ['metadata']);
        foreach (['volume', 'entry_price', 'current_price', 'stop_loss', 'take_profit', 'profit', 'swap', 'commission'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }
}
