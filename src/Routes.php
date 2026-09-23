<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AccountsController;
use App\Controllers\AuthController;
use App\Controllers\ConnectorController;
use App\Controllers\NotificationsController;
use App\Controllers\PositionsController;
use App\Controllers\ProfileController;
use App\Controllers\ShareController;
use App\Controllers\SignalsController;
use App\Controllers\TokensController;
use App\Controllers\TradesController;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Services\JwtService;

final class Routes
{
    public static function register(Router $router): void
    {
        $auth = new AuthController();
        $profile = new ProfileController();
        $accounts = new AccountsController();
        $positions = new PositionsController();
        $trades = new TradesController();
        $signals = new SignalsController();
        $notifications = new NotificationsController();
        $tokens = new TokensController();
        $share = new ShareController();
        $connector = new ConnectorController();

        $router->get('/v1/health', static function (): void {
            Response::data([
                'ok' => true,
                'service' => 'tradeview-api',
                'time' => gmdate('c'),
            ]);
        });

        $router->get('/v1/health/db', static function (): void {
            try {
                $pdo = Db::pdo();
                $pdo->query('SELECT 1');
                $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                Response::data([
                    'ok' => true,
                    'database' => 'connected',
                    'users' => $users,
                ]);
            } catch (\Throwable) {
                Response::error('DB_UNAVAILABLE', 'Database connection failed.', 503);
            }
        });

        $router->post('/v1/auth/register', [$auth, 'register']);
        $router->post('/v1/auth/login', [$auth, 'login']);
        $router->get('/v1/auth/me', [$auth, 'me'], true);
        $router->post('/v1/auth/logout', [$auth, 'logout'], true);

        $router->get('/v1/profile', [$profile, 'show'], true);
        $router->patch('/v1/profile', [$profile, 'update'], true);

        $router->get('/v1/accounts', [$accounts, 'index'], true);
        $router->post('/v1/accounts', [$accounts, 'store'], true);
        $router->get('/v1/accounts/{id}', [$accounts, 'show'], true);
        $router->patch('/v1/accounts/{id}', [$accounts, 'update'], true);
        $router->delete('/v1/accounts/{id}', [$accounts, 'destroy'], true);

        $router->get('/v1/positions', [$positions, 'index'], true);

        $router->get('/v1/trades', [$trades, 'index'], true);
        $router->post('/v1/trades', [$trades, 'store'], true);
        $router->get('/v1/trades/{id}', [$trades, 'show'], true);
        $router->patch('/v1/trades/{id}', [$trades, 'update'], true);
        $router->delete('/v1/trades/{id}', [$trades, 'destroy'], true);

        $router->get('/v1/signals', [$signals, 'index'], true);
        $router->post('/v1/signals', [$signals, 'store'], true);
        $router->get('/v1/signals/{id}', [$signals, 'show'], true);

        $router->get('/v1/notifications', [$notifications, 'index'], true);
        $router->post('/v1/notifications/read-all', [$notifications, 'markAllRead'], true);
        $router->patch('/v1/notifications/{id}/read', [$notifications, 'markRead'], true);

        $router->get('/v1/connector-tokens', [$tokens, 'index'], true);
        $router->post('/v1/connector-tokens', [$tokens, 'store'], true);
        $router->delete('/v1/connector-tokens/{id}', [$tokens, 'destroy'], true);

        $router->get('/v1/share-links', [$share, 'index'], true);
        $router->post('/v1/share-links', [$share, 'store'], true);
        $router->delete('/v1/share-links/{id}', [$share, 'destroy'], true);
        $router->get('/v1/share/{token}', [$share, 'resolve']);

        $router->post('/v1/connector/sync', [$connector, 'sync']);
    }

    /** @return array{id: string, email: string}|null */
    public static function authenticate(Request $request): ?array
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $jwt = new JwtService();
        $claims = $jwt->verify($token);
        if (!$claims) {
            return null;
        }

        $user = (new UserRepository())->findById($claims['sub']);
        if (!$user) {
            return null;
        }

        return ['id' => $user['id'], 'email' => $user['email']];
    }
}
