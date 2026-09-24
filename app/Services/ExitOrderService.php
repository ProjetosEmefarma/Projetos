<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Support\Present;

/**
 * Gestor authorizes a stock exit; CD/Estoque confirms it (QR + execute).
 * Stock is reserved on authorize and written off only on confirm.
 */
final class ExitOrderService
{
    public const STATUS_LABELS = [
        'autorizada' => 'Autorizada — aguardando CD',
        'confirmada' => 'Confirmada',
        'cancelada' => 'Cancelada',
    ];

    private const SELECT = 'SELECT o.*, i.code AS item_code, i.name AS item_name,
            au.name AS authorized_by_name, cu.name AS confirmed_by_name,
            d.name AS department_name, ind.name AS industry_name';

    private const FROM = 'FROM stock_exit_orders o
            JOIN items i ON i.id = o.item_id
            JOIN users au ON au.id = o.authorized_by
       LEFT JOIN users cu ON cu.id = o.confirmed_by
       LEFT JOIN departments d ON d.id = o.department_id
       LEFT JOIN industries ind ON ind.id = o.industry_id';

    public static function authorize(int $itemId, int $qty, array $meta = []): array
    {
        Auth::authorize('stock.exit');
        if (Auth::isCdOperations()) {
            throw HttpException::forbidden('A saída é autorizada pelo gestor. O CD só confirma.');
        }
        if ($qty <= 0) {
            throw HttpException::validation(['quantity' => 'A quantidade deve ser maior que zero.']);
        }

        return Db::transaction(function () use ($itemId, $qty, $meta) {
            StockService::reserve($itemId, $qty, 'saida autorizada');
            $code = QrService::nextExitCode();
            $id = Db::insert('stock_exit_orders', [
                'code' => $code,
                'item_id' => $itemId,
                'qty' => $qty,
                'purpose' => $meta['purpose'] ?? '',
                'recipient' => $meta['recipient'] ?? null,
                'industry_id' => $meta['industry_id'] ?? null,
                'department_id' => $meta['department_id'] ?? null,
                'requester_id' => $meta['requester_id'] ?? null,
                'document_ref' => $meta['document_ref'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'attachment_path' => $meta['attachment_path'] ?? null,
                'status' => 'autorizada',
                'authorized_by' => Auth::id(),
                'authorized_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::log('stock_exit_authorize', 'stock_exit_order', $id, null, [
                'code' => $code, 'item_id' => $itemId, 'qty' => $qty,
            ], $code);
            return self::payload($id);
        });
    }

    public static function confirm(int $id): array
    {
        Auth::authorize(['stock.exit_confirm', 'stock.exit']);
        return Db::transaction(function () use ($id) {
            $row = self::lock($id);
            if ($row['status'] !== 'autorizada') {
                throw HttpException::conflict('EXIT_NOT_PENDING', 'Esta saída já foi confirmada ou cancelada.');
            }
            $result = StockService::consumeReserved((int) $row['item_id'], (int) $row['qty'], [
                'purpose' => $row['purpose'],
                'recipient' => $row['recipient'],
                'industry_id' => $row['industry_id'],
                'department_id' => $row['department_id'],
                'requester_id' => $row['requester_id'],
                'document_ref' => $row['document_ref'],
                'notes' => $row['notes'],
                'attachment_path' => $row['attachment_path'],
            ]);
            $movementId = (int) ($result['movement']['id'] ?? 0);
            Db::update('stock_exit_orders', [
                'status' => 'confirmada',
                'confirmed_by' => Auth::id(),
                'confirmed_at' => now(),
                'movement_id' => $movementId ?: null,
                'updated_at' => now(),
            ], ['id' => $id]);
            Audit::log('stock_exit_confirm', 'stock_exit_order', $id, ['status' => 'autorizada'], [
                'status' => 'confirmada', 'movement_id' => $movementId,
            ], $row['code']);
            return self::payload($id);
        });
    }

    public static function cancel(int $id, string $reason = ''): array
    {
        Auth::authorize('stock.exit');
        return Db::transaction(function () use ($id, $reason) {
            $row = self::lock($id);
            if ($row['status'] !== 'autorizada') {
                throw HttpException::conflict('EXIT_NOT_PENDING', 'Só é possível cancelar uma saída ainda não confirmada.');
            }
            StockService::release((int) $row['item_id'], (int) $row['qty'], 'saida cancelada');
            Db::update('stock_exit_orders', [
                'status' => 'cancelada',
                'notes' => trim((string) $row['notes'] . ($reason !== '' ? "\nCancelada: {$reason}" : '')),
                'updated_at' => now(),
            ], ['id' => $id]);
            return self::payload($id);
        });
    }

    public static function find(int $id): array
    {
        $row = Db::fetch(self::SELECT . ' ' . self::FROM . ' WHERE o.id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Saída autorizada não encontrada.');
        }
        return Present::exitOrder($row);
    }

    public static function lookup(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw HttpException::validation(['code' => 'Informe o código da saída.']);
        }
        $row = Db::fetch(self::SELECT . ' ' . self::FROM . ' WHERE o.code = ?', [$code]);
        if ($row === null) {
            throw HttpException::notFound('Nenhuma saída com este QR / código.');
        }
        return Present::exitOrder($row);
    }

    /** @return list<array<string,mixed>> */
    public static function list(?string $status = 'autorizada'): array
    {
        $where = '';
        $params = [];
        if ($status !== null && $status !== '' && $status !== 'todas') {
            $where = ' WHERE o.status = ?';
            $params[] = $status;
        }
        $rows = Db::fetchAll(
            self::SELECT . ' ' . self::FROM . $where . ' ORDER BY o.id DESC LIMIT 100',
            $params
        );
        return array_map([Present::class, 'exitOrder'], $rows);
    }

    public static function qrPng(int $id): string
    {
        $row = Db::fetch('SELECT code FROM stock_exit_orders WHERE id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Saída autorizada não encontrada.');
        }
        return QrService::png((string) $row['code'], 6);
    }

    public static function attachment(int $id): array
    {
        $row = Db::fetch('SELECT code, attachment_path, document_ref FROM stock_exit_orders WHERE id = ?', [$id]);
        if ($row === null || empty($row['attachment_path'])) {
            throw HttpException::notFound('Não há nota anexada nesta saída.');
        }
        return $row;
    }

    private static function lock(int $id): array
    {
        $row = Db::fetch('SELECT * FROM stock_exit_orders WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Saída autorizada não encontrada.');
        }
        return $row;
    }

    private static function payload(int $id): array
    {
        $order = self::find($id);
        return [
            'order' => $order,
            'stock' => StockService::snapshot((int) $order['item']['id']),
        ];
    }
}
