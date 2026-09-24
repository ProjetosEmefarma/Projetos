<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;

/**
 * Append-only audit trail. Every write in the system calls Audit::log()
 * inside the same transaction as the change itself.
 */
final class Audit
{
    private const HIDDEN = ['password', 'password_hash', 'token', 'token_hash', 'session_version'];
    private const IGNORED_IN_DIFF = ['updated_at', 'updated_by', 'created_at', 'created_by'];

    public static function log(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $label = null
    ): void {
        $user = Auth::user();
        $request = Request::current();
        Db::insert('audit_log', [
            'user_id' => $user ? (int) $user['id'] : null,
            'user_name' => $user['name'] ?? 'Sistema',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $label !== null ? mb_substr($label, 0, 190) : null,
            'before_json' => self::encode($before),
            'after_json' => self::encode($after),
            'ip' => $request?->ip ?? (PHP_SAPI === 'cli' ? 'cli' : null),
            'user_agent' => $request?->userAgent ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Only the fields that really changed.
     * @return array{0: array, 1: array} [before, after]
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if (in_array($key, self::IGNORED_IN_DIFF, true)) {
                continue;
            }
            $previous = $before[$key] ?? null;
            if (self::normalize($previous) !== self::normalize($value)) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }
        return [$old, $new];
    }

    /** Log an update only when something changed. */
    public static function logUpdate(string $entityType, int $entityId, array $before, array $after, ?string $label = null): void
    {
        [$old, $new] = self::diff($before, $after);
        if ($new !== []) {
            self::log('update', $entityType, $entityId, $old, $new, $label);
        }
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            return number_format((float) $value, 4, '.', '');
        }
        return (string) $value;
    }

    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        foreach (self::HIDDEN as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '***';
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
