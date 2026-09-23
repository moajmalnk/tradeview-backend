<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class SignalRepository extends BaseRepository
{
    public function list(string $userId, int $limit, int $offset): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM tradingview_signals WHERE user_id = ? ORDER BY alert_time DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $limit, $offset]);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    public function find(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM tradingview_signals WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row ? $this->map($row) : null;
    }

    public function create(string $userId, array $data): array
    {
        $id = Uuid::v4();
        $stmt = $this->db()->prepare(
            'INSERT INTO tradingview_signals (
                id, user_id, symbol, direction, entry, stop_loss, take_profit,
                alert_time, source, payload, event_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $data['symbol'],
            $data['direction'],
            $data['entry'] ?? null,
            $data['stop_loss'] ?? null,
            $data['take_profit'] ?? null,
            $data['alert_time'] ?? date('Y-m-d H:i:s.v'),
            $data['source'] ?? 'TradingView',
            $this->encodeJson($data['payload'] ?? []),
            $data['event_id'] ?? null,
            $data['status'] ?? 'received',
        ]);

        return $this->find($userId, $id);
    }

    private function map(array $row): array
    {
        $row = $this->mapJsonFields($row, ['payload']);
        foreach (['entry', 'stop_loss', 'take_profit'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }
}
