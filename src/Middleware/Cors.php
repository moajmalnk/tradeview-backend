<?php

declare(strict_types=1);

namespace App\Middleware;

final class Cors
{
    public static function apply(): void
    {
        $origins = array_filter(array_map('trim', explode(',', $_ENV['CORS_ORIGINS'] ?? '')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        } elseif ($origins === [] && ($_ENV['APP_ENV'] ?? '') === 'local') {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }
}
