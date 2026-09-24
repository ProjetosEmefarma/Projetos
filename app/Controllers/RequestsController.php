<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\RequestService;

final class RequestsController
{
    public function index(Request $request): Response
    {
        [$rows, $meta] = RequestService::list($request);
        return Response::ok($rows, $meta);
    }

    public function show(Request $request): Response
    {
        return Response::ok(RequestService::find($request->id()));
    }

    public function store(Request $request): Response
    {
        $submit = (bool) ($request->input('submit') ?? true);
        return Response::created(RequestService::create($request->all(), $submit));
    }

    public function update(Request $request): Response
    {
        return Response::ok(RequestService::update($request->id(), $request->all()));
    }

    public function submit(Request $request): Response
    {
        return Response::ok(RequestService::submit($request->id()));
    }

    public function cancel(Request $request): Response
    {
        return Response::ok(RequestService::cancel($request->id(), (string) $request->input('reason', '')));
    }

    public function approve(Request $request): Response
    {
        return Response::ok(RequestService::approve($request->id(), $request->all()));
    }

    public function reject(Request $request): Response
    {
        return Response::ok(RequestService::reject($request->id(), $request->all()));
    }

    public function startPicking(Request $request): Response
    {
        return Response::ok(RequestService::startPicking($request->id()));
    }

    public function ready(Request $request): Response
    {
        return Response::ok(RequestService::markReady($request->id()));
    }

    public function deliver(Request $request): Response
    {
        return Response::created(\App\Services\DeliveryService::deliverRequest($request->id(), $request->all()));
    }

    public function checkAvailability(Request $request): Response
    {
        $items = $request->input('items');
        if (!is_array($items)) {
            throw \App\Core\HttpException::validation(['items' => 'Informe os itens.']);
        }
        return Response::ok(RequestService::checkAvailability($items));
    }
}
