<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function data(mixed $data, int $status = 200): void
    {
        self::json(['data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status = 400, ?array $details = null): void
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        self::json($payload, $status);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }
}
