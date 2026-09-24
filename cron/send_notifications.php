<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$n = App\Services\NotificationService::processOutbox(40);
fwrite(STDOUT, "Enviados: {$n}\n");
