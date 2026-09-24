<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use Throwable;

final class HealthController
{
    public const VERSION = '1.0.0';

    public function show(Request $request): Response
    {
        try {
            Db::value('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'indisponível';
        }
        return Response::ok([
            'status' => $database === 'ok' ? 'ok' : 'degradado',
            'version' => self::VERSION,
            'database' => $database,
            'time' => now(),
        ], null, $database === 'ok' ? 200 : 503);
    }
}
