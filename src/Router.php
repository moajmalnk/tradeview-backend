<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use Closure;
use Throwable;

final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, handler: callable, auth: bool}> */
    private array $routes = [];

    public function get(string $path, callable $handler, bool $auth = false): void
    {
        $this->add(['GET'], $path, $handler, $auth);
    }

    public function post(string $path, callable $handler, bool $auth = false): void
    {
        $this->add(['POST'], $path, $handler, $auth);
    }

    public function patch(string $path, callable $handler, bool $auth = false): void
    {
        $this->add(['PATCH'], $path, $handler, $auth);
    }

    public function delete(string $path, callable $handler, bool $auth = false): void
    {
        $this->add(['DELETE'], $path, $handler, $auth);
    }

    /** @param list<string> $methods */
    private function add(array $methods, string $path, callable $handler, bool $auth): void
    {
        $pattern = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function dispatch(Request $request, ?Closure $authenticate = null): void
    {
        if ($request->method === 'OPTIONS') {
            Response::noContent();

            return;
        }

        foreach ($this->routes as $route) {
            if (!in_array($request->method, $route['methods'], true)) {
                continue;
            }
            if (!preg_match($route['pattern'], $request->path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $request->params = $params;

            if ($route['auth']) {
                if ($authenticate === null) {
                    Response::error('UNAUTHORIZED', 'Authentication required.', 401);

                    return;
                }
                $user = $authenticate($request);
                if ($user === null) {
                    Response::error('UNAUTHORIZED', 'Invalid or expired token.', 401);

                    return;
                }
                $request->user = $user;
            }

            try {
                ($route['handler'])($request);
            } catch (Throwable $e) {
                $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
                $code = (int) $e->getCode();
                $status = $code >= 400 && $code < 600 ? $code : 500;
                $isConfig = str_contains($e->getMessage(), 'JWT_SECRET');
                Response::error(
                    $isConfig ? 'CONFIG_ERROR' : 'INTERNAL_ERROR',
                    $debug || $isConfig ? $e->getMessage() : 'Something went wrong.',
                    $isConfig ? 503 : $status,
                );
            }

            return;
        }

        Response::error('NOT_FOUND', 'Endpoint not found.', 404);
    }
}
