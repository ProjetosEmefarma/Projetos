<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Support\Present;

/**
 * The only place that changes stock balances.
 *
 * Rules:
 *  - every change runs in a transaction and locks the item's stock row (SELECT ... FOR UPDATE)
 *  - available = on_hand - reserved; a manual exit can never take reserved units
 *  - on_hand can never go below 0 or below what is reserved (also enforced by DB CHECK)
 *  - every change writes an immutable stock_movements row (balance_after) + audit_log
 *  - errors are corrected with a new adjustment, never by editing/deleting a movement
 */
final class StockService
{
    public const TYPE_LABELS = [
        'entrada' => 'Entrada',
        'saida' => 'Saída',
        'ajuste' => 'Ajuste',
        'transferencia' => 'Transferência',
        'estorno' => 'Estorno / correção',
    ];
    public const LEVEL_LABELS = ['ok' => 'Disponível', 'low' => 'Estoque baixo', 'zero' => 'Sem estoque'];

    /** Columns accepted in $meta and copied to the movement row. */
    private const META_FIELDS = [
        'unit_value', 'requester_id', 'department_id', 'industry_id', 'supplier_id', 'recipient', 'purpose',
        'purchase_ticket_no', 'document_ref', 'reason', 'notes', 'attachment_path', 'request_id', 'delivery_id', 'event_id',
        'from_location_id', 'to_location_id',
    ];

    public const MOVEMENT_SELECT = 'SELECT m.*, i.code AS item_code, i.name AS item_name, u.name AS user_name,
            rq.name AS requester_name, d.name AS department_name, ind.name AS industry_name, s.name AS supplier_name';

    public const MOVEMENT_FROM = 'FROM stock_movements m
            JOIN items i ON i.id = m.item_id
            JOIN users u ON u.id = m.user_id
       LEFT JOIN users rq ON rq.id = m.requester_id
       LEFT JOIN departments d ON d.id = m.department_id
       LEFT JOIN industries ind ON ind.id = m.industry_id
       LEFT JOIN suppliers s ON s.id = m.supplier_id';

    public static function level(int $available, int $minStock): string
    {
        if ($available <= 0) {
            return 'zero';
        }
        return $minStock > 0 && $available <= $minStock ? 'low' : 'ok';
    }

    /** Entrada: receiving / new units. */
    public static function entry(int $itemId, int $qty, array $meta = []): array
    {
        self::assertPositive($qty);
        return Db::transaction(function () use ($itemId, $qty, $meta) {
            $item = self::lock($itemId);
            self::assertActive($item);
            if ($item['entry_date'] === null) {
                Db::update('items', ['entry_date' => today()], ['id' => $itemId]);
            }
            $meta['location_id'] ??= self::defaultLocationId($item);
            return self::record($item, 'entrada', $qty, (int) $item['qty_reserved'], $meta);
        });
    }

    /** Saída manual: only from the available balance (never from reserved units). */
    public static function exit(int $itemId, int $qty, array $meta = []): array
    {
        self::assertPositive($qty);
        return Db::transaction(function () use ($itemId, $qty, $meta) {
            $item = self::lock($itemId);
            self::assertActive($item);
            $available = (int) $item['qty_on_hand'] - (int) $item['qty_reserved'];
            if ($qty > $available) {
                throw self::insufficient($item, $available, $qty);
            }
            $meta['location_id'] ??= self::defaultLocationId($item);
            $atLoc = self::positionQty((int) $item['id'], (int) $meta['location_id']);
            if ($qty > $atLoc) {
                throw self::insufficient($item, $atLoc, $qty);
            }
            return self::record($item, 'saida', -$qty, (int) $item['qty_reserved'], $meta);
        });
    }

    /**
     * Ajuste: mode "set" = counted quantity (inventory count), mode "delta" = +/- units.
     * A reason is mandatory. Allowed on inactive items (to zero their stock).
     */
    public static function adjust(int $itemId, string $mode, int $quantity, string $reason, array $meta = []): array
    {
        return Db::transaction(function () use ($itemId, $mode, $quantity, $reason, $meta) {
            $item = self::lock($itemId);
            $onHand = (int) $item['qty_on_hand'];
            $reserved = (int) $item['qty_reserved'];
            $target = $mode === 'set' ? $quantity : $onHand + $quantity;

            if ($target < 0) {
                throw HttpException::rule(
                    'STOCK_INSUFFICIENT',
                    "O ajuste deixaria o estoque negativo. Saldo atual: {$onHand}.",
                    ['on_hand' => $onHand, 'available' => $onHand - $reserved]
                );
            }
            if ($target < $reserved) {
                throw HttpException::rule(
                    'STOCK_BELOW_RESERVED',
                    "Não é possível ajustar para {$target}: há {$reserved} unidade(s) reservada(s) para solicitações/eventos.",
                    ['on_hand' => $onHand, 'reserved' => $reserved]
                );
            }
            $delta = $target - $onHand;
            if ($delta === 0) {
                throw HttpException::rule('ADJUSTMENT_NO_CHANGE', "O saldo já é {$onHand}. Nenhum ajuste necessário.");
            }
            $meta['location_id'] ??= self::defaultLocationId($item);
            return self::record($item, 'ajuste', $delta, $reserved, ['reason' => $reason] + $meta);
        });
    }

    /** Reserve available units (used by approvals and event opening - Step 2). */
    public static function reserve(int $itemId, int $qty, string $context = ''): array
    {
        self::assertPositive($qty);
        return Db::transaction(function () use ($itemId, $qty, $context) {
            $item = self::lock($itemId);
            $available = (int) $item['qty_on_hand'] - (int) $item['qty_reserved'];
            if ($qty > $available) {
                throw self::insufficient($item, $available, $qty);
            }
            $reserved = (int) $item['qty_reserved'] + $qty;
            Db::update('stock', ['qty_reserved' => $reserved], ['item_id' => $itemId]);
            Audit::log('stock_reserve', 'item', $itemId, ['reserved' => (int) $item['qty_reserved']], ['reserved' => $reserved, 'context' => $context], self::label($item));
            return self::snapshot($itemId);
        });
    }

    /** Release reserved units back to available (reject / cancel / event close). */
    public static function release(int $itemId, int $qty, string $context = ''): array
    {
        self::assertPositive($qty);
        return Db::transaction(function () use ($itemId, $qty, $context) {
            $item = self::lock($itemId);
            $reserved = (int) $item['qty_reserved'] - $qty;
            if ($reserved < 0) {
                throw HttpException::rule('RESERVATION_INVALID', 'Quantidade a liberar maior que a reservada.');
            }
            Db::update('stock', ['qty_reserved' => $reserved], ['item_id' => $itemId]);
            Audit::log('stock_release', 'item', $itemId, ['reserved' => (int) $item['qty_reserved']], ['reserved' => $reserved, 'context' => $context], self::label($item));
            return self::snapshot($itemId);
        });
    }

    /** Deliver reserved units: reserved -> out (automatic write-off - Step 2). */
    public static function consumeReserved(int $itemId, int $qty, array $meta = []): array
    {
        self::assertPositive($qty);
        return Db::transaction(function () use ($itemId, $qty, $meta) {
            $item = self::lock($itemId);
            if ($qty > (int) $item['qty_reserved']) {
                throw HttpException::rule('RESERVATION_INVALID', 'Quantidade entregue maior que a reservada.');
            }
            $meta['location_id'] ??= self::defaultLocationId($item);
            return self::record($item, 'saida', -$qty, (int) $item['qty_reserved'] - $qty, $meta);
        });
    }

    public static function snapshot(int $itemId): array
    {
        $row = Db::fetch(
            'SELECT s.qty_on_hand, s.qty_reserved, i.min_stock FROM stock s JOIN items i ON i.id = s.item_id WHERE s.item_id = ?',
            [$itemId]
        );
        $onHand = (int) ($row['qty_on_hand'] ?? 0);
        $reserved = (int) ($row['qty_reserved'] ?? 0);
        $level = self::level($onHand - $reserved, (int) ($row['min_stock'] ?? 0));
        return [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $onHand - $reserved,
            'level' => $level,
            'level_label' => self::LEVEL_LABELS[$level],
        ];
    }

    public static function findMovement(int $id): array
    {
        $row = Db::fetch(self::MOVEMENT_SELECT . ' ' . self::MOVEMENT_FROM . ' WHERE m.id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Movimentação não encontrada.');
        }
        return Present::movement($row);
    }

    // -----------------------------------------------------------------

    /** Locks item + stock rows until the transaction ends. */
    private static function lock(int $itemId): array
    {
        // Items always get a stock row on creation; this only heals legacy/imported data.
        // (Plain read first: INSERT IGNORE on an existing row would take a shared lock and
        // make two concurrent FOR UPDATE calls deadlock.)
        if (Db::value('SELECT 1 FROM stock WHERE item_id = ?', [$itemId]) === null) {
            Db::query('INSERT IGNORE INTO stock (item_id) SELECT id FROM items WHERE id = ?', [$itemId]);
        }
        $item = Db::fetch(
            'SELECT i.id, i.code, i.name, i.status, i.min_stock, i.unit_value, i.entry_date, i.location_id,
                    s.qty_on_hand, s.qty_reserved
               FROM items i JOIN stock s ON s.item_id = i.id
              WHERE i.id = ? AND i.deleted_at IS NULL
                FOR UPDATE',
            [$itemId]
        );
        if ($item === null) {
            throw HttpException::notFound('Brinde não encontrado.');
        }
        self::healPositions($item);
        return $item;
    }

    /**
     * Transfer is NOT a withdrawal: total on_hand stays the same; only the location changes.
     * Two ledger rows (–qty / +qty) keep SUM(qty) = on_hand for the audit book.
     */
    public static function transfer(int $itemId, int $qty, int $fromLocationId, int $toLocationId, array $meta = []): array
    {
        self::assertPositive($qty);
        if ($fromLocationId === $toLocationId) {
            throw HttpException::validation(['to_location_id' => 'O destino deve ser diferente da origem.']);
        }
        return Db::transaction(function () use ($itemId, $qty, $fromLocationId, $toLocationId, $meta) {
            $item = self::lock($itemId);
            self::assertActive($item);
            $fromQty = self::positionQty($itemId, $fromLocationId, true);
            if ($qty > $fromQty) {
                throw HttpException::rule(
                    'STOCK_INSUFFICIENT',
                    "Não há saldo suficiente neste local para {$item['name']}: disponível {$fromQty}, solicitado {$qty}.",
                    ['available' => $fromQty, 'requested' => $qty, 'item_id' => $itemId]
                );
            }
            $available = (int) $item['qty_on_hand'] - (int) $item['qty_reserved'];
            if ($qty > $available) {
                throw self::insufficient($item, $available, $qty);
            }
            self::addPosition($itemId, $fromLocationId, -$qty);
            self::addPosition($itemId, $toLocationId, $qty);
            $meta = $meta + ['from_location_id' => $fromLocationId, 'to_location_id' => $toLocationId];
            $first = self::record($item, 'transferencia', -$qty, (int) $item['qty_reserved'], $meta, false);
            $item['qty_on_hand'] = $first['stock']['on_hand'];
            $second = self::record($item, 'transferencia', $qty, (int) $item['qty_reserved'], $meta, false);
            return [
                'movements' => [$first['movement'], $second['movement']],
                'stock' => $second['stock'],
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $qty,
            ];
        });
    }

    /** Correction: creates a new opposite movement. Original row is never edited or deleted. */
    public static function reverse(int $movementId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw HttpException::validation(['reason' => 'Informe o motivo da correção (mínimo 3 caracteres).']);
        }
        return Db::transaction(function () use ($movementId, $reason) {
            $src = Db::fetch('SELECT * FROM stock_movements WHERE id = ? FOR UPDATE', [$movementId]);
            if ($src === null) {
                throw HttpException::notFound('Movimentação não encontrada.');
            }
            if ($src['type'] === 'transferencia') {
                throw HttpException::rule('REVERSE_TRANSFER', 'Estorne uma transferência com uma nova transferência no sentido contrário.');
            }
            $item = self::lock((int) $src['item_id']);
            $qty = -(int) $src['qty'];
            $meta = [
                'reason' => $reason,
                'notes' => 'Estorno da movimentação #' . $movementId,
                'industry_id' => $src['industry_id'],
                'request_id' => $src['request_id'],
                'event_id' => $src['event_id'],
                'document_ref' => $src['document_ref'],
                'location_id' => $src['to_location_id'] ?: $src['from_location_id'] ?: self::defaultLocationId($item),
            ];
            if ($qty > 0) {
                return self::record($item, 'estorno', $qty, (int) $item['qty_reserved'], $meta);
            }
            $available = (int) $item['qty_on_hand'] - (int) $item['qty_reserved'];
            if (abs($qty) > $available) {
                throw self::insufficient($item, $available, abs($qty));
            }
            return self::record($item, 'estorno', $qty, (int) $item['qty_reserved'], $meta);
        });
    }

    public static function cdLocationId(): int
    {
        $id = Db::value("SELECT id FROM locations WHERE kind = 'cd' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        if ($id) {
            return (int) $id;
        }
        $id = Db::value('SELECT id FROM locations WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        return (int) ($id ?: 1);
    }

    public static function positions(int $itemId): array
    {
        $rows = Db::fetchAll(
            'SELECT p.location_id, p.qty, l.name, l.kind
               FROM stock_positions p JOIN locations l ON l.id = p.location_id
              WHERE p.item_id = ? AND p.qty > 0 ORDER BY l.kind, l.name',
            [$itemId]
        );
        return array_map(fn ($r) => [
            'location' => ['id' => (int) $r['location_id'], 'name' => $r['name'], 'kind' => $r['kind']],
            'qty' => (int) $r['qty'],
        ], $rows);
    }

    private static function record(array $item, string $type, int $delta, int $newReserved, array $meta, bool $changeOnHand = true): array
    {
        $itemId = (int) $item['id'];
        $before = (int) $item['qty_on_hand'];
        $after = $changeOnHand ? $before + $delta : $before;
        $locationId = isset($meta['location_id']) ? (int) $meta['location_id'] : self::defaultLocationId($item);

        if ($changeOnHand) {
        Db::update('stock', ['qty_on_hand' => $after, 'qty_reserved' => $newReserved], ['item_id' => $itemId]);
            if (in_array($type, ['entrada', 'saida', 'ajuste', 'estorno'], true)) {
                self::addPosition($itemId, $locationId, $delta);
            }
        }

        $row = [
            'item_id' => $itemId,
            'type' => $type,
            'qty' => $delta,
            'balance_after' => $after,
            'user_id' => (int) Auth::id(),
            'created_at' => now(),
        ];
        foreach (self::META_FIELDS as $field) {
            if (array_key_exists($field, $meta) && $meta[$field] !== null && $meta[$field] !== '') {
                $row[$field] = $meta[$field];
            }
        }
        $row['unit_value'] ??= $item['unit_value'];

        $movementId = Db::insert('stock_movements', $row);

        Audit::log(
            'stock_' . $type,
            'item',
            $itemId,
            ['on_hand' => $before],
            ['on_hand' => $after, 'quantity' => $delta, 'movement_id' => $movementId],
            self::label($item)
        );

        return [
            'movement' => self::findMovement($movementId),
            'stock' => self::snapshot($itemId),
        ];
    }

    private static function defaultLocationId(array $item): int
    {
        if (!empty($item['location_id'])) {
            return (int) $item['location_id'];
        }
        return self::cdLocationId();
    }

    private static function healPositions(array $item): void
    {
        $itemId = (int) $item['id'];
        $existsPos = Db::value('SELECT 1 FROM stock_positions WHERE item_id = ? LIMIT 1', [$itemId]);
        $onHand = (int) $item['qty_on_hand'];
        $locId = self::defaultLocationId($item);

        if ($existsPos === null && $onHand > 0) {
            Db::query(
                'INSERT IGNORE INTO stock_positions (item_id, location_id, qty) VALUES (?, ?, ?)',
                [$itemId, $locId, $onHand]
            );
        }

        // Heal missing stock_movements ledger entry for items with positive stock
        $existsMov = Db::value('SELECT 1 FROM stock_movements WHERE item_id = ? LIMIT 1', [$itemId]);
        if ($existsMov === null && $onHand > 0) {
            Db::insert('stock_movements', [
                'item_id' => $itemId,
                'type' => 'entrada',
                'qty' => $onHand,
                'balance_after' => $onHand,
                'user_id' => (int) (Auth::id() ?: 1),
                'location_id' => $locId,
                'reason' => 'Saldo inicial',
                'notes' => 'Saldo inicial (conciliação automática de estoque)',
                'created_at' => now(),
            ]);
        }
    }

    /** Global stock reconciliation to heal any unlinked inventory vs movements vs positions. */
    public static function reconcileAll(): array
    {
        return Db::transaction(function () {
            $items = Db::fetchAll('SELECT i.id, i.code, i.name, i.location_id, s.qty_on_hand FROM items i JOIN stock s ON s.item_id = i.id WHERE i.deleted_at IS NULL');
            $fixedMovements = 0;
            $fixedPositions = 0;

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $onHand = (int) $item['qty_on_hand'];
                if ($onHand > 0) {
                    $locId = !empty($item['location_id']) ? (int) $item['location_id'] : self::cdLocationId();
                    
                    if (Db::value('SELECT 1 FROM stock_positions WHERE item_id = ? LIMIT 1', [$itemId]) === null) {
                        Db::query('INSERT INTO stock_positions (item_id, location_id, qty) VALUES (?, ?, ?)', [$itemId, $locId, $onHand]);
                        $fixedPositions++;
                    }

                    if (Db::value('SELECT 1 FROM stock_movements WHERE item_id = ? LIMIT 1', [$itemId]) === null) {
                        Db::insert('stock_movements', [
                            'item_id' => $itemId,
                            'type' => 'entrada',
                            'qty' => $onHand,
                            'balance_after' => $onHand,
                            'user_id' => (int) (Auth::id() ?: 1),
                            'location_id' => $locId,
                            'reason' => 'Saldo inicial',
                            'notes' => 'Saldo inicial (conciliação automática de estoque)',
                            'created_at' => now(),
                        ]);
                        $fixedMovements++;
                    }
                }
            }

            return [
                'total_items' => count($items),
                'fixed_positions' => $fixedPositions,
                'fixed_movements' => $fixedMovements,
            ];
        });
    }

    private static function positionQty(int $itemId, int $locationId, bool $lock = false): int
    {
        $sql = 'SELECT qty FROM stock_positions WHERE item_id = ? AND location_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = Db::value($sql, [$itemId, $locationId]);
        return (int) ($q ?? 0);
    }

    private static function addPosition(int $itemId, int $locationId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $row = Db::fetch(
            'SELECT qty FROM stock_positions WHERE item_id = ? AND location_id = ? FOR UPDATE',
            [$itemId, $locationId]
        );
        if ($row === null && $delta < 0) {
            $row = Db::fetch(
                'SELECT location_id, qty FROM stock_positions WHERE item_id = ? AND qty >= ? ORDER BY qty DESC LIMIT 1 FOR UPDATE',
                [$itemId, -$delta]
            );
            if ($row !== null) {
                $locationId = (int) $row['location_id'];
            }
        }
        if ($row === null) {
            if ($delta < 0) {
                throw HttpException::rule(
                    'STOCK_INSUFFICIENT',
                    'Não há saldo suficiente neste local.',
                    ['item_id' => $itemId, 'available' => 0, 'requested' => -$delta]
                );
            }
            Db::query('INSERT INTO stock_positions (item_id, location_id, qty) VALUES (?, ?, ?)', [$itemId, $locationId, $delta]);
            return;
        }
        $new = (int) $row['qty'] + $delta;
        if ($new < 0) {
            throw HttpException::rule(
                'STOCK_INSUFFICIENT',
                'Não é possível realizar a retirada. Saldo disponível: ' . (int) $row['qty'] . ' unidades.',
                ['item_id' => $itemId, 'available' => (int) $row['qty'], 'requested' => -$delta]
            );
        }
        Db::update('stock_positions', ['qty' => $new], ['item_id' => $itemId, 'location_id' => $locationId]);
    }

    private static function assertPositive(int $qty): void
    {
        if ($qty <= 0) {
            throw HttpException::validation(['quantity' => 'A quantidade deve ser maior que zero.']);
        }
    }

    private static function assertActive(array $item): void
    {
        if ($item['status'] !== 'ativo') {
            throw HttpException::rule('ITEM_INACTIVE', 'Este brinde está inativo. Reative-o para movimentar o estoque.');
        }
    }

    private static function insufficient(array $item, int $available, int $requested): HttpException
    {
        return HttpException::rule(
            'STOCK_INSUFFICIENT',
            "Estoque insuficiente para {$item['name']}: disponível {$available}, solicitado {$requested}.",
            ['item_id' => (int) $item['id'], 'available' => $available, 'requested' => $requested]
        );
    }

    private static function label(array $item): string
    {
        return $item['code'] . ' - ' . $item['name'];
    }
}
