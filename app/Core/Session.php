<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper. Uses native PHP sessions on the web, signed cookies on
 * Vercel (serverless has no shared disk), and an in-memory array in CLI.
 */
final class Session
{
    private static bool $started = false;
    private static array $memory = [];
    private static bool $cookieDirty = false;

    private static function native(): bool
    {
        return PHP_SAPI !== 'cli';
    }

    private static function cookieDriver(): bool
    {
        return self::native() && (
            (string) Config::get('session.driver', 'file') === 'cookie'
            || (string) getenv('VERCEL') !== ''
        );
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        if (!self::native()) {
            return;
        }
        if (self::cookieDriver()) {
            self::loadCookie();
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name(Config::get('session.name', 'BRINDES_SID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Config::basePath() ?: '') . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (Config::get('session.idle_minutes', 480) * 60));
        $dir = Config::get('paths.storage') . '/cache/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return self::native() ? ($_SESSION[$key] ?? $default) : (self::$memory[$key] ?? $default);
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        if (self::native()) {
            $_SESSION[$key] = $value;
        } else {
            self::$memory[$key] = $value;
        }
        self::$cookieDirty = true;
    }

    public static function forget(string $key): void
    {
        self::start();
        if (self::native()) {
            unset($_SESSION[$key]);
        } else {
            unset(self::$memory[$key]);
        }
        self::$cookieDirty = true;
    }

    /** New session id, same data (prevents session fixation on login). */
    public static function regenerate(): void
    {
        self::start();
        if (self::cookieDriver()) {
            self::$cookieDirty = true;
            return;
        }
        if (self::native() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::start();
        if (self::native()) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        } else {
            self::$memory = [];
        }
        self::$cookieDirty = true;
        if (self::cookieDriver()) {
            self::writeCookie('');
        }
    }

    /** CLI only: load/dump the in-memory store (used by the test client). */
    public static function load(array $data): void
    {
        self::$memory = $data;
        self::$started = true;
    }

    public static function dump(): array
    {
        return self::$memory;
    }

    public static function persistCookie(): void
    {
        if (!self::$started || !self::cookieDriver()) {
            return;
        }
        if (!self::$cookieDirty && ($_SESSION ?? []) === []) {
            return;
        }
        $payload = json_encode($_SESSION ?? [], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $key = self::appKey();
        $raw = base64_encode($payload) . '.' . hash_hmac('sha256', $payload, $key);
        self::writeCookie($raw);
    }

    private static function appKey(): string
    {
        $key = (string) Config::get('app.key', '');
        if ($key === '' || $key === 'demo') {
            $key = hash('sha256', __FILE__ . (string) (Config::get('database.database') ?? 'controle_brindes_local_secret'));
        }
        return $key;
    }

    private static function loadCookie(): void
    {
        $_SESSION = [];
        $name = (string) Config::get('session.name', 'BRINDES_SID');
        $raw = (string) ($_COOKIE[$name] ?? '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return;
        }
        [$b64, $mac] = explode('.', $raw, 2);
        $payload = base64_decode($b64, true);
        if ($payload === false) {
            return;
        }
        $key = self::appKey();
        $expected = hash_hmac('sha256', $payload, $key);
        if (!hash_equals($expected, $mac)) {
            return;
        }
        $data = json_decode($payload, true);
        if (is_array($data)) {
            $_SESSION = $data;
        }
    }

    private static function writeCookie(string $value): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $name = (string) Config::get('session.name', 'BRINDES_SID');
        setcookie($name, $value, [
            'expires' => $value === '' ? time() - 3600 : 0,
            'path' => (Config::basePath() ?: '') . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
