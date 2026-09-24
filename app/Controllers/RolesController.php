<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;

final class RolesController
{
    /** GET /api/roles - roles with their permission slugs and user counts. */
    public function index(Request $request): Response
    {
        $roles = Db::fetchAll(
            'SELECT r.id, r.slug, r.name, r.description,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users_count
               FROM roles r ORDER BY r.id'
        );
        $grants = Db::fetchAll(
            'SELECT rp.role_id, p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.slug'
        );
        $allSlugs = Db::query('SELECT slug FROM permissions ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);

        $out = array_map(function (array $role) use ($grants, $allSlugs) {
            $perms = $role['slug'] === 'admin'
                ? $allSlugs
                : array_values(array_map(fn ($g) => $g['slug'], array_filter($grants, fn ($g) => (int) $g['role_id'] === (int) $role['id'])));
            return [
                'id' => (int) $role['id'],
                'slug' => $role['slug'],
                'name' => $role['name'],
                'description' => $role['description'],
                'users_count' => (int) $role['users_count'],
                'editable' => $role['slug'] !== 'admin',
                'permissions' => $perms,
            ];
        }, $roles);

        return Response::ok($out);
    }

    /** GET /api/permissions - catalog grouped by module. */
    public function permissions(Request $request): Response
    {
        $rows = Db::fetchAll('SELECT slug, name, module FROM permissions ORDER BY module, id');
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['module']][] = ['slug' => $row['slug'], 'name' => $row['name']];
        }
        $out = [];
        foreach ($groups as $module => $perms) {
            $out[] = ['module' => $module, 'permissions' => $perms];
        }
        return Response::ok($out);
    }

    /** PUT /api/roles/{id}/permissions  {"permissions": ["items.view", ...]} */
    public function updatePermissions(Request $request): Response
    {
        $id = $request->id();
        $role = Db::fetch('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            throw HttpException::notFound('Perfil não encontrado.');
        }
        if ($role['slug'] === 'admin') {
            throw HttpException::rule('ROLE_NOT_EDITABLE', 'O perfil Administrador sempre tem acesso total.');
        }
        $slugs = $request->input('permissions');
        if (!is_array($slugs)) {
            throw HttpException::validation(['permissions' => 'Informe a lista de permissões.']);
        }
        $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));
        $valid = Db::query('SELECT slug, id FROM permissions')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $unknown = array_diff($slugs, array_keys($valid));
        if ($unknown !== []) {
            throw HttpException::validation(['permissions' => 'Permissão desconhecida: ' . implode(', ', $unknown)]);
        }

        Db::transaction(function () use ($id, $role, $slugs, $valid) {
            $before = Db::query(
                'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug',
                [$id]
            )->fetchAll(\PDO::FETCH_COLUMN);
            Db::query('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($slugs as $slug) {
                Db::insert('role_permissions', ['role_id' => $id, 'permission_id' => (int) $valid[$slug]]);
            }
            sort($slugs);
            Audit::log('permissions_update', 'role', $id, [
                'removed' => array_values(array_diff($before, $slugs)),
            ], [
                'added' => array_values(array_diff($slugs, $before)),
            ], $role['name']);
        });

        return $this->index($request);
    }
}
