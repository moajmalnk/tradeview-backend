<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProfileRepository extends BaseRepository
{
    public function find(string $userId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['demo_mode'] = (bool) $row['demo_mode'];

        return $row;
    }

    public function update(string $userId, array $fields): ?array
    {
        $allowed = ['full_name', 'avatar_url', 'timezone', 'demo_mode'];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = ?";
            $params[] = $key === 'demo_mode' ? $this->boolInt($fields[$key]) : $fields[$key];
        }
        if ($sets === []) {
            return $this->find($userId);
        }
        $params[] = $userId;
        $sql = 'UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db()->prepare($sql)->execute($params);

        return $this->find($userId);
    }
}
