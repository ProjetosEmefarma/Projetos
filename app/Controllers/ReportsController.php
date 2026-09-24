<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

final class ReportsController
{
    public function types(Request $request): Response
    {
        $out = [];
        foreach (ReportService::TYPES as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }
        return Response::ok($out);
    }

    public function show(Request $request): Response
    {
        $type = $request->param('type');
        $format = (string) $request->query('format', 'json');
        if (in_array($format, ['csv', 'xlsx'], true)) {
            \App\Core\Auth::authorize('reports.export');
            return ReportService::export($type, $format, $request);
        }
        return Response::ok(ReportService::run($type, $request));
    }
}
