<?php

declare(strict_types=1);

/**
 * Prove dump → empty database → restore → same table count and admin user.
 * Uses a side database (never the .env production name).
 *
 *   php tests/restore_check.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Services\BackupService;

$out = static fn (string $l) => fwrite(STDOUT, $l . PHP_EOL);

if (Config::get('db.driver') === 'sqlite') {
    fwrite(STDERR, "Este teste precisa de MySQL/MariaDB (produção).\n");
    exit(1);
}

$host = (string) Config::get('db.host');
$port = (int) Config::get('db.port');
$user = (string) Config::get('db.user');
$pass = (string) Config::get('db.pass');
$live = (string) Config::get('db.name');
$testDb = 'brindes_restore_test';

if ($live === $testDb) {
    fwrite(STDERR, "Recusado: DB_NAME não pode ser {$testDb}.\n");
    exit(1);
}

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/database/schema.sql');
$seed = file_get_contents($root . '/database/seed.sql');
if ($schema === false || $seed === false) {
    fwrite(STDERR, "schema.sql / seed.sql ausentes.\n");
    exit(1);
}

$dsnServer = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
$pdo = new PDO($dsnServer, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("DROP DATABASE `{$testDb}`");
$pdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

Config::set('db.name', $testDb);
Db::disconnect();

$import = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $testDb),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
foreach (BackupService::splitStatements($schema) as $st) {
    $import->exec($st);
}
foreach (BackupService::splitStatements($seed) as $st) {
    $import->exec($st);
}

$tablesBefore = (int) $import->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $import->quote($testDb))->fetchColumn();
$adminBefore = (string) $import->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$permBefore = (int) $import->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

$dir = Config::get('paths.storage') . '/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$dump = BackupService::create();
$dumpSize = filesize($dump);

$import->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($import->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
    $import->exec('DROP TABLE IF EXISTS `' . $row[0] . '`');
}
$import->exec('SET FOREIGN_KEY_CHECKS = 1');
$empty = (int) $import->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $import->quote($testDb))->fetchColumn();

Db::disconnect();
$restored = BackupService::restore($dump);

$after = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $testDb),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$tablesAfter = (int) $after->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $after->quote($testDb))->fetchColumn();
$adminAfter = (string) $after->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$permAfter = (int) $after->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

$pdo->exec("DROP DATABASE `{$testDb}`");
@unlink($dump);

$ok = $tablesBefore === $tablesAfter
    && $tablesBefore > 20
    && $empty === 0
    && $adminBefore === $adminAfter
    && $adminAfter === 'admin@brindes.local'
    && $permBefore === $permAfter
    && $restored > 0;

$report = [
    'date' => date('c'),
    'host' => $host . ':' . $port,
    'test_database' => $testDb,
    'tables_before' => $tablesBefore,
    'tables_after_drop' => $empty,
    'tables_after_restore' => $tablesAfter,
    'permissions' => $permAfter,
    'admin' => $adminAfter,
    'dump_bytes' => $dumpSize,
    'sql_statements' => $restored,
    'result' => $ok ? 'PASS' : 'FAIL',
];

$evidenceDir = $root . '/docs/evidencias';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0775, true);
}
$lines = "Teste de restauração — Controle de Brindes\n";
$lines .= "==========================================\n";
foreach ($report as $k => $v) {
    $lines .= $k . ': ' . $v . "\n";
}
file_put_contents($evidenceDir . '/restore-teste.txt', $lines);

$out($lines);
exit($ok ? 0 : 1);
