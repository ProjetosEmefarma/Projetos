<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Request;
use App\Core\Session;

/**
 * In-process HTTP client: runs requests through the real kernel (routing,
 * sessions, CSRF, permissions, idempotency) without a web server.
 */
final class TestClient
{
    public array $session = [];

    public function __construct(private App $app, public string $ip = '10.0.0.1')
    {
    }

    /** @return array{status:int, json:mixed, headers:array, body:string} */
    public function call(string $method, string $uri, array $body = [], array $headers = [], array $files = []): array
    {
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);
        $headers = array_change_key_case($headers, CASE_LOWER);
        if ($method !== 'GET' && !array_key_exists('x-csrf-token', $headers)) {
            $headers['x-csrf-token'] = $this->session['csrf'] ?? '';
        }
        Session::load($this->session);
        $response = $this->app->handle(new Request($method, $path, $query, $body, $files, $headers, $this->ip, 'test-suite'));
        $this->session = Session::dump();
        return [
            'status' => $response->status,
            'json' => $response->decoded(),
            'headers' => $response->headers,
            'body' => $response->body,
        ];
    }

    public function get(string $uri): array
    {
        return $this->call('GET', $uri);
    }

    public function post(string $uri, array $body = [], array $headers = []): array
    {
        return $this->call('POST', $uri, $body, $headers);
    }

    public function put(string $uri, array $body = []): array
    {
        return $this->call('PUT', $uri, $body);
    }

    public function delete(string $uri, array $body = []): array
    {
        return $this->call('DELETE', $uri, $body);
    }

    /** Stock writes need an Idempotency-Key. */
    public function postIdem(string $uri, array $body, ?string $key = null, array $files = []): array
    {
        return $this->call('POST', $uri, $body, ['Idempotency-Key' => $key ?? bin2hex(random_bytes(16))], $files);
    }

    public function login(string $email, string $password): array
    {
        $this->get('/api/auth/csrf');
        return $this->post('/api/auth/login', ['email' => $email, 'password' => $password]);
    }
}
