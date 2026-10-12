<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class PendingOrderRepository extends BaseRepository
{
    public function listPending(string $userId, ?string $accountId = null): array
    {
        $sql = 'SELECT * FROM pending_orders WHERE user_id = ? AND status = \'pending\'';
        $params = [$userId];
        if ($accountId) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY placed_at DESC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    public function upsert(string $userId, string $accountId, array $data): void
    {
        $existing = $this->db()->prepare(
            'SELECT id FROM pending_orders WHERE account_id = ? AND ticket = ? LIMIT 1'
        );
        $existing->execute([$accountId, $data['ticket']]);
        $row = $existing->fetch();

        if ($row) {
            $stmt = $this->db()->prepare(
                'UPDATE pending_orders SET
                    symbol = ?, direction = ?, volume = ?, requested_price = ?,
                    stop_loss = ?, take_profit = ?, placed_at = ?, status = ?, metadata = ?
                 WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([
                $data['symbol'],
                $data['direction'],
                $data['volume'],
                $data['requested_price'] ?? null,
                $data['stop_loss'] ?? null,
                $data['take_profit'] ?? null,
                $data['placed_at'] ?? date('Y-m-d H:i:s.v'),
                $data['status'] ?? 'pending',
                $this->encodeJson($data['metadata'] ?? []),
                $row['id'],
                $userId,
            ]);
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO pending_orders (
                    id, user_id, account_id, ticket, symbol, direction, volume,
                    requested_price, stop_loss, take_profit, placed_at, status, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                Uuid::v4(),
                $userId,
                $accountId,
                $data['ticket'],
                $data['symbol'],
                $data['direction'],
                $data['volume'],
                $data['requested_price'] ?? null,
                $data['stop_loss'] ?? null,
                $data['take_profit'] ?? null,
                $data['placed_at'] ?? date('Y-m-d H:i:s.v'),
                $data['status'] ?? 'pending',
                $this->encodeJson($data['metadata'] ?? []),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function cancelMissing(string $userId, string $accountId, array $activeTickets): array
    {
        $toCancel = $this->selectMissing($userId, $accountId, $activeTickets);
        if ($toCancel === []) {
            return [];
        }

        if ($activeTickets === []) {
            $stmt = $this->db()->prepare(
                'UPDATE pending_orders SET status = \'closed\'
                 WHERE user_id = ? AND account_id = ? AND status = \'pending\''
            );
            $stmt->execute([$userId, $accountId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($activeTickets), '?'));
            $params = array_merge([$userId, $accountId], $activeTickets);
            $stmt = $this->db()->prepare(
                "UPDATE pending_orders SET status = 'closed'
                 WHERE user_id = ? AND account_id = ? AND status = 'pending' AND ticket NOT IN ($placeholders)"
            );
            $stmt->execute($params);
        }

        return $toCancel;
    }

    /** @return list<array<string, mixed>> */
    private function selectMissing(string $userId, string $accountId, array $activeTickets): array
    {
        if ($activeTickets === []) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM pending_orders WHERE user_id = ? AND account_id = ? AND status = \'pending\''
            );
            $stmt->execute([$userId, $accountId]);

            return array_map([$this, 'map'], $stmt->fetchAll());
        }

        $placeholders = implode(',', array_fill(0, count($activeTickets), '?'));
        $params = array_merge([$userId, $accountId], $activeTickets);
        $stmt = $this->db()->prepare(
            "SELECT * FROM pending_orders
             WHERE user_id = ? AND account_id = ? AND status = 'pending' AND ticket NOT IN ($placeholders)"
        );
        $stmt->execute($params);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    private function map(array $row): array
    {
        $row = $this->mapJsonFields($row, ['metadata']);
        foreach (['volume', 'requested_price', 'stop_loss', 'take_profit'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }
}
