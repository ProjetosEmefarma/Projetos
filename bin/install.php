<?php

declare(strict_types=1);

/**
 * Installer.
 *
 *   php bin/install.php                          install schema + seed into an empty database
 *   php bin/install.php --demo                   ... and load demo data (homologation only)
 *   php bin/install.php --fresh --demo           DROP everything and reinstall (refused in production)
 *   php bin/install.php --admin-email=a@b.com --admin-password=Secret123 [--admin-name="Nome"]
 *                                                set the first administrator's login
 *
 * Without shell access (shared hosting): import database/schema.sql and then
 * database/seed.sql with phpMyAdmin. Default login: admin@brindes.local / Trocar@123
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Validator;
use App\Support\DemoSeeder;
use App\Support\Installer;

$opts = getopt('', ['fresh', 'demo', 'force', 'admin-email:', 'admin-password:', 'admin-name:', 'help']);
$out = static fn (string $line) => fwrite(STDOUT, $line . PHP_EOL);

if (isset($opts['help'])) {
    $out(trim(explode('*/', explode('/**', file_get_contents(__FILE__))[1])[0]));
    exit(0);
}

$production = Config::get('app.env') === 'production';
if ((isset($opts['fresh']) || isset($opts['demo'])) && $production && !isset($opts['force'])) {
    fwrite(STDERR, "Recusado: APP_ENV=production. --fresh/--demo apagariam ou misturariam dados reais.\n");
    exit(1);
}

try {
    $alreadyInstalled = false;
    try {
        $alreadyInstalled = in_array('users', Installer::tables(), true);
    } catch (Throwable) {
    }

    Installer::install(isset($opts['fresh']), $out);

    if (isset($opts['admin-email']) || isset($opts['admin-password'])) {
        $email = mb_strtolower(trim((string) ($opts['admin-email'] ?? 'admin@brindes.local')));
        $password = (string) ($opts['admin-password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail do administrador inválido.');
        }
        Validator::password('admin-password', $password);
        Db::update('users', [
            'email' => $email,
            'name' => (string) ($opts['admin-name'] ?? 'Administrador'),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], ['id' => 1]);
        $out("Administrador configurado: {$email}");
    }

    if (isset($opts['demo'])) {
        if ($alreadyInstalled && !isset($opts['fresh'])) {
            $out('Dados de demonstração ignorados: o banco já existia (use --fresh --demo).');
        } else {
            DemoSeeder::run($out);
        }
    }

    if ((string) Config::get('app.key') === '') {
        $out('ATENÇÃO: defina APP_KEY no arquivo .env (ex.: ' . bin2hex(random_bytes(32)) . ')');
    }
    $out('Concluído.');
    if (!isset($opts['admin-password']) && !isset($opts['demo'])) {
        $out('Login inicial: admin@brindes.local / Trocar@123 (troca de senha obrigatória no primeiro acesso)');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
