<?php

declare(strict_types=1);

/**
 * Child process for the concurrency scenarios of tests/scenarios.php.
 *   php concurrent_worker.php exit  <itemId> <startAt>
 *   php concurrent_worker.php idem  <itemId> <startAt> <idempotencyKey>
 * Prints one JSON line with the outcome.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\StockService;

[$_, $mode, $itemId, $startAt] = $argv + [null, null, null, null];
$itemId = (int) $itemId;

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    if ($mode === 'exit') {
        Auth::actingAs(Auth::loadUser(1));
        $result = StockService::exit($itemId, 1, ['purpose' => 'Teste de concorrência']);
        echo json_encode(['ok' => true, 'movement' => $result['movement']['id']]);
    } else {
        $admin = Auth::loadUser(1);
        Session::load(['uid' => 1, 'sv' => (int) $admin['session_version'], 'last' => time(), 'csrf' => 'worker-token']);
        $response = App::create()->handle(new Request(
            'POST',
            '/api/stock/exits',
            [],
            ['item_id' => $itemId, 'quantity' => 1, 'purpose' => 'Teste de envio duplicado'],
            [],
            ['x-csrf-token' => 'worker-token', 'idempotency-key' => $argv[4]],
            '10.0.0.9',
            'worker'
        ));
        $json = $response->decoded();
        echo json_encode([
            'ok' => $json['ok'] ?? false,
            'status' => $response->status,
            'movement' => $json['data']['order']['id'] ?? $json['data']['movement']['id'] ?? null,
            'replayed' => isset($response->headers['Idempotent-Replayed']),
            'error' => $json['error']['code'] ?? null,
        ]);
    }
} catch (HttpException $e) {
    echo json_encode(['ok' => false, 'error' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
