<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\EventService;

final class EventsController
{
    public function index(Request $request): Response
    {
        [$rows, $meta] = EventService::list($request);
        return Response::ok($rows, $meta);
    }

    public function show(Request $request): Response
    {
        return Response::ok(EventService::find($request->id()));
    }

    public function store(Request $request): Response
    {
        return Response::created(EventService::create($request->all()));
    }

    public function update(Request $request): Response
    {
        return Response::ok(EventService::update($request->id(), $request->all()));
    }

    public function allocations(Request $request): Response
    {
        $rows = $request->input('allocations') ?? $request->all();
        if (!is_array($rows) || isset($rows['allocations'])) {
            $rows = $request->input('allocations', []);
        }
        return Response::ok(EventService::saveAllocations($request->id(), is_array($rows) ? $rows : []));
    }

    public function open(Request $request): Response
    {
        return Response::ok(EventService::open($request->id()));
    }

    public function close(Request $request): Response
    {
        return Response::ok(EventService::close($request->id()));
    }

    public function returnToCd(Request $request): Response
    {
        return Response::ok(EventService::returnToCd($request->id(), $request->all()));
    }

    public function withdraw(Request $request): Response
    {
        return Response::created(EventService::withdraw($request->id(), $request->all()));
    }

    public function balance(Request $request): Response
    {
        $industryId = $request->queryInt('industry_id');
        if (!$industryId) {
            throw \App\Core\HttpException::validation(['industry_id' => 'Informe a indústria.']);
        }
        return Response::ok(EventService::industryBalance($request->id(), $industryId));
    }
}
