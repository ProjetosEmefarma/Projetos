<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\HttpException;

/** Brute-force protection: limits failed logins per e-mail and per IP. */
final class LoginThrottle
{
    public static function check(string $email, string $ip): void
    {
        $window = (int) Config::get('security.login_window_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - $window * 60);

        $lastSuccess = (string) (Db::value(
            'SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND success = 1',
            [$email]
        ) ?? '1970-01-01 00:00:00');
        $from = max($since, $lastSuccess);

        $byEmail = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?',
            [$email, $from]
        );
        $byIp = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?',
            [$ip, $since]
        );

        if ($byEmail >= (int) Config::get('security.login_max_attempts', 5)
            || $byIp >= (int) Config::get('security.login_max_attempts_ip', 30)) {
            throw new HttpException(
                429,
                'TOO_MANY_ATTEMPTS',
                "Muitas tentativas de login. Aguarde {$window} minutos e tente novamente."
            );
        }
    }

    public static function hit(string $email, string $ip, bool $success): void
    {
        Db::insert('login_attempts', [
            'email' => mb_substr($email, 0, 190),
            'ip' => $ip,
            'success' => $success ? 1 : 0,
            'created_at' => now(),
        ]);
    }
}
