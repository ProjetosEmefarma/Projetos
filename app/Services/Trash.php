<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;

/**
 * Soft delete ("Excluir" = lixeira), restore and the guard for permanent deletion.
 * History is never lost: soft-deleted rows stay in reports and audit.
 */
final class Trash
{
    public static function softDelete(string $table, int $id, string $entity, array $before, string $label): void
    {
        Db::update($table, ['deleted_at' => now(), 'deleted_by' => Auth::id()], ['id' => $id]);
        Audit::log('delete', $entity, $id, $before, null, $label);
    }

    public static function restore(string $table, int $id, string $entity, string $label): void
    {
        Db::update($table, ['deleted_at' => null, 'deleted_by' => null], ['id' => $id]);
        Audit::log('restore', $entity, $id, null, null, $label);
    }

    /**
     * Blocks permanent deletion while the record is referenced anywhere.
     * @param array<array{0:string,1:string,2:string}> $references [table, column, label]
     */
    public static function assertUnused(int $id, array $references, string $what): void
    {
        $uses = [];
        foreach ($references as [$table, $column, $label]) {
            $count = (int) Db::value("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?", [$id]);
            if ($count > 0) {
                $uses[] = "{$count} {$label}";
            }
        }
        if ($uses !== []) {
            throw HttpException::conflict(
                'IN_USE',
                "Não é possível excluir definitivamente: {$what} está em uso (" . implode(', ', $uses) . '). Use "Inativar" ou "Excluir" (lixeira).',
                ['uses' => $uses]
            );
        }
    }

    /** Typed confirmation for permanent deletion (e.g. the item code). */
    public static function assertConfirmed(mixed $confirm, string $expected): void
    {
        if (!is_string($confirm) || mb_strtolower(trim($confirm)) !== mb_strtolower($expected)) {
            throw HttpException::validation(
                ['confirm' => "Digite \"{$expected}\" para confirmar a exclusão definitiva."],
                'Confirmação obrigatória.'
            );
        }
    }
}
