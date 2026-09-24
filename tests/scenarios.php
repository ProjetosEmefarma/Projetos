<?php

declare(strict_types=1);

/**
 * Business-rule scenarios - run before every staging/production deploy:
 *
 *   php tests/scenarios.php
 *
 * Uses a separate database (<DB_NAME>_test) that is recreated on every run.
 * Exit code 0 = all scenarios passed.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestClient.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\HttpException;
use App\Services\StockService;
use App\Support\Installer;

// --------------------------------------------------------------------------
// Isolated test environment
// --------------------------------------------------------------------------
$testDb = preg_replace('/_test$/', '', (string) Config::get('db.name')) . '_test';
$testEnv = ['APP_ENV' => 'testing', 'DB_NAME' => $testDb, 'MAIL_DRIVER' => 'log', 'APP_DEBUG' => 'true'];
foreach ($testEnv as $k => $v) {
    putenv("{$k}={$v}");
}
Config::set('app.env', 'testing');
Config::set('app.debug', true);
Config::set('db.name', $testDb);
Config::set('mail.driver', 'log');
Db::disconnect();
Installer::install(true, static fn () => null);

$app = App::create();
$pass = 0;
$fail = 0;
$failures = [];

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL;
}

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  [OK]   {$name}" . PHP_EOL;
        return;
    }
    $fail++;
    $failures[] = $name;
    $info = is_array($detail) ? json_encode($detail['json'] ?? $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $detail;
    echo "  [FAIL] {$name}" . PHP_EOL . '         ' . mb_substr($info, 0, 600) . PHP_EOL;
}

function code(array $r): ?string
{
    return $r['json']['error']['code'] ?? null;
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function sigPng(): string
{
    $img = imagecreatetruecolor(400, 160);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $ink = imagecolorallocate($img, 20, 20, 20);
    imageline($img, 20, 80, 360, 70, $ink);
    imageline($img, 40, 110, 280, 90, $ink);
    ob_start();
    imagepng($img);
    $bin = (string) ob_get_clean();
    imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode($bin);
}

function tmpImage(string $type = 'png'): string
{
    $img = imagecreatetruecolor(640, 480);
    imagefill($img, 0, 0, imagecolorallocate($img, 37, 99, 235));
    $path = tempnam(sys_get_temp_dir(), 'img');
    $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path);
    return $path;
}

function upload(string $path, string $name, string $mime): array
{
    return ['name' => $name, 'type' => $mime, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

/** Runs N copies of tests/concurrent_worker.php at the same instant. */
function runWorkers(int $count, array $args): array
{
    global $testEnv;
    $env = array_merge(getenv(), $testEnv);
    $startAt = sprintf('%.3f', microtime(true) + 2.5);
    $procs = [];
    $pipesList = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/concurrent_worker.php', $args[0], (string) $args[1], $startAt], array_slice($args, 2));
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
        $pipesList[$i] = $pipes;
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $stdout = stream_get_contents($pipesList[$i][1]);
        $stderr = stream_get_contents($pipesList[$i][2]);
        proc_close($proc);
        $results[] = json_decode(trim((string) $stdout), true) ?? ['ok' => false, 'error' => trim($stdout . ' ' . $stderr)];
    }
    return $results;
}

function onHand(int $itemId): int
{
    return (int) Db::value('SELECT qty_on_hand FROM stock WHERE item_id = ?', [$itemId]);
}

// ==========================================================================
section('S. Catálogo de permissões');
// ==========================================================================
$slugs = Db::query('SELECT slug FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
$used = [];
foreach ($app->router()->routes() as $route) {
    foreach ((array) ($route['options']['perm'] ?? []) as $p) {
        $used[] = $p;
    }
}
foreach ($app->pages() as $page) {
    foreach ((array) ($page['perm'] ?? []) as $p) {
        $used[] = $p;
    }
}
$missing = array_values(array_diff(array_unique($used), $slugs));
check('S1 toda permissão usada em rotas/páginas existe no banco', $missing === [], implode(', ', $missing));

// ==========================================================================
section('A. Autenticação e segurança');
// ==========================================================================
$guest = new TestClient($app, '10.0.0.2');
$r = $guest->get('/api/health');
check('A1 health check', $r['status'] === 200 && $r['json']['data']['database'] === 'ok', $r);

$r = $guest->get('/api/items');
check('A2 API sem login -> 401 UNAUTHENTICATED', $r['status'] === 401 && code($r) === 'UNAUTHENTICATED', $r);

$r = $guest->call('POST', '/api/auth/login', ['email' => 'admin@brindes.local', 'password' => 'Trocar@123'], ['X-CSRF-Token' => '']);
check('A3 login sem token CSRF -> 403 CSRF_INVALID', $r['status'] === 403 && code($r) === 'CSRF_INVALID', $r);

$admin = new TestClient($app);
$r = $admin->login('admin@brindes.local', 'Errada123');
check('A4 senha errada -> 401 INVALID_CREDENTIALS', $r['status'] === 401 && code($r) === 'INVALID_CREDENTIALS', $r);

$r = $admin->login('admin@brindes.local', 'Trocar@123');
check('A5 login do admin inicial + troca de senha obrigatória', $r['status'] === 200 && $r['json']['data']['must_change_password'] === true, $r);

$r = $admin->get('/api/items');
check('A6 API bloqueada até trocar a senha -> PASSWORD_CHANGE_REQUIRED', $r['status'] === 403 && code($r) === 'PASSWORD_CHANGE_REQUIRED', $r);

$r = $admin->post('/api/auth/change-password', ['current_password' => 'Trocar@123', 'new_password' => 'fraca', 'new_password_confirmation' => 'fraca']);
check('A7 senha fraca recusada', $r['status'] === 422 && isset($r['json']['error']['fields']['new_password']), $r);

$r = $admin->post('/api/auth/change-password', ['current_password' => 'Trocar@123', 'new_password' => 'Admin@2026', 'new_password_confirmation' => 'Admin@2026']);
check('A8 troca de senha concluída', $r['status'] === 200 && $r['json']['data']['must_change_password'] === false, $r);

$r = $admin->get('/api/auth/me');
check('A9 /me traz usuário, permissões e branding', $r['status'] === 200 && in_array('items.purge', $r['json']['data']['permissions'], true) && isset($r['json']['data']['settings']['company_name']), $r);

$r = $guest->get('/dashboard');
check('A10 página protegida sem login -> redireciona para /login?next=', $r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/login?next=%2Fdashboard'), $r['headers']);

$r = $admin->get('/dashboard');
check('A11 página autenticada renderiza com meta csrf-token', $r['status'] === 200 && str_contains($r['body'], 'name="csrf-token"'), substr($r['body'], 0, 200));

$r = $admin->get('/api/nao-existe');
check('A12 endpoint inexistente -> 404 JSON', $r['status'] === 404 && code($r) === 'NOT_FOUND', $r);

$r = $admin->call('DELETE', '/api/health');
check('A13 método errado -> 405', $r['status'] === 405, $r);

// ==========================================================================
section('B. Usuários, perfis e permissões');
// ==========================================================================
$mk = fn (string $name, string $email, int $role) => $admin->post('/api/users', [
    'name' => $name, 'email' => $email, 'password' => 'Senha1234', 'role_id' => $role, 'must_change_password' => false,
]);
$r = $mk('Operador Teste', 'op@teste.local', 3);
check('B1 admin cria usuário Operação', $r['status'] === 201 && $r['json']['data']['role']['slug'] === 'operations', $r);
$opId = $r['json']['data']['id'] ?? 0;
$r = $mk('Solicitante Teste', 'sol@teste.local', 4);
$solId = $r['json']['data']['id'] ?? 0;
$r = $mk('Gestor Teste', 'gestor@teste.local', 2);
check('B2 admin cria Solicitante e Gestor', $r['status'] === 201 && $solId > 0, $r);

$r = $mk('Duplicado', 'OP@teste.local', 3);
check('B3 e-mail duplicado (maiúsculas) recusado', $r['status'] === 422 && isset($r['json']['error']['fields']['email']), $r);

$op = new TestClient($app, '10.0.0.3');
$sol = new TestClient($app, '10.0.0.4');
$gestor = new TestClient($app, '10.0.0.22');
$r1 = $op->login('op@teste.local', 'Senha1234');
$r2 = $sol->login('sol@teste.local', 'Senha1234');
$r3 = $gestor->login('gestor@teste.local', 'Senha1234');
check('B4 novos usuários fazem login', $r1['status'] === 200 && $r2['status'] === 200 && $r3['status'] === 200, [$r1, $r2, $r3]);

$r = $sol->post('/api/items', ['name' => 'X', 'category_id' => 1]);
check('B5 Solicitante não cadastra brinde -> 403', $r['status'] === 403 && code($r) === 'FORBIDDEN', $r);

$r1 = $sol->get('/api/users');
$r2 = $sol->get('/api/users/options');
check('B6 Solicitante não lista usuários, mas acessa /users/options', $r1['status'] === 403 && $r2['status'] === 200 && count($r2['json']['data']) >= 4, [$r1, $r2]);

$r = $admin->post('/api/users/1/deactivate');
check('B7 admin não pode inativar a si mesmo', $r['status'] === 422 && code($r) === 'SELF_ACTION', $r);

$r = $admin->put('/api/roles/1/permissions', ['permissions' => []]);
check('B8 perfil Administrador não é editável', $r['status'] === 422 && code($r) === 'ROLE_NOT_EDITABLE', $r);

$roles = array_column($admin->get('/api/roles')['json']['data'], null, 'slug');
$requesterPerms = $roles['requester']['permissions'];
$r0 = $sol->get('/api/stock/movements');
$admin->put('/api/roles/4/permissions', ['permissions' => [...$requesterPerms, 'stock.view']]);
$r1 = $sol->get('/api/stock/movements');
$admin->put('/api/roles/4/permissions', ['permissions' => $requesterPerms]);
$r2 = $sol->get('/api/stock/movements');
check('B9 permissões configuráveis valem na hora (403 -> 200 -> 403)', $r0['status'] === 403 && $r1['status'] === 200 && $r2['status'] === 403, [$r0['status'], $r1['status'], $r2['status']]);

$opPerms = $roles['operations']['permissions'];
$admin->put('/api/roles/3/permissions', ['permissions' => [...$opPerms, 'users.manage']]);
$r = $op->post('/api/users/1/deactivate');
$admin->put('/api/roles/3/permissions', ['permissions' => $opPerms]);
check('B10 sempre existe ao menos um administrador ativo', $r['status'] === 422 && code($r) === 'LAST_ADMIN', $r);

$mk('Temporário', 'temp@teste.local', 4);
$tmp = new TestClient($app, '10.0.0.5');
$tmp->login('temp@teste.local', 'Senha1234');
$tmpId = (int) Db::value("SELECT id FROM users WHERE email = 'temp@teste.local'");
$admin->post("/api/users/{$tmpId}/deactivate");
$r1 = $tmp->get('/api/auth/me');
$r2 = $tmp->login('temp@teste.local', 'Senha1234');
check('B11 inativar usuário encerra a sessão e bloqueia login', $r1['status'] === 401 && $r2['status'] === 403 && code($r2) === 'USER_INACTIVE', [$r1, $r2]);

$r = $admin->post("/api/users/{$tmpId}/purge", ['confirm' => 'temp@teste.local']);
check('B12 exclusão definitiva exige lixeira antes', $r['status'] === 409 && code($r) === 'NOT_IN_TRASH', $r);
$admin->delete("/api/users/{$tmpId}");
$r = $admin->post("/api/users/{$tmpId}/purge", ['confirm' => 'temp@teste.local']);
check('B13 usuário que já agiu no sistema não é excluído definitivamente', $r['status'] === 409 && code($r) === 'IN_USE', $r);

// ==========================================================================
section('C. Cadastros auxiliares');
// ==========================================================================
$r = $admin->get('/api/lookups/schema');
check('C1 schema dos 5 cadastros para a tela genérica', $r['status'] === 200 && count($r['json']['data']) === 5, $r);
$indSchema = null;
$locSchema = null;
foreach ($r['json']['data'] ?? [] as $s) {
    if (($s['page'] ?? '') === 'industrias') {
        $indSchema = $s;
    }
    if (($s['page'] ?? '') === 'locais') {
        $locSchema = $s;
    }
}
$kindField = null;
foreach ($locSchema['fields'] ?? [] as $f) {
    if (($f['name'] ?? '') === 'kind') {
        $kindField = $f;
    }
}
check('C1b cadastro de indústrias usa endpoint relativo', is_array($indSchema) && ($indSchema['endpoint'] ?? '') === '/api/industries', $indSchema);
check('C1c locais têm tipo CD / evento / escritório', is_array($kindField) && ($kindField['type'] ?? '') === 'select', $kindField);

$r = $admin->post('/api/categories', ['name' => 'Eletrônicos']);
$catId = $r['json']['data']['id'] ?? 0;
check('C2 cria categoria', $r['status'] === 201 && $catId > 0, $r);

$r = $admin->post('/api/categories', ['name' => 'eletrônicos']);
check('C3 nome duplicado recusado', $r['status'] === 422 && isset($r['json']['error']['fields']['name']), $r);

$r = $admin->put("/api/categories/{$catId}", ['description' => 'Aparelhos eletrônicos']);
check('C4 edita categoria', $r['status'] === 200 && $r['json']['data']['description'] === 'Aparelhos eletrônicos', $r);

$r1 = $admin->post("/api/categories/{$catId}/deactivate");
$r2 = $admin->post("/api/categories/{$catId}/activate");
check('C5 inativa e reativa', $r1['json']['data']['active'] === false && $r2['json']['data']['active'] === true, [$r1, $r2]);

$deptId = $admin->post('/api/departments', ['name' => 'Comercial'])['json']['data']['id'] ?? 0;
$indId = $admin->post('/api/industries', ['name' => 'Indústria Alfa', 'contact_email' => 'Contato@Alfa.example', 'cnpj' => '12.345.678/0001-90'])['json']['data']['id'] ?? 0;
$supId = $admin->post('/api/suppliers', ['name' => 'Fornecedor X'])['json']['data']['id'] ?? 0;
check('C6 cria departamento, indústria e fornecedor', $deptId > 0 && $indId > 0 && $supId > 0);

$r = $admin->post('/api/industries', ['name' => 'Indústria Beta', 'contact_email' => 'invalido']);
check('C7 e-mail inválido recusado', $r['status'] === 422 && isset($r['json']['error']['fields']['contact_email']), $r);

$r = $sol->post('/api/categories', ['name' => 'Hack']);
check('C8 Solicitante não cria cadastros -> 403', $r['status'] === 403, $r);

$tmpCat = $admin->post('/api/categories', ['name' => 'Temporária'])['json']['data']['id'];
$r1 = $admin->post("/api/categories/{$tmpCat}/purge", ['confirm' => 'Temporária']);
$admin->delete("/api/categories/{$tmpCat}");
$listed = array_column($admin->get('/api/categories?per_page=100')['json']['data'], 'id');
$trashed = array_column($admin->get('/api/categories?trashed=1')['json']['data'], 'id');
check('C9 excluir -> lixeira (some da lista, aparece em trashed)', $r1['status'] === 409 && !in_array($tmpCat, $listed, true) && in_array($tmpCat, $trashed, true), $r1);

$r = $admin->post("/api/categories/{$tmpCat}/restore");
check('C10 restaurar da lixeira', $r['status'] === 200 && $r['json']['data']['deleted_at'] === null, $r);

$admin->delete("/api/categories/{$tmpCat}");
$r1 = $admin->post("/api/categories/{$tmpCat}/purge", ['confirm' => 'errado']);
$r2 = $admin->post("/api/categories/{$tmpCat}/purge", ['confirm' => 'Temporária']);
check('C11 exclusão definitiva só com confirmação digitada', $r1['status'] === 422 && $r2['status'] === 200 && Db::value('SELECT 1 FROM categories WHERE id = ?', [$tmpCat]) === null, [$r1, $r2]);

// ==========================================================================
section('D. Brindes');
// ==========================================================================
$r = $admin->post('/api/items', [
    'name' => 'Air Fryer 4L', 'category_id' => $catId, 'location_id' => 1, 'supplier_id' => $supId,
    'unit_value' => '389,90', 'min_stock' => 5, 'initial_quantity' => 10,
]);
$airId = $r['json']['data']['id'] ?? 0;
check('D1 cria brinde com código automático, valor em R$ e saldo inicial', $r['status'] === 201
    && $r['json']['data']['code'] === 'BRD-00001' && $r['json']['data']['unit_value'] === 389.9
    && $r['json']['data']['stock']['on_hand'] === 10, $r);

$mv = Db::fetch('SELECT type, qty, reason FROM stock_movements WHERE item_id = ?', [$airId]);
check('D2 saldo inicial gera movimentação de entrada rastreável', $mv && $mv['type'] === 'entrada' && (int) $mv['qty'] === 10 && $mv['reason'] === 'Saldo inicial', $mv);

$r = $admin->post('/api/items', ['code' => 'brd-00001', 'name' => 'Duplicado', 'category_id' => $catId]);
check('D3 código duplicado recusado (sem diferenciar maiúsculas)', $r['status'] === 422 && isset($r['json']['error']['fields']['code']), $r);

$r = $admin->post('/api/items', ['code' => 'tv-43', 'name' => 'Smart TV 43"', 'category_id' => $catId, 'unit_value' => 1899, 'min_stock' => 2]);
$tvId = $r['json']['data']['id'] ?? 0;
check('D4 código informado é normalizado (TV-43), saldo 0', $r['status'] === 201 && $r['json']['data']['code'] === 'TV-43' && $r['json']['data']['stock']['level'] === 'zero', $r);

$r = $admin->post('/api/items', ['name' => '', 'category_id' => 9999]);
check('D5 validação de campos obrigatórios / inexistentes', $r['status'] === 422 && isset($r['json']['error']['fields']['name'], $r['json']['error']['fields']['category_id']), $r);

$r = $admin->put("/api/items/{$airId}", ['name' => 'Air Fryer 4L Digital', 'min_stock' => 12, 'unit_value' => 389.90]);
$aud = Db::fetch("SELECT before_json, after_json FROM audit_log WHERE entity_type = 'item' AND entity_id = ? AND action = 'update' ORDER BY id DESC LIMIT 1", [$airId]);
$after = json_decode($aud['after_json'] ?? '{}', true);
check('D6 edição auditada só com os campos alterados', $r['status'] === 200 && array_keys($after) === ['name', 'min_stock'], $aud);

$r1 = $admin->get('/api/items?q=digital');
$r2 = $admin->get('/api/items?stock_level=low');
$r3 = $admin->get('/api/items?stock_level=zero');
check('D7 busca e filtros por nível de estoque', $r1['json']['meta']['total'] === 1
    && in_array($airId, array_column($r2['json']['data'], 'id'), true)
    && in_array($tvId, array_column($r3['json']['data'], 'id'), true), [$r1['json']['meta'], $r2['json']['meta'], $r3['json']['meta']]);

$img = tmpImage('png');
$r = $admin->call('POST', "/api/items/{$airId}/photo", [], [], ['photo' => upload($img, 'foto.png', 'image/png')]);
check('D8 upload de foto gera foto + miniatura', $r['status'] === 200 && $r['json']['data']['thumb_url'] !== null, $r);
$r = $admin->get("/api/items/{$airId}/photo?size=thumb");
check('D9 miniatura servida somente para usuário logado', $r['status'] === 200 && ($r['headers']['Content-Type'] ?? '') === 'image/jpeg' && $guest->get("/api/items/{$airId}/photo")['status'] === 401, $r['headers']);

$txt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($txt, '<?php echo "x";');
$r = $admin->call('POST', "/api/items/{$airId}/photo", [], [], ['photo' => upload($txt, 'foto.jpg', 'image/jpeg')]);
check('D10 arquivo que não é imagem é recusado', $r['status'] === 422, $r);

$admin->post("/api/items/{$tvId}/deactivate");
$r = $admin->postIdem('/api/stock/entries', ['item_id' => $tvId, 'quantity' => 1]);
$admin->post("/api/items/{$tvId}/activate");
check('D11 brinde inativo não movimenta estoque', $r['status'] === 422 && code($r) === 'ITEM_INACTIVE', $r);

$r = $admin->delete("/api/items/{$airId}");
check('D12 não exclui brinde com saldo em estoque', $r['status'] === 409 && code($r) === 'ITEM_HAS_STOCK', $r);

$r = $sol->get('/api/items/options?q=air');
check('D13 seletor leve de brindes para solicitações', $r['status'] === 200 && ($r['json']['data'][0]['available'] ?? null) === 10, $r);

// ==========================================================================
section('E. Estoque (entrada, saída, ajuste, reservas, concorrência)');
// ==========================================================================
$r = $op->post('/api/stock/entries', ['item_id' => $tvId, 'quantity' => 5]);
check('E1 escrita de estoque sem Idempotency-Key -> 400', $r['status'] === 400 && code($r) === 'IDEMPOTENCY_KEY_REQUIRED', $r);

$key = uuid();
$body = ['item_id' => $tvId, 'quantity' => 5, 'purchase_ticket_no' => 'CH-2026-0001', 'supplier_id' => $supId];
$r1 = $op->postIdem('/api/stock/entries', $body, $key);
$r2 = $op->postIdem('/api/stock/entries', $body, $key);
$count = (int) Db::value("SELECT COUNT(*) FROM stock_movements WHERE item_id = ? AND type = 'entrada'", [$tvId]);
check('E2 [T2] envio duplicado não duplica a entrada', $r1['status'] === 201 && $r2['status'] === 201
    && $r1['json']['data']['movement']['id'] === $r2['json']['data']['movement']['id']
    && ($r2['headers']['Idempotent-Replayed'] ?? '') === 'true' && $count === 1 && onHand($tvId) === 5, [$r1, $r2, $count]);

$r = $op->postIdem('/api/stock/entries', ['item_id' => $tvId, 'quantity' => 50], $key);
check('E3 mesma chave com dados diferentes é recusada', $r['status'] === 422 && code($r) === 'IDEMPOTENCY_KEY_REUSED', $r);

$denied = $op->postIdem('/api/stock/exits', ['item_id' => $tvId, 'quantity' => 1, 'purpose' => 'CD não autoriza']);
check('E4cd CD não registra saída — só confirma a autorizada pelo gestor', $denied['status'] === 403, $denied);

$r = $gestor->postIdem('/api/stock/exits', ['item_id' => $tvId, 'quantity' => 6, 'purpose' => 'Premiação']);
check('E4 [T1] saída maior que o disponível -> STOCK_INSUFFICIENT, saldo intacto', $r['status'] === 422 && code($r) === 'STOCK_INSUFFICIENT'
    && $r['json']['error']['details']['available'] === 5 && onHand($tvId) === 5, $r);

$r = $gestor->postIdem('/api/stock/exits', ['item_id' => $tvId, 'quantity' => 2]);
check('E5 saída manual exige finalidade', $r['status'] === 422 && isset($r['json']['error']['fields']['purpose']), $r);

$auth = $gestor->postIdem('/api/stock/exits', ['item_id' => $tvId, 'quantity' => 2, 'purpose' => 'Evento com cliente', 'industry_id' => $indId, 'department_id' => $deptId, 'requester_id' => $solId, 'recipient' => 'Cliente XPTO']);
$oid = (int) ($auth['json']['data']['order']['id'] ?? 0);
$qr = $op->get('/api/stock/exit-orders/' . $oid . '/qr');
$pending = $op->get('/api/stock/exit-orders?status=autorizada');
$gestorExitPage = $gestor->get('/estoque/saida');
$cdBlocked = $op->get('/estoque/saida');
$cdPage = $op->get('/estoque/confirmar-saida');
$conf = $op->postIdem('/api/stock/exit-orders/' . $oid . '/confirm', []);
check('E6ui registrar saída só no gestor; CD só confirma', $gestorExitPage['status'] === 200
    && str_contains((string) $gestorExitPage['body'], 'Registrar saída')
    && str_contains((string) $gestorExitPage['body'], '/api/stock/exits')
    && $cdBlocked['status'] === 403
    && $cdPage['status'] === 200
    && str_contains((string) $cdPage['body'], 'Confirmar saída'), [$gestorExitPage['status'], $cdBlocked['status'], $cdPage['status']]);
check('E6 gestor registra; CD vê QR e confirma a baixa', $auth['status'] === 201
    && ($auth['json']['data']['order']['status'] ?? '') === 'autorizada'
    && str_starts_with((string) ($auth['json']['data']['order']['code'] ?? ''), 'SAI-')
    && $auth['json']['data']['stock']['on_hand'] === 5 && $auth['json']['data']['stock']['reserved'] === 2
    && $qr['status'] === 200 && str_contains((string) ($qr['headers']['Content-Type'] ?? ''), 'png')
    && $cdPage['status'] === 200
    && ($pending['json']['meta']['total'] ?? count($pending['json']['data'] ?? [])) >= 1
    && $conf['status'] === 200 && ($conf['json']['data']['order']['status'] ?? '') === 'confirmada'
    && onHand($tvId) === 3
    && $conf['json']['data']['stock']['on_hand'] === 3, [$auth, $qr['status'], $cdPage['status'], $conf]);

$nfIn = tmpImage('png');
$entNf = $op->postIdem('/api/stock/entries', [
    'item_id' => $tvId, 'quantity' => 1, 'document_ref' => 'NF-E-99',
], null, ['invoice' => upload($nfIn, 'nf-entrada.png', 'image/png')]);
$entId = (int) ($entNf['json']['data']['movement']['id'] ?? 0);
$nfOut = tmpImage('png');
$saiNf = $gestor->postIdem('/api/stock/exits', [
    'item_id' => $tvId, 'quantity' => 1, 'purpose' => 'Entrega com NF', 'document_ref' => 'NF-S-99',
], null, ['invoice' => upload($nfOut, 'nf-saida.png', 'image/png')]);
$saiOrderId = (int) ($saiNf['json']['data']['order']['id'] ?? 0);
$saiConf = $op->postIdem('/api/stock/exit-orders/' . $saiOrderId . '/confirm', []);
$saiId = (int) ($saiConf['json']['data']['order']['movement_id'] ?? 0);
$dlIn = $admin->get('/api/stock/movements/' . $entId . '/attachment');
$dlOut = $admin->get('/api/stock/movements/' . $saiId . '/attachment');
$dlOrd = $admin->get('/api/stock/exit-orders/' . $saiOrderId . '/attachment');
check('E6b entrada e saída aceitam anexo de nota (PDF/imagem)', $entNf['status'] === 201 && $saiNf['status'] === 201
    && !empty($entNf['json']['data']['movement']['attachment_url'])
    && !empty($saiNf['json']['data']['order']['attachment_url'])
    && $saiConf['status'] === 200
    && $dlIn['status'] === 200 && $dlOut['status'] === 200 && $dlOrd['status'] === 200, [$entNf['status'], $saiNf['status'], $saiConf['status'], $dlIn['status'], $dlOut['status'], $dlOrd['status']]);

$r = $op->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'set', 'quantity' => 4, 'reason' => 'Contagem']);
check('E7 Operação não faz ajuste manual (permissão)', $r['status'] === 403, $r);

$r = $admin->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'set', 'quantity' => 4, 'reason' => 'Contagem mensal no CD']);
check('E8 ajuste por contagem (set) calcula a diferença', $r['status'] === 201 && $r['json']['data']['movement']['quantity'] === 1 && onHand($tvId) === 4, $r);

$r1 = $admin->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'set', 'quantity' => 4, 'reason' => 'Contagem']);
$r2 = $admin->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'delta', 'quantity' => -10, 'reason' => 'Perda']);
$r3 = $admin->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'delta', 'quantity' => -1]);
check('E9 ajuste sem mudança / negativo / sem motivo são recusados', code($r1) === 'ADJUSTMENT_NO_CHANGE' && code($r2) === 'STOCK_INSUFFICIENT'
    && isset($r3['json']['error']['fields']['reason']) && onHand($tvId) === 4, [$r1, $r2, $r3]);

Auth::actingAs(Auth::loadUser(1));
StockService::reserve($tvId, 3, 'teste');
$r1 = $gestor->postIdem('/api/stock/exits', ['item_id' => $tvId, 'quantity' => 2, 'purpose' => 'Teste']);
$r2 = $admin->postIdem('/api/stock/adjustments', ['item_id' => $tvId, 'mode' => 'set', 'quantity' => 2, 'reason' => 'Contagem']);
Auth::actingAs(Auth::loadUser(1));
$consumed = StockService::consumeReserved($tvId, 2, ['purpose' => 'Entrega de solicitação']);
$released = StockService::release($tvId, 1, 'teste');
Auth::actingAs(null);
check('E10 unidades reservadas ficam protegidas (saída/ajuste) e a baixa consome a reserva', code($r1) === 'STOCK_INSUFFICIENT'
    && code($r2) === 'STOCK_BELOW_RESERVED' && $consumed['stock']['on_hand'] === 2 && $consumed['stock']['reserved'] === 1
    && $released['reserved'] === 0 && $released['available'] === 2, [$r1, $r2, $consumed, $released]);

try {
    Db::query('UPDATE stock SET qty_on_hand = -1 WHERE item_id = ?', [$tvId]);
    $blocked = false;
} catch (PDOException) {
    $blocked = true;
}
check('E11 o próprio banco impede estoque negativo (CHECK constraint)', $blocked && onHand($tvId) === 2);

$r = $admin->get("/api/stock/movements?item_id={$tvId}&type=saida");
$r2 = $admin->get('/api/stock/movements?from=' . date('Y-m-d') . '&to=' . date('Y-m-d') . '&q=CH-2026-0001');
check('E12 histórico de movimentações com filtros', $r['json']['meta']['total'] === 3 && $r2['json']['meta']['total'] === 1, [$r['json']['meta'], $r2['json']['meta']]);

$par = $admin->post('/api/items', ['name' => 'Concorrência', 'category_id' => $catId, 'initial_quantity' => 3])['json']['data']['id'];
$results = runWorkers(8, ['exit', $par]);
$oks = count(array_filter($results, fn ($x) => $x['ok'] === true));
$insufficient = count(array_filter($results, fn ($x) => ($x['error'] ?? '') === 'STOCK_INSUFFICIENT'));
check('E13 [T3] 8 retiradas simultâneas com 3 em estoque: exatamente 3 aprovadas', $oks === 3 && $insufficient === 5 && onHand($par) === 0, $results);

$par2 = $admin->post('/api/items', ['name' => 'Duplo clique', 'category_id' => $catId, 'initial_quantity' => 10])['json']['data']['id'];
$sameKey = uuid();
$results = runWorkers(4, ['idem', $par2, $sameKey]);
$ids = array_unique(array_column($results, 'movement'));
$exits = (int) Db::value("SELECT COUNT(*) FROM stock_movements WHERE item_id = ? AND type = 'saida'", [$par2]);
check('E14 [T2] 4 envios simultâneos do mesmo formulário geram 1 única autorização', count($ids) === 1 && $ids[0] !== null
    && $exits === 0 && onHand($par2) === 10 && count(array_filter($results, fn ($x) => $x['status'] === 201)) === 4, $results);

// ==========================================================================
section('F. Dashboard, auditoria e configurações');
// ==========================================================================
$r = $admin->get('/api/dashboard');
$d = $r['json']['data'] ?? [];
$days = (int) date('j');
check('F1 dashboard com indicadores, série diária e alertas', $r['status'] === 200 && $d['stock']['items_active'] >= 4
    && count($d['series']['points']) === $days && $d['period_totals']['exits_units'] >= 5 && count($d['alerts']) >= 1, $d);

$r = $sol->get('/api/dashboard');
check('F2 Solicitante vê dashboard sem valores/movimentações de estoque', $r['status'] === 200 && $r['json']['data']['stock']['stock_value'] === null && $r['json']['data']['period_totals'] === null, $r);

$r = $admin->get("/api/items/{$airId}/history");
$kinds = array_unique(array_column($r['json']['data'] ?? [], 'kind'));
check('F3 histórico completo do brinde (movimentações + alterações)', $r['status'] === 200 && in_array('movement', $kinds, true) && in_array('change', $kinds, true), $r);

$r1 = $admin->get('/api/audit?entity_type=item&entity_id=' . $airId);
$r2 = $sol->get('/api/audit');
check('F4 auditoria: admin consulta, Solicitante não', $r1['status'] === 200 && $r1['json']['meta']['total'] >= 3 && $r2['status'] === 403, [$r1['json']['meta'] ?? null, $r2['status']]);

$r1 = $admin->put('/api/settings', ['primary_color' => 'azul']);
$r2 = $admin->put('/api/settings', ['company_name' => 'Empresa Teste', 'primary_color' => '#112233', 'alert_emails' => 'a@b.com; c@d.com']);
$r3 = $guest->get('/api/settings/public');
check('F5 configurações (branding) com validação', $r1['status'] === 422 && $r2['status'] === 200 && count($r2['json']['data']['alert_emails']) === 2
    && $r3['json']['data']['company_name'] === 'Empresa Teste' && $r3['json']['data']['primary_color'] === '#112233', [$r1, $r2, $r3]);

$r = $admin->call('POST', '/api/settings/logo', [], [], ['logo' => upload(tmpImage('png'), 'logo.png', 'image/png')]);
$r2 = $guest->get('/api/settings/logo');
check('F6 upload do logo da empresa (público para a tela de login)', $r['status'] === 200 && $r['json']['data']['logo_url'] !== null && $r2['status'] === 200, [$r, $r2['headers']]);

// ==========================================================================
section('G. Ciclo de vida: lixeira, histórico preservado, exclusão definitiva');
// ==========================================================================
$admin->postIdem('/api/stock/adjustments', ['item_id' => $airId, 'mode' => 'set', 'quantity' => 0, 'reason' => 'Baixa para exclusão']);
$r = $admin->delete("/api/items/{$airId}");
$list = array_column($admin->get('/api/items?per_page=100')['json']['data'], 'id');
check('G1 brinde zerado vai para a lixeira e sai da lista', $r['status'] === 200 && !in_array($airId, $list, true), $r);

$r1 = $admin->get("/api/items/{$airId}/history");
$r2 = $admin->get("/api/stock/movements?item_id={$airId}");
check('G2 [T8] histórico e movimentações continuam disponíveis', $r1['status'] === 200 && $r1['json']['meta']['total'] >= 3 && $r2['json']['meta']['total'] === 2, [$r1['json']['meta'] ?? null, $r2['json']['meta'] ?? null]);

$r = $admin->post("/api/items/{$airId}/purge", ['confirm' => 'BRD-00001']);
check('G3 [T9] brinde com movimentações não pode ser excluído definitivamente', $r['status'] === 409 && code($r) === 'IN_USE', $r);

$r = $admin->post("/api/items/{$airId}/restore");
check('G4 restaura brinde da lixeira', $r['status'] === 200 && $r['json']['data']['deleted_at'] === null, $r);

$never = $admin->post('/api/items', ['code' => 'APAGAR-1', 'name' => 'Cadastro errado', 'category_id' => $catId])['json']['data']['id'];
$admin->delete("/api/items/{$never}");
$r1 = $admin->post("/api/items/{$never}/purge", ['confirm' => 'nao']);
$r2 = $admin->post("/api/items/{$never}/purge", ['confirm' => 'apagar-1']);
$snap = Db::value("SELECT before_json FROM audit_log WHERE action = 'purge' AND entity_type = 'item' AND entity_id = ?", [$never]);
check('G5 cadastro nunca usado: exclusão definitiva com confirmação e cópia na auditoria', $r1['status'] === 422 && $r2['status'] === 200
    && Db::value('SELECT 1 FROM items WHERE id = ?', [$never]) === null && str_contains((string) $snap, 'APAGAR-1'), [$r1, $r2]);

// ==========================================================================
section('H. Recuperação de senha, força bruta e logout');
// ==========================================================================
$guest->get('/api/auth/csrf');
$r = $guest->post('/api/auth/forgot', ['email' => 'sol@teste.local']);
$log = (string) file_get_contents(Config::get('paths.storage') . '/logs/mail.log');
preg_match_all('/token=([a-f0-9]{64})/', $log, $m);
$token = end($m[1]) ?: '';
check('H1 "esqueci a senha" envia link por e-mail', $r['status'] === 200 && $token !== '', $r);

$r = $guest->post('/api/auth/reset', ['token' => $token, 'password' => 'NovaSenha9', 'password_confirmation' => 'NovaSenha9']);
$r1 = $sol->get('/api/auth/me');
$r2 = $sol->login('sol@teste.local', 'NovaSenha9');
$r3 = $guest->post('/api/auth/reset', ['token' => $token, 'password' => 'OutraSenha9', 'password_confirmation' => 'OutraSenha9']);
check('H2 redefinição encerra sessões antigas; link não pode ser reutilizado', $r['status'] === 200 && $r1['status'] === 401
    && $r2['status'] === 200 && code($r3) === 'RESET_TOKEN_INVALID', [$r, $r1, $r2, $r3]);

$r = $guest->post('/api/auth/forgot', ['email' => 'ninguem@teste.local']);
check('H3 e-mail inexistente recebe a mesma resposta (não revela cadastro)', $r['status'] === 200, $r);

$bf = new TestClient($app, '10.0.0.77');
for ($i = 0; $i < 5; $i++) {
    $bf->login('gestor@teste.local', 'Errada123');
}
$r = $bf->login('gestor@teste.local', 'Senha1234');
check('H4 bloqueio após 5 senhas erradas (429)', $r['status'] === 429 && code($r) === 'TOO_MANY_ATTEMPTS', $r);

// ==========================================================================
section('I. Solicitações, aprovação, entrega, evento (T4–T12)');
// ==========================================================================
$cheap = $admin->post('/api/items', [
    'name' => 'Caneta Premium', 'category_id' => $catId, 'unit_value' => 5, 'min_stock' => 2, 'initial_quantity' => 40,
]);
$cheapId = $cheap['json']['data']['id'] ?? 0;
check('I0 brinde barato para o fluxo', $cheap['status'] === 201 && $cheapId > 0, $cheap);

$rAuto = $sol->post('/api/requests', [
    'purpose' => 'Ação rápida', 'items' => [['item_id' => $cheapId, 'qty_requested' => 1]], 'submit' => true,
]);
$autoId = $rAuto['json']['data']['id'] ?? 0;
$autoReserved = (int) Db::value('SELECT qty_reserved FROM stock WHERE item_id = ?', [$cheapId]);
check('I1 [T4] sem regra → aprovada e reserva estoque', $rAuto['status'] === 201 && ($rAuto['json']['data']['status'] ?? '') === 'aprovada' && $autoReserved === 1, $rAuto);

$rRule = $sol->post('/api/requests', [
    'purpose' => 'Volume alto', 'items' => [['item_id' => $cheapId, 'qty_requested' => 11]], 'submit' => true,
]);
$ruleId = $rRule['json']['data']['id'] ?? 0;
check('I2 [T4] com regra de quantidade → aguardando_aprovacao', $rRule['status'] === 201 && ($rRule['json']['data']['status'] ?? '') === 'aguardando_aprovacao', $rRule);

$rNoJust = $admin->post("/api/requests/{$ruleId}/reject", []);
$rJust = $admin->post("/api/requests/{$ruleId}/reject", ['justification' => 'Fora da política do mês']);
check('I3 [T5] reprovar sem justificativa 422; com justificativa libera', code($rNoJust) === 'VALIDATION_ERROR' && ($rJust['json']['data']['status'] ?? '') === 'reprovada', [$rNoJust, $rJust]);

$rPick = $admin->post("/api/requests/{$autoId}/start-picking");
$rReady = $admin->post("/api/requests/{$autoId}/ready");
$sig = sigPng();
$rDel = $admin->postIdem("/api/requests/{$autoId}/deliver", [
    'received_by_name' => 'Carlos Receptor', 'received_by_email' => 'carlos@teste.local', 'signature' => $sig,
]);
$rDel2 = $admin->postIdem("/api/requests/{$autoId}/deliver", [
    'received_by_name' => 'Carlos Receptor', 'received_by_email' => 'carlos@teste.local', 'signature' => $sig,
]);
$blockedPick = $op->post("/api/requests/{$autoId}/start-picking");
$noTrade = $op->post('/api/trade/requests', [
    'industry_id' => $indId, 'purpose' => 'CD não cria', 'items' => [['item_id' => $cheapId, 'qty_requested' => 1]],
]);
$cadPage = $op->get('/cadastros/industrias');
$novaPage = $op->get('/solicitacoes-trade/nova');
$opPage = $op->get('/operacao');
check('I4 [T6] entrega gera protocolo e baixa; segunda entrega bloqueada', $rPick['status'] === 200 && $rReady['status'] === 200
    && $rDel['status'] === 201 && !empty($rDel['json']['data']['code']) && $rDel2['status'] === 409, [$rPick, $rReady, $rDel, $rDel2]);
check('I4cd CD/Estoque sem nova solicitação, cadastros e entregas', $blockedPick['status'] === 403 && $noTrade['status'] === 403
    && $cadPage['status'] === 403 && $novaPage['status'] === 403 && $opPage['status'] === 403,
    [$blockedPick['status'], $noTrade['status'], $cadPage['status'], $novaPage['status'], $opPage['status']]);
$delId = (int) ($rDel['json']['data']['id'] ?? 0);
$prot = $op->get("/api/deliveries/{$delId}");
$line = $prot['json']['data']['items'][0] ?? [];
$pdf = $op->get("/api/deliveries/{$delId}/pdf");
$pdfOk = $pdf['status'] === 200 && str_contains((string) ($pdf['headers']['Content-Type'] ?? ''), 'pdf');
$sigGet = $op->get("/api/deliveries/{$delId}/signature");
$sigOk = $sigGet['status'] === 200 && str_contains((string) ($sigGet['headers']['Content-Type'] ?? ''), 'png');
$sigStored = (string) Db::value('SELECT signature_png FROM deliveries WHERE id = ?', [$delId]);
check('I4b protocolo informa quantidade retirada, saldo restante e gera PDF com assinatura', $pdfOk && $sigOk
    && isset($line['qty_withdrawn'], $line['remaining'])
    && (int) $line['qty_withdrawn'] > 0
    && (int) $line['remaining'] >= 0
    && strlen($sigStored) > 80, [$prot, $pdf['status'], $pdf['headers']['Content-Type'] ?? '', $sigGet['status']]);
$today = date('Y-m-d');
$listed = $op->get("/api/deliveries?from={$today}&to={$today}");
$emptyRange = $op->get('/api/deliveries?from=2000-01-01&to=2000-01-02');
$listedIds = array_map('intval', array_column($listed['json']['data'] ?? [], 'id'));
$emptyIds = array_map('intval', array_column($emptyRange['json']['data'] ?? [], 'id'));
check('I4d comprovantes filtram por data', in_array($delId, $listedIds, true) && !in_array($delId, $emptyIds, true), [$listedIds, $emptyIds]);

$ev = $admin->post('/api/events', ['name' => 'Feira Teste', 'venue' => 'CD']);
$evId = $ev['json']['data']['id'] ?? 0;
$admin->put("/api/events/{$evId}/allocations", ['allocations' => [
    ['industry_id' => $indId, 'item_id' => $cheapId, 'qty_allocated' => 3],
]]);
$opened = $admin->post("/api/events/{$evId}/open");
$over = $op->postIdem("/api/events/{$evId}/withdrawals", [
    'industry_id' => $indId, 'received_by_name' => 'Rep Indústria', 'signature' => $sig,
    'items' => [['item_id' => $cheapId, 'qty' => 9]],
]);
$okW = $op->postIdem("/api/events/{$evId}/withdrawals", [
    'industry_id' => $indId, 'received_by_name' => 'Rep Indústria', 'received_by_email' => 'contato@alfa.example', 'signature' => $sig,
    'items' => [['item_id' => $cheapId, 'qty' => 1]],
]);
check('I5 [T7] evento: cota excedida 422; retirada válida gera protocolo', ($opened['json']['data']['status'] ?? '') === 'aberto'
    && code($over) === 'ALLOCATION_EXCEEDED' && $okW['status'] === 201 && str_starts_with((string) ($okW['json']['data']['code'] ?? ''), 'PROT-'), [$opened, $over, $okW]);

$ret = $admin->postIdem("/api/events/{$evId}/returns", [
    'items' => [['industry_id' => $indId, 'item_id' => $cheapId, 'qty' => 2]],
]);
$retSaldo = 0;
foreach ($ret['json']['data']['allocations'] ?? [] as $a) {
    if ((int) ($a['item']['id'] ?? 0) === (int) $cheapId) {
        $retSaldo = (int) ($a['saldo'] ?? -1);
    }
}
check('I5c devolução ao CD zera o saldo restante do evento', $ret['status'] === 200 && $retSaldo === 0, $ret);

$gev = $gestor->post('/api/events', ['name' => 'Evento do Gestor', 'venue' => 'CD']);
$gevId = $gev['json']['data']['id'] ?? 0;
$gAlloc = $gestor->put("/api/events/{$gevId}/allocations", ['allocations' => [
    ['industry_id' => $indId, 'item_id' => $cheapId, 'qty_allocated' => 2],
]]);
$gOpen = $gestor->post("/api/events/{$gevId}/open");
check('I5b gestor cria, define cotas e abre evento', $gev['status'] === 201 && $gAlloc['status'] === 200 && ($gOpen['json']['data']['status'] ?? '') === 'aberto', [$gev, $gAlloc, $gOpen]);

$mine = $admin->post('/api/requests', [
    'purpose' => 'Pedido do admin', 'items' => [['item_id' => $cheapId, 'qty_requested' => 1]], 'submit' => true,
]);
$otherId = $mine['json']['data']['id'] ?? 0;
$rSpy = $sol->get('/api/requests/' . $otherId);
check('I6 [T10] solicitante não vê pedido de outro usuário', $rSpy['status'] === 404, $rSpy);

$alertItem = $admin->post('/api/items', [
    'name' => 'Alerta Mínimo', 'category_id' => $catId, 'min_stock' => 5, 'initial_quantity' => 6,
])['json']['data']['id'] ?? 0;
\App\Services\NotificationService::stockAlert($alertItem, 'X', 'Alerta', 'low');
\App\Services\NotificationService::stockAlert($alertItem, 'X', 'Alerta', 'low');
$nUsers = (int) Db::value("SELECT COUNT(DISTINCT user_id) FROM notifications WHERE related_type = 'item' AND related_id = ? AND channel = 'in_app' AND subject LIKE 'Estoque mínimo%'", [$alertItem]);
$nCopies = (int) Db::value("SELECT COUNT(*) FROM notifications WHERE related_type = 'item' AND related_id = ? AND channel = 'in_app' AND subject LIKE 'Estoque mínimo%'", [$alertItem]);
check('I7 [T12] alerta de estoque mínimo só uma vez no dia (por usuário)', $nUsers >= 1 && $nCopies === $nUsers, [$nUsers, $nCopies]);

$rep = $admin->get('/api/reports/stock');
check('I8 relatório de estoque atual', $rep['status'] === 200 && isset($rep['json']['data']['columns'], $rep['json']['data']['rows'])
    && in_array('Indústria', $rep['json']['data']['columns'] ?? [], true), $rep);

// ==========================================================================
section('T. TRADE — compra, CD, estoque por indústria, transferência, QR');
// ==========================================================================
$cafe = $admin->post('/api/items', [
    'name' => 'Cafeteira TRADE', 'category_id' => $catId, 'unit_value' => 199, 'kind' => 'fisico',
])['json']['data']['id'] ?? 0;
$tr = $admin->post('/api/trade/requests', [
    'industry_id' => $indId,
    'purpose' => 'Feirão setembro — cafeteiras',
    'action_type' => 'feirao',
    'recipient' => 'Vendedores',
    'delivery_place' => 'CD Belford Roxo',
    'items' => [['item_id' => $cafe, 'qty_requested' => 100, 'unit_value' => 199]],
]);
check('T1 cria solicitação TRADE com código EME', $tr['status'] === 201 && str_starts_with((string) ($tr['json']['data']['code'] ?? ''), 'EME-')
    && ($tr['json']['data']['status'] ?? '') === 'solicitada'
    && empty($tr['json']['data']['purchase_ticket_no'])
    && empty($tr['json']['data']['invoice_no']), $tr);
$tid = $tr['json']['data']['id'] ?? 0;
$eme = $tr['json']['data']['public_code'] ?? $tr['json']['data']['code'] ?? '';

$noTicket = $admin->post("/api/trade/requests/{$tid}/approve", []);
check('T2a chamado é obrigatório na aprovação', $noTicket['status'] === 422, $noTicket);

$early = $op->postIdem("/api/trade/requests/{$tid}/receive", [
    'invoice_no' => 'NF-1001',
    'items' => [['item_id' => $cafe, 'qty' => 100]],
]);
check('T2b CD não recebe antes da aprovação', $early['status'] === 409 && code($early) === 'INVALID_TRANSITION', $early);

$forbidden = $sol->post("/api/trade/requests/{$tid}/approve", ['purchase_ticket_no' => 'CH-88']);
check('T2c TRADE/solicitante não preenche o chamado', $forbidden['status'] === 403, $forbidden);

$buy = $admin->post("/api/trade/requests/{$tid}/approve", ['purchase_ticket_no' => 'CH-88']);
check('T2 aprovação preenche o chamado (sem NF)', ($buy['json']['data']['status'] ?? '') === 'aguardando_recebimento'
    && ($buy['json']['data']['purchase_ticket_no'] ?? '') === 'CH-88'
    && empty($buy['json']['data']['invoice_no'])
    && empty($buy['json']['data']['invoice_url']), $buy);

$nfPath = tmpImage('png');
$rec = $op->postIdem("/api/trade/requests/{$tid}/receive", [
    'invoice_no' => 'NF-1001',
    'items' => [['item_id' => $cafe, 'qty' => 100]],
], null, ['invoice' => upload($nfPath, 'nf.png', 'image/png')]);
check('T3 NF anexada no CD alimenta estoque e deixa pronto para retirada', ($rec['json']['data']['status'] ?? '') === 'pronta'
    && (int) ($rec['json']['data']['items'][0]['qty_received'] ?? 0) === 100
    && !empty($rec['json']['data']['invoice_url']), $rec);

$nfGet = $admin->get("/api/trade/requests/{$tid}/invoice");
check('T3b download da NF anexada no CD', $nfGet['status'] === 200 && str_contains((string) ($nfGet['headers']['Content-Type'] ?? ''), 'image/png'), $nfGet);

$trRej = $admin->post('/api/trade/requests', [
    'industry_id' => $indId,
    'purpose' => 'Pedido a reprovar',
    'items' => [['item_id' => $cafe, 'qty_requested' => 1, 'unit_value' => 10]],
]);
$rejId = $trRej['json']['data']['id'] ?? 0;
$rej = $admin->post("/api/trade/requests/{$rejId}/reject", ['reason' => 'Fora do orçamento']);
check('T3c reprovação do pedido TRADE sem chamado', ($rej['json']['data']['status'] ?? '') === 'reprovada', $rej);

$byInd = $admin->get('/api/stock/by-industry');
$cafeRow = null;
foreach ($byInd['json']['data'] ?? [] as $row) {
    if ((int) ($row['item']['id'] ?? 0) === (int) $cafe) {
        $cafeRow = $row;
        break;
    }
}
check('T4 estoque por indústria: recebido 100, saldo 100 (não é campo manual)', $cafeRow && (int) $cafeRow['received'] === 100 && (int) $cafeRow['saldo'] === 100, $cafeRow);

$noName = $op->postIdem("/api/trade/requests/{$tid}/withdraw", [
    'received_by_name' => '',
    'signature' => $sig,
    'items' => [['item_id' => $cafe, 'qty' => 10]],
]);
check('T5 retirada sem identificação é bloqueada', $noName['status'] === 422, $noName);

$tooMuch = $op->postIdem("/api/trade/requests/{$tid}/withdraw", [
    'received_by_name' => 'João da Silva',
    'received_by_document' => '123.456.789-00',
    'signature' => $sig,
    'items' => [['item_id' => $cafe, 'qty' => 150]],
]);
check('T6 retirada maior que o saldo é bloqueada', code($tooMuch) === 'STOCK_INSUFFICIENT', $tooMuch);

$okRet = $op->postIdem("/api/trade/requests/{$tid}/withdraw", [
    'received_by_name' => 'João da Silva',
    'received_by_document' => '123.456.789-00',
    'recipient' => 'Vendedor do Feirão',
    'signature' => $sig,
    'items' => [['item_id' => $cafe, 'qty' => 20]],
]);
check('T7 retirada digital gera comprovante e baixa 20', $okRet['status'] === 201 && str_starts_with((string) ($okRet['json']['data']['code'] ?? ''), 'PROT-'), $okRet);

$byInd2 = $admin->get('/api/stock/by-industry');
$cafeRow2 = null;
foreach ($byInd2['json']['data'] ?? [] as $row) {
    if ((int) ($row['item']['id'] ?? 0) === (int) $cafe) {
        $cafeRow2 = $row;
        break;
    }
}
check('T8 após retirada o saldo da indústria é 80', $cafeRow2 && (int) $cafeRow2['saldo'] === 80 && (int) $cafeRow2['withdrawn'] === 20, $cafeRow2);

$evFair = $admin->post('/api/events', ['name' => 'Feirão Setembro 2026', 'venue' => 'Belford Roxo']);
$evFairId = $evFair['json']['data']['id'] ?? 0;
$tf = $op->postIdem('/api/stock/transfers', [
    'item_id' => $cafe, 'quantity' => 80, 'industry_id' => $indId, 'event_id' => $evFairId,
]);
check('T9 transferência não é retirada: saldo total continua 80', $tf['status'] === 201 && (int) ($tf['json']['data']['stock']['on_hand'] ?? -1) === 80, $tf);

$pos = $admin->get('/api/stock/positions?item_id=' . $cafe);
$cdQty = 0;
$evQty = 0;
foreach ($pos['json']['data'] ?? [] as $p) {
    if (($p['location']['kind'] ?? '') === 'cd') {
        $cdQty += (int) $p['qty'];
    }
    if (($p['location']['kind'] ?? '') === 'evento') {
        $evQty += (int) $p['qty'];
    }
}
check('T10 após transferência: CD 0 e Feirão 80', $cdQty === 0 && $evQty === 80, $pos);

$mug = $admin->post('/api/items', [
    'name' => 'Caneca escritório', 'category_id' => $catId, 'unit_value' => 20, 'initial_quantity' => 15,
])['json']['data']['id'] ?? 0;
$office = $admin->post('/api/locations', [
    'name' => 'Escritório São Paulo', 'kind' => 'outro', 'description' => 'Sede',
]);
$officeId = $office['json']['data']['id'] ?? 0;
$tfOffice = $op->postIdem('/api/stock/transfers', [
    'item_id' => $mug, 'quantity' => 15, 'industry_id' => $indId, 'to_location_id' => $officeId,
]);
check('T10b transferência do CD para o escritório', $tfOffice['status'] === 201 && $officeId > 0
    && (int) ($tfOffice['json']['data']['stock']['on_hand'] ?? -1) === 15, [$office, $tfOffice]);

$lu = $op->get('/api/trade/lookup?code=' . rawurlencode((string) $eme));
$qr = $admin->get("/api/trade/requests/{$tid}/qr");
check('T11 QR / código identifica a solicitação TRADE', ($lu['json']['data']['id'] ?? 0) === $tid && $qr['status'] === 200
    && (str_starts_with((string) $qr['body'], "\x89PNG") || str_contains((string) ($qr['headers']['Content-Type'] ?? ''), 'png')), [$lu, $qr['status'], $qr['headers']['Content-Type'] ?? '']);
$showQr = $admin->get("/api/requests/{$tid}");
check('T11b detalhe da solicitação traz o QR em PNG', str_starts_with((string) ($showQr['json']['data']['qr_png'] ?? ''), 'data:image/png'), $showQr['status']);

$betaId = $admin->post('/api/industries', ['name' => 'Indústria Beta'])['json']['data']['id'] ?? 0;
$trBeta = $admin->post('/api/trade/requests', [
    'industry_id' => $betaId,
    'purpose' => 'Pedido de outra indústria',
    'items' => [['item_id' => $cafe, 'qty_requested' => 1]],
]);
$admin->post('/api/users', [
    'name' => 'Portal Alfa', 'email' => 'alfa@teste.local', 'password' => 'Senha1234',
    'role_id' => 5, 'industry_id' => $indId, 'must_change_password' => false,
]);
$portal = new TestClient($app, '10.0.0.9');
$portal->login('alfa@teste.local', 'Senha1234');
$seen = $portal->get('/api/requests?flow=trade&per_page=50');
$seenIds = array_map('intval', array_column($seen['json']['data'] ?? [], 'id'));
$spyBeta = $portal->get('/api/requests/' . ($trBeta['json']['data']['id'] ?? 0));
check('T12 portal da indústria só vê os dados dela', in_array((int) $tid, $seenIds, true) && !in_array((int) ($trBeta['json']['data']['id'] ?? 0), $seenIds, true) && $spyBeta['status'] === 404, [$seen, $spyBeta]);

$repInd = $admin->get('/api/reports/stock_industry');
$repW = $admin->get('/api/reports/withdrawals');
$repEv = $admin->get('/api/reports/event_report?event_id=' . $evFairId);
check('T13 relatórios por indústria, retiradas e evento', $repInd['status'] === 200 && $repW['status'] === 200 && $repEv['status'] === 200, [$repInd['status'], $repW['status'], $repEv['status']]);

$r1 = $admin->post('/api/auth/logout');
$r2 = $admin->get('/api/auth/me');
check('H5 logout encerra a sessão', $r1['status'] === 200 && $r2['status'] === 401, [$r1, $r2]);

// ==========================================================================
section('Z. Integridade final');
// ==========================================================================
$bad = Db::fetchAll(
    'SELECT i.id, i.code, s.qty_on_hand, COALESCE(SUM(m.qty), 0) AS ledger
       FROM items i JOIN stock s ON s.item_id = i.id LEFT JOIN stock_movements m ON m.item_id = i.id
      GROUP BY i.id, i.code, s.qty_on_hand HAVING s.qty_on_hand <> ledger'
);
check('Z1 saldo de todo brinde = soma das movimentações (livro-razão)', $bad === [], $bad);
$lastBad = Db::value(
    'SELECT COUNT(*) FROM stock s JOIN stock_movements m ON m.id = (SELECT MAX(id) FROM stock_movements WHERE item_id = s.item_id)
      WHERE m.balance_after <> s.qty_on_hand'
);
check('Z2 balance_after da última movimentação = saldo atual', (int) $lastBad === 0, $lastBad);
$actions = Db::query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
$expected = ['create', 'update', 'delete', 'restore', 'purge', 'activate', 'deactivate', 'login', 'logout', 'password_change',
    'password_reset', 'photo_update', 'permissions_update', 'settings_update', 'stock_entrada', 'stock_saida', 'stock_ajuste',
    'stock_reserve', 'stock_release', 'submit', 'approve', 'reject', 'deliver', 'withdraw', 'open'];
$missingActions = array_values(array_diff($expected, $actions));
check('Z3 [T11] todas as ações relevantes geraram auditoria', $missingActions === [], implode(', ', $missingActions));

echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
echo sprintf('RESULTADO: %d aprovados, %d reprovados', $pass, $fail) . PHP_EOL;
if ($failures) {
    echo 'Falhas: ' . implode(' | ', $failures) . PHP_EOL;
}
exit($fail === 0 ? 0 : 1);
