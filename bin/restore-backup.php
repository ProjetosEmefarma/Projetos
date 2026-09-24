<?php

declare(strict_types=1);

/**
 * Restore a SQL dump into the database from .env.
 *
 *   php bin/restore-backup.php storage/backups/backup-20260918-020010.sql
 *
 * The dump created by the application includes DROP TABLE. Existing tables
 * in DB_NAME are replaced. Photos/PDFs in storage/ must be copied separately.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\BackupService;

$path = $argv[1] ?? '';
if ($path === '' || $path === '--help' || $path === '-h') {
    fwrite(STDOUT, "Uso: php bin/restore-backup.php CAMINHO/backup.sql\n");
    exit($path === '' ? 1 : 0);
}

$abs = $path;
if (!str_contains($path, '/') && !str_contains($path, '\\') && !is_file($path)) {
    $abs = Config::get('paths.storage') . '/backups/' . $path;
}
if (!is_file($abs) && is_file($path)) {
    $abs = $path;
}

try {
    $n = BackupService::restore($abs);
    fwrite(STDOUT, "Restaurado: {$abs}\n");
    fwrite(STDOUT, "Banco: " . Config::get('db.name') . " @ " . Config::get('db.host') . "\n");
    fwrite(STDOUT, "Comandos SQL executados: {$n}\n");
    fwrite(STDOUT, "Confira login, brindes e um PDF de comprovante.\n");
    fwrite(STDOUT, "Se as fotos sumiram, copie de volta a pasta storage/ (uploads, signatures, pdf).\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha na restauração: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
