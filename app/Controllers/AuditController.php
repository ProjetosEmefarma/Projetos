<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Support\AuditLabels;
use App\Support\Present;

final class AuditController
{
    /** GET /api/audit?entity_type=&entity_id=&user_id=&action=&from=&to=&q= */
    public function index(Request $request): Response
    {
        $where = [];
        $params = [];
        if (($type = (string) $request->query('entity_type', '')) !== '') {
            $where[] = 'entity_type = ?';
            $params[] = $type;
        }
        if (($entityId = $request->queryInt('entity_id')) !== null) {
            $where[] = 'entity_id = ?';
            $params[] = $entityId;
        }
        if (($userId = $request->queryInt('user_id')) !== null) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }
        if (($action = (string) $request->query('action', '')) !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }
        if (StockController::isDate($from = (string) $request->query('from', ''))) {
            $where[] = 'created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (StockController::isDate($to = (string) $request->query('to', ''))) {
            $where[] = 'created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(entity_label LIKE ? OR user_name LIKE ?)';
            array_push($params, Db::like($q), Db::like($q));
        }

        [$rows, $meta] = Paginator::run(
            $request,
            'SELECT *',
            'FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params,
            'ORDER BY created_at DESC, id DESC',
            50
        );
        $meta['filters'] = ['actions' => AuditLabels::ACTIONS, 'entities' => AuditLabels::ENTITIES];
        return Response::ok(array_map([Present::class, 'audit'], $rows), $meta);
    }

    public function show(Request $request): Response
    {
        $row = Db::fetch('SELECT * FROM audit_log WHERE id = ?', [$request->id()]);
        if ($row === null) {
            throw HttpException::notFound('Registro de auditoria não encontrado.');
        }
        return Response::ok(Present::audit($row));
    }
}
