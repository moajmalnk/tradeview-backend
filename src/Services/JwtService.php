<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    private const DAY_SECONDS = 86400;

    public function issue(string $userId, string $email): string
    {
        $configured = (int) ($_ENV['JWT_TTL'] ?? self::DAY_SECONDS);
        $ttl = $configured > 0 ? min($configured, self::DAY_SECONDS) : self::DAY_SECONDS;
        $now = time();
        $payload = [
            'sub' => $userId,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    /** @return array{sub: string, email: string}|null */
    public function verify(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), 'HS256'));
            $data = (array) $decoded;
            if (!isset($data['sub'], $data['email'])) {
                return null;
            }

            $issuedAt = isset($data['iat']) ? (int) $data['iat'] : 0;
            if ($issuedAt <= 0 || time() >= $issuedAt + self::DAY_SECONDS) {
                return null;
            }

            return [
                'sub' => (string) $data['sub'],
                'email' => (string) $data['email'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function secret(): string
    {
        $secret = trim((string) ($_ENV['JWT_SECRET'] ?? ''));
        $placeholders = [
            '',
            'replace-with-long-random-string',
            'change-me-to-a-long-random-string',
        ];
        if (!in_array($secret, $placeholders, true)) {
            return $secret;
        }

        // Fallback so Hostinger works before JWT_SECRET is customized.
        $material = implode('|', [
            $_ENV['DB_PASS'] ?? '',
            $_ENV['DB_NAME'] ?? '',
            $_ENV['APP_URL'] ?? 'https://tradeapi.finbro.cloud',
        ]);

        return hash('sha256', 'tradeview-jwt|' . $material);
    }
}
