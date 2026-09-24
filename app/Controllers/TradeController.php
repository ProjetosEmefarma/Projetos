<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\QrService;
use App\Services\RequestService;
use App\Services\StockService;
use App\Services\TradeService;

final class TradeController
{
    public function store(Request $request): Response
    {
        return Response::created(TradeService::create($request->all()));
    }

    public function purchase(Request $request): Response
    {
        return Response::ok(TradeService::approveWithTicket($request->id(), $request->all()));
    }

    public function approve(Request $request): Response
    {
        return Response::ok(TradeService::approveWithTicket($request->id(), $request->all()));
    }

    public function reject(Request $request): Response
    {
        return Response::ok(TradeService::reject($request->id(), $request->all()));
    }

    public function awaitReceipt(Request $request): Response
    {
        return Response::ok(TradeService::awaitReceipt($request->id()));
    }

    public function receive(Request $request): Response
    {
        return Response::ok(TradeService::receive($request->id(), $request->all(), $request->file('invoice')));
    }

    public function invoice(Request $request): Response
    {
        RequestService::find($request->id());
        $path = TradeService::invoiceAbsolute($request->id());
        return Response::file($path, TradeService::invoiceMime($path), 0, 'NF-' . $request->id() . '.' . pathinfo($path, PATHINFO_EXTENSION));
    }

    public function withdraw(Request $request): Response
    {
        return Response::created(TradeService::withdraw($request->id(), $request->all()));
    }

    public function delivered(Request $request): Response
    {
        return Response::ok(TradeService::markDelivered($request->id()));
    }

    public function lookup(Request $request): Response
    {
        $code = (string) ($request->query('code', '') ?: $request->input('code', ''));
        return Response::ok(TradeService::lookup($code));
    }

    public function qr(Request $request): Response
    {
        $row = RequestService::find($request->id());
        $code = (string) ($row['public_code'] ?? $row['code']);
        $png = QrService::png($code);
        return new Response(200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ], $png);
    }

    public function byIndustry(Request $request): Response
    {
        return Response::ok(TradeService::stockByIndustry($request->queryInt('industry_id')));
    }

    public function positions(Request $request): Response
    {
        return Response::ok(TradeService::positions($request->queryInt('item_id')));
    }

    public function transfer(Request $request): Response
    {
        return Response::created(TradeService::transfer($request->all()));
    }

    public function reverse(Request $request): Response
    {
        $id = (int) ($request->input('movement_id') ?? $request->id());
        $reason = (string) $request->input('reason', '');
        return Response::created(StockService::reverse($id, $reason));
    }
}
