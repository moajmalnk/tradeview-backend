<?php

declare(strict_types=1);

namespace App\Services;

final class TokenHasher
{
    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /** @return array{token: string, prefix: string} */
    public static function generateConnectorToken(): array
    {
        $raw = self::randomBase32(12);
        $token = sprintf('TVM-%s-%s-%s', substr($raw, 0, 4), substr($raw, 4, 4), substr($raw, 8, 4));

        return ['token' => $token, 'prefix' => 'TVM-' . substr($raw, 0, 4)];
    }

    /** @return array{token: string, prefix: string} */
    public static function generateShareToken(): array
    {
        $bytes = random_bytes(20);
        $token = bin2hex($bytes);

        return ['token' => $token, 'prefix' => substr($token, 0, 6)];
    }

    private static function randomBase32(int $length): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
