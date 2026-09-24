<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;

    /** Route parameters, e.g. ['id' => '12']. */
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        private readonly array $headers = [],
        public readonly string $ip = '127.0.0.1',
        public readonly string $userAgent = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uriPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
        $base = Config::basePath();
        if ($base !== '' && str_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }
        $path = '/' . trim($uriPath, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw HttpException::badRequest('INVALID_JSON', 'Corpo da requisição não é um JSON válido.');
                }
                $body = $decoded;
            }
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $body,
            $_FILES,
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        );
    }

    public static function setCurrent(?Request $request): void
    {
        self::$current = $request;
    }

    public static function current(): ?Request
    {
        return self::$current;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** Query value as int or null (ignores empty / invalid). */
    public function queryInt(string $key): ?int
    {
        $value = $this->query($key);
        return is_numeric($value) ? (int) $value : null;
    }

    public function queryBool(string $key): bool
    {
        return in_array($this->query($key), ['1', 'true', 'sim', 'on'], true);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function param(string $name): string
    {
        return (string) ($this->params[$name] ?? '');
    }

    public function id(string $name = 'id'): int
    {
        return (int) $this->param($name);
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
