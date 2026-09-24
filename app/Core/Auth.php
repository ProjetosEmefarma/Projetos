<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Current user + permission checks. The user is reloaded from the database on
 * every request, so deactivation, role changes and password changes apply at once.
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $id = (int) Session::get('uid', 0);
        if ($id === 0) {
            return;
        }
        $idleSeconds = (int) Config::get('session.idle_minutes', 480) * 60;
        $last = (int) Session::get('last', 0);
        if ($last > 0 && time() - $last > $idleSeconds) {
            Session::destroy();
            return;
        }
        $user = self::loadUser($id);
        if ($user === null || !(int) $user['active'] || (int) $user['session_version'] !== (int) Session::get('sv')) {
            Session::destroy();
            return;
        }
        Session::set('last', time());
        self::$user = $user;
    }

    public static function loadUser(int $id): ?array
    {
        return Db::fetch(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, d.name AS department_name,
                    ind.name AS industry_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN departments d ON d.id = u.department_id
          LEFT JOIN industries ind ON ind.id = u.industry_id
              WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id]
        );
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        Session::set('sv', (int) $user['session_version']);
        Session::set('last', time());
        Csrf::rotate();
        Db::update('users', ['last_login_at' => now()], ['id' => (int) $user['id']]);
        self::refresh((int) $user['id']);
    }

    /** Re-read the user after a change (password, profile, session version). */
    public static function refresh(?int $id = null): void
    {
        $id ??= self::id();
        self::$permissions = null;
        self::$booted = true;
        self::$user = $id ? self::loadUser($id) : null;
        if (self::$user !== null) {
            Session::set('sv', (int) self::$user['session_version']);
        }
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$permissions = null;
        self::$booted = true;
    }

    /** CLI / tests / cron: act as a given user without a session. */
    public static function actingAs(?array $user): void
    {
        self::$user = $user;
        self::$permissions = null;
        self::$booted = true;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$permissions = null;
        self::$booted = false;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user ? (int) self::$user['id'] : null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function roleSlug(): ?string
    {
        return self::$user['role_slug'] ?? null;
    }

    /** CD / Estoque profile (never gestor, TRADE or admin). */
    public static function isCdOperations(): bool
    {
        if (self::$user === null || self::isAdmin()) {
            return false;
        }
        $slug = (string) (self::$user['role_slug'] ?? '');
        $name = mb_strtolower((string) (self::$user['role_name'] ?? ''));
        $rid = (int) (self::$user['role_id'] ?? 0);
        return $slug === 'operations'
            || $rid === 3
            || str_contains($name, 'cd / estoque')
            || str_contains($name, 'cd/estoque');
    }

    public static function isOperations(): bool
    {
        return self::isCdOperations();
    }

    public static function isAdmin(): bool
    {
        return (self::$user['role_slug'] ?? null) === 'admin';
    }

    /** Industry portal: the logged user only sees this industry. */
    public static function industryId(): ?int
    {
        if (self::$user === null) {
            return null;
        }
        if ((self::$user['role_slug'] ?? null) === 'industry' && !empty(self::$user['industry_id'])) {
            return (int) self::$user['industry_id'];
        }
        return null;
    }

    /** @return string[] permission slugs of the current user */
    public static function permissions(): array
    {
        if (self::$user === null) {
            return [];
        }
        if (self::$permissions === null) {
            self::$permissions = self::isAdmin()
                ? Db::query('SELECT slug FROM permissions ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN)
                : Db::query(
                    'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                      WHERE rp.role_id = ? ORDER BY p.slug',
                    [(int) self::$user['role_id']]
                )->fetchAll(\PDO::FETCH_COLUMN);
        }
        return self::$permissions;
    }

    /** Any-of check when an array is given. The admin role always passes. */
    public static function can(string|array $permission): bool
    {
        if (self::$user === null) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        $granted = self::permissions();
        foreach ((array) $permission as $p) {
            if (in_array($p, $granted, true)) {
                return true;
            }
        }
        return false;
    }

    public static function authorize(string|array $permission): void
    {
        if (!self::can($permission)) {
            throw HttpException::forbidden();
        }
    }
}
