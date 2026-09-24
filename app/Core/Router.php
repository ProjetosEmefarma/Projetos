<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Pattern router. Patterns use {name} or {name:regex}, e.g. /api/items/{id:\d+}.
 * Route options:
 *   public   => true   no login required
 *   perm     => 'x.y' | ['a.b','c.d']  any-of permission check
 *   csrf     => false  skip CSRF check (only for safe endpoints)
 *   idempotent => true require Idempotency-Key and replay duplicates
 *   password_change_ok => true reachable while the user must change the password
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, array $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, array $handler, array $options = []): void
    {
        $this->add('PUT', $pattern, $handler, $options);
    }

    public function delete(string $pattern, array $handler, array $options = []): void
    {
        $this->add('DELETE', $pattern, $handler, $options);
    }

    public function add(string $method, string $pattern, array $handler, array $options = []): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => self::compile($pattern),
            'handler' => $handler,
            'options' => $options,
        ];
    }

    public static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        );
        return '#^' . $regex . '$#';
    }

    /**
     * @return array{route: array, params: array}|null
     * @throws HttpException 405 when the path exists with another method
     */
    public function match(string $method, string $path): ?array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['route' => $route, 'params' => $params];
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Método não permitido para este endereço.');
        }
        return null;
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
