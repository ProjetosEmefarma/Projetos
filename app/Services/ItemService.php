<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Validator;
use App\Support\Present;
use PDOException;
use Throwable;

/**
 * Items (brindes): register, status, trash, permanent deletion and photo.
 * Stock quantities are NEVER edited here - only through StockService.
 */
final class ItemService
{
    public const SELECT = 'SELECT i.*, c.name AS category_name, l.name AS location_name, sp.name AS supplier_name,
            s.qty_on_hand, s.qty_reserved';

    public const FROM = 'FROM items i
       LEFT JOIN stock s ON s.item_id = i.id
       LEFT JOIN categories c ON c.id = i.category_id
       LEFT JOIN locations l ON l.id = i.location_id
       LEFT JOIN suppliers sp ON sp.id = i.supplier_id';

    private const REFERENCES = [
        ['stock_movements', 'item_id', 'movimentação(ões) de estoque'],
        ['request_items', 'item_id', 'solicitação(ões)'],
        ['delivery_items', 'item_id', 'protocolo(s) de entrega'],
        ['event_allocations', 'item_id', 'cota(s) de evento'],
    ];

    public static function rules(bool $update): array
    {
        $req = $update ? 'sometimes|required' : 'required';
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/'],
            'name' => "{$req}|string|max:150",
            'description' => 'sometimes|nullable|string|max:5000',
            'category_id' => "{$req}|int|exists:categories",
            'location_id' => 'sometimes|nullable|int|exists:locations',
            'supplier_id' => 'sometimes|nullable|int|exists:suppliers',
            'unit_value' => 'sometimes|nullable|numeric|min:0|max:9999999999',
            'min_stock' => 'sometimes|nullable|int|min:0|max:1000000',
            'status' => 'sometimes|required|in:ativo,inativo',
            'entry_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:5000',
            'kind' => 'sometimes|nullable|in:fisico,voucher,cartao,outro',
        ];
    }

    public static function find(int $id, bool $withTrashed = false): array
    {
        $row = Db::fetch(
            self::SELECT . ' ' . self::FROM . ' WHERE i.id = ?' . ($withTrashed ? '' : ' AND i.deleted_at IS NULL'),
            [$id]
        );
        if ($row === null) {
            throw HttpException::notFound('Brinde não encontrado.');
        }
        return Present::item($row);
    }

    public static function create(array $input): array
    {
        $data = Validator::validate($input, self::rules(false));
        $initial = (int) (Validator::validate($input, ['initial_quantity' => 'sometimes|nullable|int|min:0|max:1000000'])['initial_quantity'] ?? 0);
        $data['code'] = self::normalizeCode($data['code'] ?? null);
        $data['min_stock'] = $data['min_stock'] ?? 0;
        $data['status'] = $data['status'] ?? 'ativo';

        if ($initial > 0 && !Auth::can('stock.entry')) {
            throw HttpException::forbidden('Você não tem permissão para lançar saldo inicial.');
        }
        if ($initial > 0 && $data['status'] === 'inativo') {
            throw HttpException::validation(['initial_quantity' => 'Não é possível lançar saldo inicial em um brinde inativo.']);
        }

        return Db::transaction(function () use ($data, $initial) {
            if ($data['code'] === null) {
                $data['code'] = self::generateCode();
            } else {
                self::assertCodeFree($data['code']);
            }
            $now = now();
            try {
                $id = Db::insert('items', $data + [
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $e) {
                if (Db::isDuplicateKey($e)) {
                    throw HttpException::validation(['code' => 'Já existe um brinde com este código.']);
                }
                throw $e;
            }
            Db::insert('stock', ['item_id' => $id, 'qty_on_hand' => 0, 'qty_reserved' => 0]);
            Audit::log('create', 'item', $id, null, $data, $data['code'] . ' - ' . $data['name']);

            if ($initial > 0) {
                StockService::entry($id, $initial, ['reason' => 'Saldo inicial', 'notes' => 'Saldo inicial informado no cadastro do brinde.']);
            }
            return self::find($id);
        });
    }

    public static function update(int $id, array $input): array
    {
        $data = Validator::validate($input, self::rules(true));

        return Db::transaction(function () use ($id, $data) {
            $before = self::lockRow($id);
            if (array_key_exists('code', $data)) {
                $code = self::normalizeCode($data['code']);
                if ($code === null) {
                    unset($data['code']);
                } else {
                    self::assertCodeFree($code, $id);
                    $data['code'] = $code;
                }
            }
            if (array_key_exists('min_stock', $data) && $data['min_stock'] === null) {
                $data['min_stock'] = 0;
            }
            if (($data['status'] ?? null) === 'inativo' && $before['status'] !== 'inativo') {
                self::assertNoReservations($id);
            }
            if ($data === []) {
                return self::find($id);
            }
            Db::update('items', $data + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            Audit::logUpdate('item', $id, $before, $data, ($data['code'] ?? $before['code']) . ' - ' . ($data['name'] ?? $before['name']));
            return self::find($id);
        });
    }

    public static function setStatus(int $id, string $status): array
    {
        return Db::transaction(function () use ($id, $status) {
            $before = self::lockRow($id);
            if ($before['status'] === $status) {
                return self::find($id);
            }
            if ($status === 'inativo') {
                self::assertNoReservations($id);
            }
            Db::update('items', ['status' => $status, 'updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            Audit::log(
                $status === 'ativo' ? 'activate' : 'deactivate',
                'item',
                $id,
                ['status' => $before['status']],
                ['status' => $status],
                $before['code'] . ' - ' . $before['name']
            );
            return self::find($id);
        });
    }

    /** "Excluir" = send to trash. Blocked while the item still has stock or reservations. */
    public static function softDelete(int $id): void
    {
        Db::transaction(function () use ($id) {
            $before = self::lockRow($id);
            $stock = StockService::snapshot($id);
            if ($stock['reserved'] > 0) {
                throw HttpException::conflict(
                    'ITEM_HAS_RESERVATIONS',
                    "Este brinde tem {$stock['reserved']} unidade(s) reservada(s) em solicitações ou eventos. Conclua ou cancele-as antes de excluir."
                );
            }
            if ($stock['on_hand'] > 0) {
                throw HttpException::conflict(
                    'ITEM_HAS_STOCK',
                    "Este brinde ainda tem {$stock['on_hand']} unidade(s) em estoque. Faça um ajuste para zerar o saldo ou apenas inative o brinde."
                );
            }
            Trash::softDelete('items', $id, 'item', self::snapshotForAudit($before), $before['code'] . ' - ' . $before['name']);
        });
    }

    public static function restore(int $id): array
    {
        return Db::transaction(function () use ($id) {
            $row = Db::fetch('SELECT * FROM items WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw HttpException::notFound('Brinde não encontrado.');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Este brinde não está na lixeira.');
            }
            Trash::restore('items', $id, 'item', $row['code'] . ' - ' . $row['name']);
            return self::find($id);
        });
    }

    /**
     * Permanent deletion: only from the trash, only when the item was never used,
     * and only with the typed confirmation (its code). A snapshot stays in the audit log.
     */
    public static function purge(int $id, mixed $confirm): void
    {
        $files = Db::transaction(function () use ($id, $confirm) {
            $row = Db::fetch('SELECT * FROM items WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw HttpException::notFound('Brinde não encontrado.');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Envie o brinde para a lixeira antes de excluir definitivamente.');
            }
            Trash::assertConfirmed($confirm, $row['code']);
            Trash::assertUnused($id, self::REFERENCES, 'o brinde');
            if ((int) Db::value('SELECT qty_on_hand + qty_reserved FROM stock WHERE item_id = ?', [$id]) > 0) {
                throw HttpException::conflict('ITEM_HAS_STOCK', 'O brinde ainda possui saldo em estoque.');
            }
            Db::query('DELETE FROM stock WHERE item_id = ?', [$id]);
            Db::query('DELETE FROM items WHERE id = ?', [$id]);
            Audit::log('purge', 'item', $id, self::snapshotForAudit($row), null, $row['code'] . ' - ' . $row['name']);
            return [$row['photo_path'], $row['thumb_path']];
        });
        ImageService::delete(...$files);
    }

    public static function setPhoto(int $id, array $file): array
    {
        self::find($id); // 404 before processing the upload
        $paths = ImageService::storeItemPhoto($file);
        try {
            $old = Db::transaction(function () use ($id, $paths) {
                $row = self::lockRow($id);
                Db::update('items', $paths + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
                Audit::log('photo_update', 'item', $id, null, null, $row['code'] . ' - ' . $row['name']);
                return [$row['photo_path'], $row['thumb_path']];
            });
        } catch (Throwable $e) {
            ImageService::delete($paths['photo_path'], $paths['thumb_path']);
            throw $e;
        }
        ImageService::delete(...$old);
        return self::find($id);
    }

    public static function removePhoto(int $id): array
    {
        $old = Db::transaction(function () use ($id) {
            $row = self::lockRow($id);
            if ($row['photo_path'] === null) {
                return [null, null];
            }
            Db::update('items', ['photo_path' => null, 'thumb_path' => null, 'updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            Audit::log('photo_delete', 'item', $id, null, null, $row['code'] . ' - ' . $row['name']);
            return [$row['photo_path'], $row['thumb_path']];
        });
        ImageService::delete(...$old);
        return self::find($id);
    }

    /** Preview of the next automatic code (the real one is assigned on save). */
    public static function previewCode(): string
    {
        $n = (int) (Db::value("SELECT last_value FROM sequences WHERE name = 'item' AND period = 'all'") ?? 0);
        do {
            $code = sprintf('BRD-%05d', ++$n);
        } while (Db::value('SELECT 1 FROM items WHERE code = ?', [$code]) !== null);
        return $code;
    }

    // -----------------------------------------------------------------

    private static function generateCode(): string
    {
        do {
            $code = sprintf('BRD-%05d', Sequence::next('item'));
        } while (Db::value('SELECT 1 FROM items WHERE code = ?', [$code]) !== null);
        return $code;
    }

    private static function normalizeCode(mixed $code): ?string
    {
        if (!is_string($code) || trim($code) === '') {
            return null;
        }
        return mb_strtoupper(trim($code));
    }

    private static function assertCodeFree(string $code, ?int $ignoreId = null): void
    {
        $row = Db::fetch('SELECT id, deleted_at FROM items WHERE code = ? AND id <> ?', [$code, $ignoreId ?? 0]);
        if ($row !== null) {
            throw HttpException::validation(['code' => $row['deleted_at'] !== null
                ? 'Já existe um brinde com este código na lixeira. Restaure-o ou use outro código.'
                : 'Já existe um brinde com este código.']);
        }
    }

    private static function assertNoReservations(int $id): void
    {
        $reserved = (int) Db::value('SELECT qty_reserved FROM stock WHERE item_id = ?', [$id]);
        if ($reserved > 0) {
            throw HttpException::conflict(
                'ITEM_HAS_RESERVATIONS',
                "Este brinde tem {$reserved} unidade(s) reservada(s). Conclua ou cancele as solicitações/eventos antes de inativá-lo."
            );
        }
    }

    private static function lockRow(int $id): array
    {
        $row = Db::fetch('SELECT * FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Brinde não encontrado.');
        }
        return $row;
    }

    private static function snapshotForAudit(array $row): array
    {
        unset($row['created_by'], $row['updated_by'], $row['deleted_by']);
        return $row;
    }
}
