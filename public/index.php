<?php

declare(strict_types=1);

use App\Middleware\Cors;
use App\Http\Request;
use App\Router;
use App\Routes;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

// Ensure env vars are visible via $_ENV even if only putenv was used
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET', 'JWT_TTL', 'CORS_ORIGINS', 'APP_DEBUG', 'APP_ENV'] as $key) {
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val !== false && $val !== null && $val !== '') {
        $_ENV[$key] = (string) $val;
    }
}

Cors::apply();

$router = new Router();
Routes::register($router);
$router->dispatch(Request::fromGlobals(), Routes::authenticate(...));
