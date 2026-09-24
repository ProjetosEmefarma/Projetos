<?php

declare(strict_types=1);

/**
 * Build the SQLite file shipped with the Vercel demo.
 *
 *   php bin/build-demo-sqlite.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Support\DemoSeeder;
use App\Support\Installer;

$path = BASE_PATH . '/database/demo.sqlite';
if (is_file($path)) {
    unlink($path);
}
foreach (glob($path . '*') ?: [] as $extra) {
    @unlink($extra);
}

Config::set('db.driver', 'sqlite');
Config::set('db.path', $path);
Db::disconnect();

Installer::install(true, static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
});
DemoSeeder::run(static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
});

Db::pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
Db::pdo()->exec('PRAGMA journal_mode = DELETE');
Db::disconnect();
foreach (glob($path . '-*') ?: [] as $extra) {
    @unlink($extra);
}

$size = round(filesize($path) / 1024, 1);
fwrite(STDOUT, "SQLite demo pronto: {$path} ({$size} KB)" . PHP_EOL);
