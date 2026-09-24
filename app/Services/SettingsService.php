<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Throwable;

final class SettingsService
{
    public const DEFAULTS = [
        'company_name' => 'Controle de Brindes',
        'primary_color' => '#2563EB',
        'logo_path' => '',
        'stalled_days' => '3',
        'event_email_mode' => 'por_retirada',
        'alert_emails' => '',
    ];

    /** Keys editable through PUT /api/settings with their validation rules. */
    public const EDITABLE = [
        'company_name' => 'sometimes|required|string|max:100',
        'primary_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'stalled_days' => 'sometimes|required|int|min:1|max:60',
        'event_email_mode' => 'sometimes|required|in:por_retirada,consolidado',
        'alert_emails' => 'sometimes|nullable|string|max:500',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                $rows = Db::fetchAll('SELECT `key`, value FROM settings');
                self::$cache = self::DEFAULTS;
                foreach ($rows as $row) {
                    self::$cache[$row['key']] = (string) $row['value'];
                }
            } catch (Throwable) {
                return self::DEFAULTS; // DB not installed yet: render with defaults
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    /** Branding values used by every page and the login screen. */
    public static function branding(): array
    {
        $all = self::all();
        $logo = $all['logo_path'] ?? '';
        return [
            'company_name' => $all['company_name'],
            'primary_color' => $all['primary_color'],
            'logo_url' => $logo !== '' ? url('/api/settings/logo') . '?v=' . substr(md5($logo), 0, 8) : null,
        ];
    }

    public static function set(array $values): void
    {
        $before = self::all();
        Db::transaction(function () use ($values, $before) {
            foreach ($values as $key => $value) {
                $text = $value === null ? '' : (string) $value;
                if (Db::isSqlite()) {
                    Db::query(
                        'INSERT INTO settings (`key`, value, updated_by) VALUES (?, ?, ?)
                         ON CONFLICT(`key`) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by',
                        [$key, $text, Auth::id()]
                    );
                } else {
                    Db::query(
                        'INSERT INTO settings (`key`, value, updated_by) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = ?, updated_by = ?',
                        [$key, $text, Auth::id(), $text, Auth::id()]
                    );
                }
            }
            $after = array_map(fn ($v) => $v === null ? '' : (string) $v, $values);
            [$old, $new] = Audit::diff(array_intersect_key($before, $after), $after);
            if ($new !== []) {
                Audit::log('settings_update', 'settings', null, $old, $new, 'Configurações');
            }
        });
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
