<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class NotificationRepository extends BaseRepository
{
    public function list(string $userId, int $limit, int $offset): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $limit, $offset]);

        return $stmt->fetchAll();
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
}
