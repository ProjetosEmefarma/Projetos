<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Env;
use App\Core\Log;

/**
 * Keeps the Vercel demo SQLite in Vercel Blob so every serverless instance
 * sees the same cadastros, approvals and receipts.
 */
final class DemoSqliteStore
{
    private const PATHNAME = 'brindes-demo.sqlite';

    public static function hydrate(string $dst, string $bundled): void
    {
        $token = self::token();
        if ($token !== '') {
            $url = self::latestUrl($token);
            if ($url !== null && self::download($url, $dst, $token)) {
                return;
            }
        }
        if (!is_file($dst) && is_file($bundled)) {
            @copy($bundled, $dst);
        }
    }

    public static function save(string $path): void
    {
        $token = self::token();
        if ($token === '' || !is_file($path)) {
            if ($token === '' && Env::get('VERCEL')) {
                Log::error('Demo sqlite blob save skipped: missing BLOB_READ_WRITE_TOKEN');
            }
            return;
        }
        $body = file_get_contents($path);
        if (!is_string($body) || $body === '') {
            return;
        }
        $qs = 'pathname=' . rawurlencode(self::PATHNAME);
        $headers = [
            'x-api-version: 10',
            'x-vercel-blob-access: private',
            'x-add-random-suffix: 0',
            'x-allow-overwrite: 1',
            'x-content-type: application/octet-stream',
            'Content-Type: application/octet-stream',
        ];
        $res = self::request('PUT', 'https://blob.vercel-storage.com/?' . $qs, $token, $body, $headers, 25);
        if (($res['code'] ?? 0) < 200 || ($res['code'] ?? 0) >= 300) {
            $fallback = self::request('PUT', 'https://blob.vercel-storage.com/?' . $qs, $token, $body, [
                'x-api-version: 7',
                'x-vercel-blob-access: private',
                'x-add-random-suffix: 1',
                'x-content-type: application/octet-stream',
            ], 25);
            if (($fallback['code'] ?? 0) < 200 || ($fallback['code'] ?? 0) >= 300) {
                Log::error('Demo sqlite blob save failed', [
                    'code' => $res['code'] ?? 0,
                    'body' => substr((string) ($res['body'] ?? ''), 0, 300),
                    'fallback' => $fallback['code'] ?? 0,
                    'fallback_body' => substr((string) ($fallback['body'] ?? ''), 0, 300),
                ]);
            }
        }
    }

    private static function token(): string
    {
        $t = Env::get('BLOB_READ_WRITE_TOKEN', '');
        return is_string($t) && $t !== '' ? $t : '';
    }

    private static function latestUrl(string $token): ?string
    {
        $res = self::request('GET', 'https://blob.vercel-storage.com/?prefix=' . rawurlencode('brindes-demo'), $token, null, [
            'x-api-version: 10',
        ], 8);
        $json = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($json)) {
            return null;
        }
        $best = null;
        $bestTime = '';
        foreach ($json['blobs'] ?? [] as $blob) {
            $name = (string) ($blob['pathname'] ?? '');
            if ($name === '' || !str_starts_with($name, 'brindes-demo')) {
                continue;
            }
            $when = (string) ($blob['uploadedAt'] ?? $blob['uploaded_at'] ?? '');
            if ($best === null || $when >= $bestTime) {
                $best = $blob;
                $bestTime = $when;
            }
        }
        return !empty($best['url']) ? (string) $best['url'] : null;
    }

    private static function download(string $url, string $dst, string $token): bool
    {
        $res = self::request('GET', $url, $token, null, [], 15);
        $bin = $res['body'] ?? '';
        if (($res['code'] ?? 0) >= 400 || !is_string($bin) || strlen($bin) < 1024) {
            return false;
        }
        if (!str_starts_with($bin, 'SQLite format 3')) {
            Log::error('Demo sqlite blob ignored: not a SQLite file', ['bytes' => strlen($bin)]);
            return false;
        }
        $dir = dirname($dst);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return file_put_contents($dst, $bin) !== false;
    }

    /** @param list<string> $headers */
    private static function request(string $method, string $url, string $token, ?string $body, array $headers, int $timeout): array
    {
        $headers[] = 'Authorization: Bearer ' . $token;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = is_string($out) ? '' : (string) curl_error($ch);
            curl_close($ch);
            return ['code' => $code, 'body' => is_string($out) ? $out : $err];
        }
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $out = @file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => true]]));
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return ['code' => $code, 'body' => is_string($out) ? $out : ''];
    }
}
