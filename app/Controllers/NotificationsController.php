<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

final class NotificationsController
{
    public function index(Request $request): Response
    {
        [$rows, $meta] = NotificationService::inbox($request);
        return Response::ok($rows, $meta);
    }

    public function read(Request $request): Response
    {
        NotificationService::markRead($request->id());
        return Response::ok(['read' => true]);
    }

    public function readAll(Request $request): Response
    {
        NotificationService::markAllRead();
        return Response::ok(['read' => true]);
    }
}
