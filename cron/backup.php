<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$path = App\Services\BackupService::create();
fwrite(STDOUT, "Backup: {$path}\n");
