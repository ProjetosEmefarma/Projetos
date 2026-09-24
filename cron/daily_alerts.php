<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\StockService;

$stalled = max(1, (int) SettingsService::get('stalled_days', '3'));
$since = date('Y-m-d H:i:s', time() - $stalled * 86400);

$items = Db::fetchAll(
    "SELECT i.id, i.code, i.name, i.min_stock, s.qty_on_hand, s.qty_reserved
       FROM items i JOIN stock s ON s.item_id = i.id
      WHERE i.deleted_at IS NULL AND i.status = 'ativo'"
);
foreach ($items as $i) {
    $available = (int) $i['qty_on_hand'] - (int) $i['qty_reserved'];
    $level = StockService::level($available, (int) $i['min_stock']);
    if ($level !== 'ok') {
        NotificationService::stockAlert((int) $i['id'], $i['code'], $i['name'], $level);
    }
}

$stalledRows = Db::fetchAll(
    "SELECT r.id, r.code, r.requester_id FROM requests r
      WHERE r.deleted_at IS NULL AND r.status IN ('aguardando_aprovacao','aprovada','em_separacao','pronta')
        AND r.status_changed_at < ?",
    [$since]
);
foreach ($stalledRows as $r) {
    NotificationService::queueInApp(
        (int) $r['requester_id'],
        'Solicitação parada: ' . $r['code'],
        "A solicitação {$r['code']} está sem movimentação há mais de {$stalled} dia(s).",
        '/solicitacoes/' . $r['id'],
        'stalled-' . $r['id'] . '-' . today(),
        'request',
        (int) $r['id']
    );
}

$pending = Db::fetchAll("SELECT r.id, r.code FROM requests r WHERE r.status = 'aguardando_aprovacao' AND r.deleted_at IS NULL");
foreach ($pending as $r) {
    NotificationService::notifyUsersByPermission(
        'requests.approve',
        'Aprovação pendente: ' . $r['code'],
        "A solicitação {$r['code']} ainda aguarda aprovação.",
        '/solicitacoes/' . $r['id'],
        'request',
        (int) $r['id']
    );
}

NotificationService::processOutbox(50);
fwrite(STDOUT, "Alertas diários processados.\n");
