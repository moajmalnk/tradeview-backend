<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class CapitalMovementRepository extends BaseRepository
{
    private const IN_TYPES = ['deposit_to_trader', 'broker_deposit'];
    private const OUT_TYPES = ['return_from_trader', 'broker_withdrawal'];

    public function list(string $userId, ?string $accountId, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM capital_movements WHERE user_id = ?';
        $params = [$userId];
        if ($accountId) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY paid_at DESC, created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'map'], $stmt->fetchAll());
    }

    public function find(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM capital_movements WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row ? $this->map($row) : null;
    }

    public function create(string $userId, array $data): array
    {
        $id = Uuid::v4();
        $amount = (float) ($data['amount'] ?? 0);
        $fx = isset($data['fx_rate']) && $data['fx_rate'] !== null && $data['fx_rate'] !== ''
            ? (float) $data['fx_rate']
            : null;
        $currency = strtoupper((string) ($data['currency'] ?? 'INR'));
        $amountUsd = $data['amount_usd'] ?? null;
        if ($amountUsd === null || $amountUsd === '') {
            if ($currency === 'USD') {
                $amountUsd = $amount;
            } elseif ($fx !== null && $fx > 0) {
                $amountUsd = $amount / $fx;
            } else {
                $amountUsd = null;
            }
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO capital_movements (
                id, user_id, account_id, type, amount, currency, fx_rate, amount_usd,
                paid_at, method, reference, counterparty, note, receipt_url, attachments
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $attachments = $this->normalizeAttachments($data['attachments'] ?? []);
        $receiptUrl = $data['receipt_url'] ?? ($attachments[0]['url'] ?? null);

        $stmt->execute([
            $id,
            $userId,
            $data['account_id'] ?? null,
            $data['type'],
            $amount,
            $currency,
            $fx,
            $amountUsd,
            $data['paid_at'] ?? date('Y-m-d H:i:s'),
            $data['method'] ?? 'upi',
            $data['reference'] ?? null,
            $data['counterparty'] ?? null,
            $data['note'] ?? null,
            $receiptUrl,
            $this->encodeJson($attachments),
        ]);

        return $this->find($userId, $id);
    }

    public function update(string $userId, string $id, array $fields): ?array
    {
        if (!$this->find($userId, $id)) {
            return null;
        }

        $allowed = [
            'account_id', 'type', 'amount', 'currency', 'fx_rate', 'amount_usd',
            'paid_at', 'method', 'reference', 'counterparty', 'note', 'receipt_url', 'attachments',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = ?";
            if ($key === 'attachments') {
                $params[] = $this->encodeJson($this->normalizeAttachments($fields[$key]));
            } else {
                $params[] = $fields[$key];
            }
        }
        if ($sets === []) {
            return $this->find($userId, $id);
        }
        $params[] = $id;
        $params[] = $userId;
        $this->db()->prepare(
            'UPDATE capital_movements SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        )->execute($params);

        return $this->find($userId, $id);
    }

    public function delete(string $userId, string $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM capital_movements WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{capital_in: float, capital_out: float, net_funded: float} */
    public function fundedTotals(string $userId, ?string $accountId = null): array
    {
        $sql = 'SELECT type, amount, currency, amount_usd, fx_rate FROM capital_movements WHERE user_id = ?';
        $params = [$userId];
        if ($accountId) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $capitalIn = 0.0;
        $capitalOut = 0.0;
        foreach ($rows as $row) {
            $usd = $this->rowUsd($row);
            $type = (string) $row['type'];
            if (in_array($type, self::IN_TYPES, true)) {
                $capitalIn += $usd;
            } elseif (in_array($type, self::OUT_TYPES, true)) {
                $capitalOut += $usd;
            } elseif ($type === 'fee') {
                $capitalOut += $usd;
            }
        }

        return [
            'capital_in' => round($capitalIn, 2),
            'capital_out' => round($capitalOut, 2),
            'net_funded' => round($capitalIn - $capitalOut, 2),
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowUsd(array $row): float
    {
        if ($row['amount_usd'] !== null && $row['amount_usd'] !== '') {
            return (float) $row['amount_usd'];
        }
        $amount = (float) $row['amount'];
        $currency = strtoupper((string) $row['currency']);
        if ($currency === 'USD') {
            return $amount;
        }
        $fx = $row['fx_rate'] !== null ? (float) $row['fx_rate'] : 0.0;
        if ($fx > 0) {
            return $amount / $fx;
        }

        return $amount;
    }

    private function map(array $row): array
    {
        $row = $this->mapJsonFields($row, ['attachments']);
        if (!is_array($row['attachments'] ?? null)) {
            $row['attachments'] = [];
        }
        foreach (['amount', 'fx_rate', 'amount_usd'] as $n) {
            if ($row[$n] !== null) {
                $row[$n] = (float) $row[$n];
            }
        }

        return $row;
    }

    /** @return list<array{id: string, name: string, mime: string, size: int, url: string}> */
    private function normalizeAttachments(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => (string) ($item['id'] ?? Uuid::v4()),
                'name' => (string) ($item['name'] ?? 'receipt'),
                'mime' => (string) ($item['mime'] ?? 'image/jpeg'),
                'size' => (int) ($item['size'] ?? 0),
                'url' => $url,
            ];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }
}
