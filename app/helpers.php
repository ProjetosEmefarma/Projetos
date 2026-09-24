<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Services\SettingsService;

/**
 * Global helpers available inside views (resources/views) and PHP code.
 */

if (!function_exists('e')) {
    /** HTML-escape any value for safe output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('now')) {
    /** Current timestamp in the app timezone (Y-m-d H:i:s). */
    function now(): string
    {
        return \App\Support\Clock::now();
    }
}

if (!function_exists('today')) {
    function today(): string
    {
        return \App\Support\Clock::today();
    }
}

if (!function_exists('url')) {
    /** Absolute path inside the app, respecting installs in a sub-folder. */
    function url(string $path = '/'): string
    {
        return Config::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    /** Full http(s) URL, for e-mail bodies and links opened outside the app. */
    function absolute_url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        $appUrl = rtrim((string) Config::get('app.url', ''), '/');
        $host = parse_url($appUrl, PHP_URL_HOST);
        $local = is_string($host) && in_array($host, ['localhost', '127.0.0.1'], true);
        if (is_string($host) && $host !== '' && !$local) {
            $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
            $port = parse_url($appUrl, PHP_URL_PORT);
            $prefix = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?: ''), '/');
            return $scheme . '://' . $host . ($port ? ':' . $port : '') . $prefix . $path;
        }
        $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $https = $fwd === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $reqHost = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        if ($reqHost !== '') {
            return ($https ? 'https' : 'http') . '://' . $reqHost . Config::basePath() . $path;
        }
        return ($appUrl !== '' ? $appUrl : '') . $path;
    }
}

if (!function_exists('asset')) {
    /** URL for a file in public/assets with a cache-busting version. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = BASE_PATH . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return url('assets/' . $path) . '?v=' . $version;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('can')) {
    /** True when the logged user has the permission (or any of them when an array is given). */
    function can(string|array $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('is_cd_operations')) {
    function is_cd_operations(): bool
    {
        return Auth::isCdOperations();
    }
}

if (!function_exists('setting')) {
    function setting(string $key, ?string $default = null): ?string
    {
        return SettingsService::get($key, $default);
    }
}

if (!function_exists('json_script')) {
    /** JSON safe to embed inside a <script> tag. */
    function json_script(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: 'null';
    }
}
