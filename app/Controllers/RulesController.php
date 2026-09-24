<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\Trash;

final class RulesController
{
    public function index(Request $request): Response
    {
        $rows = Db::fetchAll(
            'SELECT r.*, ro.name AS role_name, u.name AS user_name
               FROM approval_rules r
          LEFT JOIN roles ro ON ro.id = r.approver_role_id
          LEFT JOIN users u ON u.id = r.approver_user_id
              WHERE r.deleted_at IS NULL ORDER BY r.priority ASC, r.id ASC'
        );
        return Response::ok(array_map([self::class, 'present'], $rows));
    }

    public function store(Request $request): Response
    {
        $data = self::validate($request->all(), false);
        $id = Db::transaction(function () use ($data) {
            $id = Db::insert('approval_rules', $data + [
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::log('create', 'approval_rule', $id, null, $data, $data['name']);
            return $id;
        });
        return Response::created($this->one($id));
    }

    public function update(Request $request): Response
    {
        $id = $request->id();
        $data = self::validate($request->all(), true);
        Db::transaction(function () use ($id, $data) {
            $before = Db::fetch('SELECT * FROM approval_rules WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$before) {
                throw HttpException::notFound('Regra não encontrada.');
            }
            if ($data !== []) {
                Db::update('approval_rules', $data + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
                Audit::logUpdate('approval_rule', $id, $before, $data, $data['name'] ?? $before['name']);
            }
        });
        return Response::ok($this->one($id));
    }

    public function destroy(Request $request): Response
    {
        $id = $request->id();
        Db::transaction(function () use ($id) {
            $row = Db::fetch('SELECT * FROM approval_rules WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$row) {
                throw HttpException::notFound('Regra não encontrada.');
            }
            Trash::softDelete('approval_rules', $id, 'approval_rule', $row, $row['name']);
        });
        return Response::ok(['id' => $id, 'deleted' => true]);
    }

    private function one(int $id): array
    {
        $row = Db::fetch(
            'SELECT r.*, ro.name AS role_name, u.name AS user_name
               FROM approval_rules r
          LEFT JOIN roles ro ON ro.id = r.approver_role_id
          LEFT JOIN users u ON u.id = r.approver_user_id WHERE r.id = ?',
            [$id]
        );
        return self::present($row);
    }

    private static function validate(array $input, bool $update): array
    {
        $req = $update ? 'sometimes|required' : 'required';
        $data = Validator::validate($input, [
            'name' => "{$req}|string|max:120",
            'criterion' => "{$req}|in:quantidade,valor,categoria,departamento,perfil",
            'operator' => 'sometimes|required|in:>,>=,=,in',
            'value' => "{$req}|string|max:255",
            'approver_role_id' => 'sometimes|nullable|int|exists:roles',
            'approver_user_id' => 'sometimes|nullable|int|exists:users',
            'priority' => 'sometimes|nullable|int|min:1|max:9999',
            'active' => 'sometimes|bool',
        ]);
        if (isset($data['active'])) {
            $data['active'] = (int) $data['active'];
        }
        if (isset($data['priority']) && $data['priority'] === null) {
            $data['priority'] = 100;
        }
        if (!$update && empty($data['approver_role_id']) && empty($data['approver_user_id'])) {
            throw HttpException::validation(['approver_role_id' => 'Informe o perfil ou o usuário aprovador.']);
        }
        return $data;
    }

    private static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'criterion' => $r['criterion'],
            'operator' => $r['operator'],
            'value' => $r['value'],
            'approver_role' => $r['approver_role_id'] ? ['id' => (int) $r['approver_role_id'], 'name' => $r['role_name']] : null,
            'approver_user' => $r['approver_user_id'] ? ['id' => (int) $r['approver_user_id'], 'name' => $r['user_name']] : null,
            'priority' => (int) $r['priority'],
            'active' => (bool) $r['active'],
        ];
    }
}
