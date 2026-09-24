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
use App\Support\LookupRegistry;
use App\Support\Present;

/**
 * Generic CRUD for categories, departments, industries, locations and suppliers.
 * The type comes from the URL: /api/{type}.
 */
final class LookupController
{
    /** GET /api/lookups/schema - field definitions for the generic UI page. */
    public function schema(Request $request): Response
    {
        return Response::ok(LookupRegistry::schema());
    }

    /**
     * GET /api/{type}?q=&active=1|0&trashed=1&page=&per_page=
     * GET /api/{type}?all=1  -> every active record, not paginated (for selects)
     */
    public function index(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $table = $def['table'];

        if ($request->queryBool('all')) {
            $sql = "SELECT * FROM `{$table}` WHERE deleted_at IS NULL AND active = 1";
            $params = [];
            if ($table === 'industries' && ($iid = Auth::industryId())) {
                $sql .= ' AND id = ?';
                $params[] = $iid;
            }
            $rows = Db::fetchAll($sql . ' ORDER BY name', $params);
            return Response::ok(array_map(fn ($r) => Present::lookup($r, $def['fields']), $rows));
        }

        $where = [$request->queryBool('trashed') ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [];
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(' . implode(' OR ', array_map(fn ($c) => "`{$c}` LIKE ?", $def['search'])) . ')';
            foreach ($def['search'] as $_) {
                $params[] = Db::like($q);
            }
        }
        if (in_array($request->query('active'), ['0', '1'], true)) {
            $where[] = 'active = ?';
            $params[] = (int) $request->query('active');
        }
        $order = Paginator::orderBy($request, ['name' => 'name', 'created_at' => 'created_at', 'updated_at' => 'updated_at'], 'updated_at DESC, id DESC');
        [$rows, $meta] = Paginator::run(
            $request,
            'SELECT *',
            "FROM `{$table}` WHERE " . implode(' AND ', $where),
            $params,
            $order
        );
        return Response::ok(array_map(fn ($r) => Present::lookup($r, $def['fields']), $rows), $meta);
    }

    public function show(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $row = Db::fetch("SELECT * FROM `{$def['table']}` WHERE id = ?", [$request->id()]);
        if ($row === null) {
            throw HttpException::notFound($def['label'] . ' não encontrado(a).');
        }
        return Response::ok(Present::lookup($row, $def['fields']));
    }

    public function store(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $data = Validator::validate($request->all(), $def['fields'] + ['active' => 'sometimes|bool']);
        $this->assertNameFree($def, $data['name']);

        $id = Db::transaction(function () use ($def, $data) {
            $now = now();
            $row = $data + ['created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now];
            if (isset($row['active'])) {
                $row['active'] = (int) $row['active'];
            }
            $id = Db::insert($def['table'], $row);
            Audit::log('create', $def['entity'], $id, null, $data, $data['name']);
            return $id;
        });

        return Response::created($this->present($def, $id));
    }

    public function update(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $id = $request->id();
        $rules = [];
        foreach ($def['fields'] as $field => $rule) {
            $rules[$field] = is_array($rule) ? ['sometimes', ...$rule] : 'sometimes|' . $rule;
        }
        $data = Validator::validate($request->all(), $rules);

        Db::transaction(function () use ($def, $id, $data) {
            $before = $this->lock($def, $id);
            if (isset($data['name'])) {
                $this->assertNameFree($def, $data['name'], $id);
            }
            if ($data === []) {
                return;
            }
            Db::update($def['table'], $data + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            Audit::logUpdate($def['entity'], $id, $before, $data, $data['name'] ?? $before['name']);
        });

        return Response::ok($this->present($def, $id));
    }

    public function activate(Request $request): Response
    {
        return $this->setActive($request, true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->setActive($request, false);
    }

    public function destroy(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $id = $request->id();
        Db::transaction(function () use ($def, $id) {
            $before = $this->lock($def, $id);
            Trash::softDelete($def['table'], $id, $def['entity'], $before, $before['name']);
        });
        return Response::ok(['id' => $id, 'deleted' => true]);
    }

    public function restore(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $id = $request->id();
        Db::transaction(function () use ($def, $id) {
            $row = Db::fetch("SELECT * FROM `{$def['table']}` WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null) {
                throw HttpException::notFound($def['label'] . ' não encontrado(a).');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Este registro não está na lixeira.');
            }
            $this->assertNameFree($def, $row['name'], $id);
            Trash::restore($def['table'], $id, $def['entity'], $row['name']);
        });
        return Response::ok($this->present($def, $id));
    }

    public function purge(Request $request): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $id = $request->id();
        Db::transaction(function () use ($def, $id, $request) {
            $row = Db::fetch("SELECT * FROM `{$def['table']}` WHERE id = ? FOR UPDATE", [$id]);
            if ($row === null) {
                throw HttpException::notFound($def['label'] . ' não encontrado(a).');
            }
            if ($row['deleted_at'] === null) {
                throw HttpException::conflict('NOT_IN_TRASH', 'Envie o registro para a lixeira antes de excluir definitivamente.');
            }
            Trash::assertConfirmed($request->input('confirm'), $row['name']);
            Trash::assertUnused($id, $def['references'], mb_strtolower($def['label']) . ' "' . $row['name'] . '"');
            Db::query("DELETE FROM `{$def['table']}` WHERE id = ?", [$id]);
            Audit::log('purge', $def['entity'], $id, $row, null, $row['name']);
        });
        return Response::ok(['id' => $id, 'purged' => true]);
    }

    // -----------------------------------------------------------------

    private function setActive(Request $request, bool $active): Response
    {
        $def = LookupRegistry::get($request->param('type'));
        $id = $request->id();
        Db::transaction(function () use ($def, $id, $active) {
            $before = $this->lock($def, $id);
            if ((bool) $before['active'] === $active) {
                return;
            }
            Db::update($def['table'], ['active' => (int) $active, 'updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
            Audit::log($active ? 'activate' : 'deactivate', $def['entity'], $id, ['active' => (bool) $before['active']], ['active' => $active], $before['name']);
        });
        return Response::ok($this->present($def, $id));
    }

    private function present(array $def, int $id): array
    {
        return Present::lookup(Db::fetch("SELECT * FROM `{$def['table']}` WHERE id = ?", [$id]), $def['fields']);
    }

    private function lock(array $def, int $id): array
    {
        $row = Db::fetch("SELECT * FROM `{$def['table']}` WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$id]);
        if ($row === null) {
            throw HttpException::notFound($def['label'] . ' não encontrado(a).');
        }
        return $row;
    }

    /** Names are unique among records that are not in the trash (case-insensitive). */
    private function assertNameFree(array $def, string $name, ?int $ignoreId = null): void
    {
        $exists = Db::value(
            "SELECT 1 FROM `{$def['table']}` WHERE name = ? AND deleted_at IS NULL AND id <> ?",
            [$name, $ignoreId ?? 0]
        );
        if ($exists !== null) {
            throw HttpException::validation(['name' => 'Já existe um registro com este nome.']);
        }
    }
}
