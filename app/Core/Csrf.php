<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection. Every POST/PUT/DELETE to /api must send
 * the header X-CSRF-Token (available in <meta name="csrf-token"> and /api/auth/csrf).
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('csrf');
        if (!is_string($token) || $token === '') {
            $token = self::rotate();
        }
        return $token;
    }

    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf', $token);
        return $token;
    }

    public static function verify(Request $request): void
    {
        $sent = $request->header('X-CSRF-Token') ?? $request->input('_csrf');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw HttpException::forbidden('Sessão expirada ou inválida. Recarregue a página e tente novamente.', 'CSRF_INVALID');
        }
    }
}
