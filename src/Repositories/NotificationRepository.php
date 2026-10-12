<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class NotificationRepository extends BaseRepository
{
    public function list(string $userId, int $limit, int $offset, ?string $unread = null): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        $params = [$userId];
        if ($unread === '1' || $unread === 'true') {
            $sql .= ' AND read_at IS NULL';
        } elseif ($unread === '0' || $unread === 'false') {
            $sql .= ' AND read_at IS NOT NULL';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countUnread(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function countAll(string $userId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(string $userId, string $id): ?array
    {
        $stmt = $this->db()->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$id, $userId]);

        $find = $this->db()->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
        $find->execute([$id, $userId]);
        $row = $find->fetch();

        return $row ?: null;
    }

    public function markAllRead(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP(3)
             WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    public function create(string $userId, array $data): array
    {
        $id = Uuid::v4();
        $stmt = $this->db()->prepare(
            'INSERT INTO notifications (id, user_id, account_id, type, title, message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $data['account_id'] ?? null,
            $data['type'],
            $data['title'],
            $data['message'] ?? null,
        ]);
        $find = $this->db()->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
        $find->execute([$id]);

        return $find->fetch();
    }

    /** First-run desk tips so an empty inbox is never a dead end. */
    public function ensureWelcome(string $userId): void
    {
        if ($this->countAll($userId) > 0) {
            return;
        }

        $this->create($userId, [
            'type' => 'CONNECTION_RESTORED',
            'title' => 'Welcome to TradeView Desk',
            'message' => 'Your monitoring workspace is live. Add an account or connector to start receiving trade and risk alerts.',
        ]);
        $this->create($userId, [
            'type' => 'DRAWDOWN_WARNING',
            'title' => 'Risk alerts are informational',
            'message' => 'Drawdown and concentration warnings never close positions automatically. Review them on the Risk page.',
        ]);
    }
}
