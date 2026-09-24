<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\Trash;
use App\Support\Present;

final class UsersController
{
    private const SELECT = 'SELECT u.*, r.slug AS role_slug, r.name AS role_name, d.name AS department_name, ind.name AS industry_name';
    private const FROM = 'FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN industries ind ON ind.id = u.industry_id';

    /** GET /api/users?q=&role_id=&department_id=&active=1|0&trashed=1 */
    public function index(Request $request): Response
    {
        [$where, $params] = [[], []];
        $where[] = $request->queryBool('trashed') ? 'u.deleted_at IS NOT NULL' : 'u.deleted_at IS NULL';
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, Db::like($q), Db::like($q));
        }
        if (($role = $request->queryInt('role_id')) !== null) {
            $where[] = 'u.role_id = ?';
            $params[] = $role;
        }
        if (($dept = $request->queryInt('department_id')) !== null) {
            $where[] = 'u.department_id = ?';
            $params[] = $dept;
        }
        if (in_array($request->query('active'), ['0', '1'], true)) {
            $where[] = 'u.active = ?';
            $params[] = (int) $request->query('active');
        }
        $order = Paginator::orderBy($request, [
            'name' => 'u.name', 'email' => 'u.email', 'last_login_at' => 'u.last_login_at', 'created_at' => 'u.created_at',
        ], 'u.name ASC');

        [$rows, $meta] = Paginator::run($request, self::SELECT, self::FROM . ' WHERE ' . implode(' AND ', $where), $params, $order);
        return Response::ok(array_map([Present::class, 'user'], $rows), $meta);
    }

    /** GET /api/users/options - minimal list of active users for selects (any logged user). */
    public function options(Request $request): Response
    {
        $rows = Db::fetchAll(
            'SELECT u.id, u.name, u.department_id, d.name AS department_name
               FROM users u LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.deleted_at IS NULL AND u.active = 1 ORDER BY u.name'
        );
        return Response::ok(array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'department' => $r['department_id'] ? ['id' => (int) $r['department_id'], 'name' => $r['department_name']] : null,
        ], $rows));
    }

    public function show(Request $request): Response
    {
        return Response::ok(Present::user($this->find($request->id(), true)));
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:190',
            'role_id' => 'required|int|exists:roles',
            'department_id' => 'nullable|int|exists:departments',
            'industry_id' => 'nullable|int|exists:industries',
            'phone' => 'nullable|string|max:30',
            'active' => 'sometimes|bool',
            'must_change_password' => 'sometimes|bool',
        ]);
        Validator::password('password', $request->input('password'));
        $this->assertEmailFree($data['email']);

        $id = Db::transaction(function () use ($data, $request) {
            $now = now();
            $row = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
                'role_id' => $data['role_id'],
                'department_id' => $data['department_id'],
                'industry_id' => $data['industry_id'] ?? null,
                'phone' => $data['phone'],
                'active' => (int) ($data['active'] ?? true),
                'must_change_password' => (int) ($data['must_change_password'] ?? true),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $id = Db::insert('users', $row);
            unset($row['password_hash']);
            Audit::log('create', 'user', $id, null, $row, $row['name']);
            return $id;
        });

        return Response::created(Present::user($this->find($id)));
    }

    public function update(Request $request): Response
    {
        $id = $request->id();
        $data = Validator::validate($request->all(), [
            'name' => 'sometimes|required|string|max:150',
            'email' => 'sometimes|required|email|max:190',
            'role_id' => 'sometimes|required|int|exists:roles',
            'department_id' => 'sometimes|nullable|int|exists:departments',
            'industry_id' => 'sometimes|nullable|int|exists:industries',
            'phone' => 'sometimes|nullable|string|max:30',
            'must_change_password' => 'sometimes|bool',
        ]);
        $password = $request->input('password');

        Db::transaction(function () use ($id, $data, $password) {
            $before = $this->lock($id);
            if (isset($data['email'])) {
                $this->assertEmailFree($data['email'], $id);
            }
            if (isset($data['role_id']) && (int) $data['role_id'] !== (int) $before['role_id']) {
                if ($id === Auth::id()) {
                    throw HttpException::rule('SELF_ROLE_CHANGE', 'Você não pode alterar o seu próprio perfil.');
                }
                $this->assertNotLastAdmin($before);
            }
            $changes = $data;
            if (isset($changes['must_change_password'])) {
                $changes['must_change_password'] = (int) $changes['must_change_password'];
            }
            if (is_string($password) && $password !== '') {
                Validator::password('password', $password);
                $changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $changes['must_change_password'] = (int) ($data['must_change_password'] ?? true);
            }
            if ($changes === []) {
                return;
            }
            Db::update('users', $changes + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            // A new password or role signs the user out of every open session.
            if (isset($changes['password_hash']) || isset($changes['role_id'])) {
                Db::query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
            }
            $audit = $changes;
            if (isset($audit['password_hash'])) {
                unset($audit['password_hash']);
                $audit['password'] = 'redefinida pelo administrador';
            }
            Audit::logUpdate('user', $id, $before, $audit, $data['name'] ?? $before['name']);
        });

        return Response::ok(Present::user($this->find($id)));
    }

    public function activate(Request $request): Response
    {
        return $this->setActive($request->id(), true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->setActive($request->id(), false);
    }

    /** "Excluir" = trash. The user can no longer log in; history is kept. */
    public function destroy(Request $request): Response
    {
        $id = $request->id();
        Db::transaction(function () use ($id) {
            $before = $this->lock($id);
            $this->assertNotSelf($id, 'excluir');
            $this->assertNotLastAdmin($before);
            Db::query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
            unset($before['password_hash']);
            Trash::softDelete('users', $id, 'user', $before, $before['name']);
        });
        return Response::ok(['id' => $id, 'deleted' => true]);
    }

    public function restore(Request $request): Response
    {
        $id = $request->id();
        Db::transaction(function () use ($id) {
            $row = Db::fetch('SELECT * FROM users WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw HttpException::notFound('Usuário não encontrado.');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Este usuário não está na lixeira.');
            }
            Trash::restore('users', $id, 'user', $row['name']);
        });
        return Response::ok(Present::user($this->find($id)));
    }

    /** Permanent deletion: only from the trash and only for users that never acted in the system. */
    public function purge(Request $request): Response
    {
        $id = $request->id();
        Db::transaction(function () use ($id, $request) {
            $row = Db::fetch('SELECT * FROM users WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw HttpException::notFound('Usuário não encontrado.');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Envie o usuário para a lixeira antes de excluir definitivamente.');
            }
            Trash::assertConfirmed($request->input('confirm'), $row['email']);
            Trash::assertUnused($id, [
                ['audit_log', 'user_id', 'registro(s) de auditoria'],
                ['stock_movements', 'user_id', 'movimentação(ões)'],
                ['stock_movements', 'requester_id', 'movimentação(ões) como solicitante'],
                ['requests', 'requester_id', 'solicitação(ões)'],
                ['deliveries', 'delivered_by', 'protocolo(s)'],
                ['approval_rules', 'approver_user_id', 'regra(s) de aprovação'],
            ], 'o usuário');
            Db::query('DELETE FROM users WHERE id = ?', [$id]);
            unset($row['password_hash']);
            Audit::log('purge', 'user', $id, $row, null, $row['name']);
        });
        return Response::ok(['id' => $id, 'purged' => true]);
    }

    // -----------------------------------------------------------------

    private function setActive(int $id, bool $active): Response
    {
        Db::transaction(function () use ($id, $active) {
            $before = $this->lock($id);
            if ((bool) $before['active'] === $active) {
                return;
            }
            if (!$active) {
                $this->assertNotSelf($id, 'inativar');
                $this->assertNotLastAdmin($before);
            }
            Db::query(
                'UPDATE users SET active = ?, updated_by = ?, updated_at = ?, session_version = session_version + 1 WHERE id = ?',
                [(int) $active, Auth::id(), now(), $id]
            );
            Audit::log($active ? 'activate' : 'deactivate', 'user', $id, ['active' => (bool) $before['active']], ['active' => $active], $before['name']);
        });
        return Response::ok(Present::user($this->find($id)));
    }

    private function find(int $id, bool $withTrashed = false): array
    {
        $row = Db::fetch(self::SELECT . ' ' . self::FROM . ' WHERE u.id = ?' . ($withTrashed ? '' : ' AND u.deleted_at IS NULL'), [$id]);
        if ($row === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }
        return $row;
    }

    private function lock(int $id): array
    {
        $row = Db::fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }
        return $row;
    }

    private function assertEmailFree(string $email, ?int $ignoreId = null): void
    {
        $row = Db::fetch('SELECT id, deleted_at FROM users WHERE email = ? AND id <> ?', [$email, $ignoreId ?? 0]);
        if ($row !== null) {
            throw HttpException::validation(['email' => $row['deleted_at'] !== null
                ? 'Este e-mail pertence a um usuário na lixeira. Restaure-o em vez de criar outro.'
                : 'Já existe um usuário com este e-mail.']);
        }
    }

    private function assertNotSelf(int $id, string $action): void
    {
        if ($id === Auth::id()) {
            throw HttpException::rule('SELF_ACTION', "Você não pode {$action} o seu próprio usuário.");
        }
    }

    /** The system must always keep at least one active administrator. */
    private function assertNotLastAdmin(array $user): void
    {
        $adminRoleId = (int) Db::value("SELECT id FROM roles WHERE slug = 'admin'");
        if ((int) $user['role_id'] !== $adminRoleId || !(int) $user['active']) {
            return;
        }
        $others = (int) Db::value(
            'SELECT COUNT(*) FROM users WHERE role_id = ? AND active = 1 AND deleted_at IS NULL AND id <> ?',
            [$adminRoleId, (int) $user['id']]
        );
        if ($others === 0) {
            throw HttpException::rule('LAST_ADMIN', 'É necessário manter pelo menos um administrador ativo.');
        }
    }
}
