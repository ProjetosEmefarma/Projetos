<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private ?string $filePath = null;

    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /** Standard success envelope: {"ok":true,"data":...,"meta":...} */
    public static function ok(mixed $data = null, ?array $meta = null, int $status = 200): self
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    public static function created(mixed $data = null): self
    {
        return self::ok($data, null, 201);
    }

    public static function fromException(HttpException $e): self
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->fields !== []) {
            $error['fields'] = $e->fields;
        }
        if ($e->details !== []) {
            $error['details'] = $e->details;
        }
        return self::json(['ok' => false, 'error' => $error], $e->status);
    }

    public static function json(mixed $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location]);
    }

    public static function file(string $path, string $mime, int $maxAge = 0, ?string $downloadName = null): self
    {
        $response = new self(200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => $maxAge > 0 ? "private, max-age={$maxAge}" : 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($downloadName !== null) {
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
            $response->headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        }
        $response->filePath = $path;
        return $response;
    }

    public static function download(string $content, string $filename, string $mime): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'arquivo';
        return new self(200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], $content);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** Decoded JSON body (used by tests). */
    public function decoded(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        Session::persistCookie();
        \App\Core\Db::persistDemo();
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
