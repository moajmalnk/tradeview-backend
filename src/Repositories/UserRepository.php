<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Uuid;

final class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT id, email, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $email, string $passwordHash, ?string $fullName = null): array
    {
        $id = Uuid::v4();
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$id, strtolower($email), $passwordHash]);

            $profile = $pdo->prepare(
                'INSERT INTO profiles (id, full_name, email, timezone, demo_mode) VALUES (?, ?, ?, ?, 1)'
            );
            $profile->execute([$id, $fullName, strtolower($email), 'UTC']);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'email' => strtolower($email)];
    }
}
