<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DeliveryService;

final class DeliveriesController
{
    public function index(Request $request): Response
    {
        [$rows, $meta] = DeliveryService::list($request);
        return Response::ok($rows, $meta);
    }

    public function show(Request $request): Response
    {
        return Response::ok(DeliveryService::find($request->id()));
    }

    public function pdf(Request $request): Response
    {
        $path = DeliveryService::pdfPath($request->id());
        $code = \App\Core\Db::value('SELECT code FROM deliveries WHERE id = ?', [$request->id()]);
        return Response::file($path, 'application/pdf', 0, $code . '.pdf');
    }

    public function signature(Request $request): Response
    {
        return Response::file(DeliveryService::signaturePath($request->id()), 'image/png', 86400);
    }

    public function resend(Request $request): Response
    {
        DeliveryService::resend($request->id());
        return Response::ok(['resent' => true]);
    }
}
