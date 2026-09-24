<?php

declare(strict_types=1);

// PHP built-in dev server: serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

try {
    $request = Request::fromGlobals();
} catch (HttpException $e) {
    Response::fromException($e)->send();
    exit;
}

App::create()->handle($request)->send();
