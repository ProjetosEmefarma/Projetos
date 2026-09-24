<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\StockService;

/**
 * Converts DB rows into the JSON shapes documented in docs/api-contract.md.
 * Never exposes password hashes or internal file paths.
 */
final class Present
{
    public static function money(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }

    public static function user(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'phone' => $u['phone'] ?? null,
            'role' => [
                'id' => (int) $u['role_id'],
                'slug' => $u['role_slug'] ?? null,
                'name' => $u['role_name'] ?? null,
            ],
            'department' => !empty($u['department_id'])
                ? ['id' => (int) $u['department_id'], 'name' => $u['department_name'] ?? null]
                : null,
            'industry' => !empty($u['industry_id'])
                ? ['id' => (int) $u['industry_id'], 'name' => $u['industry_name'] ?? null]
                : null,
            'active' => (bool) $u['active'],
            'must_change_password' => (bool) $u['must_change_password'],
            'last_login_at' => $u['last_login_at'] ?? null,
            'created_at' => $u['created_at'] ?? null,
            'updated_at' => $u['updated_at'] ?? null,
            'deleted_at' => $u['deleted_at'] ?? null,
        ];
    }

    /** Expects the columns selected by ItemService::SELECT. */
    public static function item(array $row): array
    {
        $onHand = (int) ($row['qty_on_hand'] ?? 0);
        $reserved = (int) ($row['qty_reserved'] ?? 0);
        $available = $onHand - $reserved;
        $min = (int) $row['min_stock'];
        $level = StockService::level($available, $min);
        $unit = self::money($row['unit_value']);
        $version = $row['photo_path'] ? substr(md5((string) $row['photo_path']), 0, 8) : null;

        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category_id'] ? ['id' => (int) $row['category_id'], 'name' => $row['category_name'] ?? null] : null,
            'location' => $row['location_id'] ? ['id' => (int) $row['location_id'], 'name' => $row['location_name'] ?? null] : null,
            'supplier' => $row['supplier_id'] ? ['id' => (int) $row['supplier_id'], 'name' => $row['supplier_name'] ?? null] : null,
            'unit_value' => $unit,
            'min_stock' => $min,
            'status' => $row['status'],
            'entry_date' => $row['entry_date'],
            'notes' => $row['notes'],
            'kind' => $row['kind'] ?? 'fisico',
            'kind_label' => \App\Services\TradeService::KIND_LABELS[$row['kind'] ?? 'fisico'] ?? 'Produto físico',
            'photo_url' => $version ? url('/api/items/' . $row['id'] . '/photo') . '?v=' . $version : null,
            'thumb_url' => $version ? url('/api/items/' . $row['id'] . '/photo') . '?size=thumb&v=' . $version : null,
            'stock' => [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'level' => $level,
                'level_label' => StockService::LEVEL_LABELS[$level],
            ],
            'stock_value' => $unit !== null ? round($onHand * $unit, 2) : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'deleted_at' => $row['deleted_at'],
        ];
    }

    /** Expects the columns selected by StockService::MOVEMENT_SELECT. */
    public static function movement(array $m): array
    {
        $ref = fn (string $prefix) => !empty($m[$prefix . '_id'])
            ? ['id' => (int) $m[$prefix . '_id'], 'name' => $m[$prefix . '_name'] ?? null]
            : null;
        $unit = self::money($m['unit_value']);

        return [
            'id' => (int) $m['id'],
            'type' => $m['type'],
            'type_label' => StockService::TYPE_LABELS[$m['type']] ?? $m['type'],
            'quantity' => (int) $m['qty'],
            'balance_after' => (int) $m['balance_after'],
            'unit_value' => $unit,
            'total_value' => $unit !== null ? round(abs((int) $m['qty']) * $unit, 2) : null,
            'item' => ['id' => (int) $m['item_id'], 'code' => $m['item_code'] ?? null, 'name' => $m['item_name'] ?? null],
            'user' => ['id' => (int) $m['user_id'], 'name' => $m['user_name'] ?? null],
            'requester' => $ref('requester'),
            'department' => $ref('department'),
            'industry' => $ref('industry'),
            'supplier' => $ref('supplier'),
            'recipient' => $m['recipient'],
            'purpose' => $m['purpose'],
            'purchase_ticket_no' => $m['purchase_ticket_no'],
            'document_ref' => $m['document_ref'],
            'reason' => $m['reason'],
            'notes' => $m['notes'],
            'attachment_url' => !empty($m['attachment_path']) ? url('/api/stock/movements/' . $m['id'] . '/attachment') : null,
            'request_id' => $m['request_id'] !== null ? (int) $m['request_id'] : null,
            'delivery_id' => $m['delivery_id'] !== null ? (int) $m['delivery_id'] : null,
            'event_id' => $m['event_id'] !== null ? (int) $m['event_id'] : null,
            'created_at' => $m['created_at'],
        ];
    }

    public static function exitOrder(array $o): array
    {
        $code = (string) $o['code'];
        return [
            'id' => (int) $o['id'],
            'code' => $code,
            'status' => $o['status'],
            'status_label' => \App\Services\ExitOrderService::STATUS_LABELS[$o['status']] ?? $o['status'],
            'qty' => (int) $o['qty'],
            'purpose' => $o['purpose'],
            'recipient' => $o['recipient'] ?? null,
            'document_ref' => $o['document_ref'] ?? null,
            'notes' => $o['notes'] ?? null,
            'item' => [
                'id' => (int) $o['item_id'],
                'code' => $o['item_code'] ?? null,
                'name' => $o['item_name'] ?? null,
            ],
            'industry' => !empty($o['industry_id'])
                ? ['id' => (int) $o['industry_id'], 'name' => $o['industry_name'] ?? null]
                : null,
            'department' => !empty($o['department_id'])
                ? ['id' => (int) $o['department_id'], 'name' => $o['department_name'] ?? null]
                : null,
            'authorized_by' => ['id' => (int) $o['authorized_by'], 'name' => $o['authorized_by_name'] ?? null],
            'authorized_at' => $o['authorized_at'] ?? null,
            'confirmed_by' => !empty($o['confirmed_by'])
                ? ['id' => (int) $o['confirmed_by'], 'name' => $o['confirmed_by_name'] ?? null]
                : null,
            'confirmed_at' => $o['confirmed_at'] ?? null,
            'movement_id' => !empty($o['movement_id']) ? (int) $o['movement_id'] : null,
            'qr_png' => \App\Services\QrService::pngDataUri($code, 5),
            'qr_url' => url('/api/stock/exit-orders/' . $o['id'] . '/qr'),
            'attachment_url' => !empty($o['attachment_path']) ? url('/api/stock/exit-orders/' . $o['id'] . '/attachment') : null,
            'created_at' => $o['created_at'] ?? null,
        ];
    }

    public static function lookup(array $row, array $fields): array
    {
        $out = ['id' => (int) $row['id']];
        foreach (array_keys($fields) as $field) {
            $out[$field] = $row[$field] ?? null;
        }
        $out['active'] = (bool) $row['active'];
        $out['created_at'] = $row['created_at'];
        $out['updated_at'] = $row['updated_at'];
        $out['deleted_at'] = $row['deleted_at'];
        return $out;
    }

    public static function audit(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'action' => $a['action'],
            'action_label' => AuditLabels::action($a['action']),
            'entity_type' => $a['entity_type'],
            'entity_label' => AuditLabels::entity($a['entity_type']),
            'entity_id' => $a['entity_id'] !== null ? (int) $a['entity_id'] : null,
            'entity_name' => $a['entity_label'],
            'user' => ['id' => $a['user_id'] !== null ? (int) $a['user_id'] : null, 'name' => $a['user_name']],
            'before' => $a['before_json'] ? json_decode($a['before_json'], true) : null,
            'after' => $a['after_json'] ? json_decode($a['after_json'], true) : null,
            'ip' => $a['ip'],
            'user_agent' => $a['user_agent'],
            'created_at' => $a['created_at'],
        ];
    }
}
