<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Controle de Brindes'),
        'env' => Env::get('APP_ENV', 'production'),          // production | local | testing
        'debug' => (bool) Env::get('APP_DEBUG', false),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),
        'key' => (string) Env::get('APP_KEY', ''),            // random secret used to sign protocols
    ],

    'db' => [
        'driver' => Env::get('DB_DRIVER', 'mysql'), // mysql | sqlite
        'path' => Env::get('DB_PATH', BASE_PATH . '/database/demo.sqlite'),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', 'brindes'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => (string) Env::get('DB_PASS', ''),
    ],

    'session' => [
        'name' => 'BRINDES_SID',
        'driver' => Env::get('SESSION_DRIVER', 'file'), // file | cookie
        'idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 480),
    ],

    'mail' => [
        'driver' => Env::get('MAIL_DRIVER', 'log'),           // log | smtp
        'host' => Env::get('MAIL_HOST', ''),
        'port' => (int) Env::get('MAIL_PORT', 587),
        'username' => Env::get('MAIL_USERNAME', ''),
        'password' => (string) Env::get('MAIL_PASSWORD', ''),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),  // tls | ssl | none
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'Controle de Brindes'),
    ],

    'security' => [
        'login_max_attempts' => 5,       // per e-mail inside the window
        'login_max_attempts_ip' => 30,   // per IP inside the window
        'login_window_minutes' => 15,
        'password_reset_minutes' => 60,
    ],

    'uploads' => [
        'max_image_mb' => 5,
        'image_max_side' => 1200,
        'thumb_max_side' => 320,
    ],

    'paths' => [
        'storage' => BASE_PATH . '/storage',
        'views' => BASE_PATH . '/resources/views',
    ],
];
