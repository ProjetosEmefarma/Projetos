<?php
/**
 * Developer placeholder, used only while resources/views/<view>.php does not exist.
 * Keeps login, password reset and the forced password change usable before the
 * real screens are built. Not part of the final UI.
 *
 * @var array $app
 */
$user = $app['user'];
$settings = $app['settings'];
$path = $app['page']['path'];
$view = $app['missing_view'] ?? $app['page']['view'];
$error = $app['error'] ?? null;
$color = e($settings['primary_color'] ?? '#2563EB');
$form = match (true) {
    $path === '/login' => 'login',
    $path === '/esqueci-senha' => 'forgot',
    $path === '/redefinir-senha' => 'reset',
    $path === '/perfil' => 'password',
    default => null,
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($app['csrf']) ?>">
<meta name="base-url" content="<?= e($app['base_url']) ?>">
<title><?= e(($app['page']['title'] ?: 'Página') . ' - ' . $settings['company_name']) ?></title>
<style>
  *{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f5f7;color:#1f2937}
  header{background:<?= $color ?>;color:#fff;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
  header b{font-size:17px} header button{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:6px;padding:6px 12px;cursor:pointer}
  main{max-width:960px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border-radius:10px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:16px}
  .narrow{max-width:400px;margin:48px auto}
  label{display:block;font-size:14px;margin:12px 0 4px} input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:15px}
  .btn{margin-top:16px;width:100%;padding:11px;border:0;border-radius:6px;background:<?= $color ?>;color:#fff;font-size:15px;cursor:pointer}
  .msg{margin-top:12px;font-size:14px}.err{color:#b91c1c}.ok{color:#047857}
  .tag{display:inline-block;background:#fef3c7;color:#92400e;border-radius:4px;padding:2px 8px;font-size:12px}
  code{background:#f3f4f6;padding:2px 6px;border-radius:4px} a{color:<?= $color ?>}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px} .grid a{display:block;padding:8px 10px;background:#f9fafb;border-radius:6px;text-decoration:none}
  h3{margin:18px 0 8px;font-size:13px;text-transform:uppercase;color:#6b7280}
</style>
</head>
<body>
<header>
  <b><?= e($settings['company_name']) ?></b>
  <?php if ($user): ?>
    <span><?= e($user['name']) ?> (<?= e($user['role']['name']) ?>) <button type="button" id="logout">Sair</button></span>
  <?php endif; ?>
</header>
<main>
<?php if ($error): ?>
  <div class="card narrow"><h2>Erro <?= (int) $error['status'] ?></h2><p><?= e($error['message']) ?></p><p><a href="<?= e(url('/')) ?>">Voltar ao início</a></p></div>
<?php elseif ($form === 'login'): ?>
  <form class="card narrow" data-endpoint="/api/auth/login" data-success="login">
    <h2>Entrar</h2>
    <label>E-mail</label><input name="email" type="email" autocomplete="username" required autofocus>
    <label>Senha</label><input name="password" type="password" autocomplete="current-password" required>
    <button class="btn">Entrar</button>
    <p class="msg"></p>
    <p><a href="<?= e(url('/esqueci-senha')) ?>">Esqueci minha senha</a></p>
  </form>
<?php elseif ($form === 'forgot'): ?>
  <form class="card narrow" data-endpoint="/api/auth/forgot" data-success="message">
    <h2>Esqueci minha senha</h2>
    <label>E-mail</label><input name="email" type="email" required autofocus>
    <button class="btn">Enviar instruções</button><p class="msg"></p>
    <p><a href="<?= e(url('/login')) ?>">Voltar ao login</a></p>
  </form>
<?php elseif ($form === 'reset'): ?>
  <form class="card narrow" data-endpoint="/api/auth/reset" data-success="message">
    <h2>Redefinir senha</h2>
    <input type="hidden" name="token" value="<?= e($app['page']['query']['token'] ?? '') ?>">
    <label>Nova senha</label><input name="password" type="password" autocomplete="new-password" required>
    <label>Confirmar nova senha</label><input name="password_confirmation" type="password" autocomplete="new-password" required>
    <button class="btn">Redefinir</button><p class="msg"></p>
    <p><a href="<?= e(url('/login')) ?>">Ir para o login</a></p>
  </form>
<?php elseif ($form === 'password'): ?>
  <form class="card narrow" data-endpoint="/api/auth/change-password" data-success="dashboard">
    <h2>Trocar senha</h2>
    <?php if ($user['must_change_password']): ?><p class="tag">Troca de senha obrigatória no primeiro acesso</p><?php endif; ?>
    <label>Senha atual</label><input name="current_password" type="password" autocomplete="current-password" required>
    <label>Nova senha (mín. 8, letras e números)</label><input name="new_password" type="password" autocomplete="new-password" required>
    <label>Confirmar nova senha</label><input name="new_password_confirmation" type="password" autocomplete="new-password" required>
    <button class="btn">Salvar nova senha</button><p class="msg"></p>
  </form>
<?php else: ?>
  <div class="card">
    <span class="tag">Tela em construção</span>
    <h2><?= e($app['page']['title']) ?></h2>
    <p>A interface desta página será criada em <code>resources/views/<?= e($view) ?>.php</code>. A API já está disponível.</p>
  </div>
  <?php if ($app['menu']): ?>
  <div class="card">
    <?php foreach ($app['menu'] as $group): ?>
      <h3><?= e($group['group']) ?></h3>
      <div class="grid">
        <?php foreach ($group['items'] as $item): ?>
          <a href="<?= e($item['url']) ?>"><?= e($item['label']) ?><?= $item['soon'] ? ' <span class="tag">em breve</span>' : '' ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
</main>
<script>
(function () {
  const base = document.querySelector('meta[name="base-url"]').content;
  let csrf = document.querySelector('meta[name="csrf-token"]').content;
  async function post(endpoint, body) {
    const res = await fetch(base + endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json'},
      body: JSON.stringify(body)
    });
    return res.json();
  }
  document.querySelectorAll('form[data-endpoint]').forEach(function (form) {
    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const btn = form.querySelector('button'); const msg = form.querySelector('.msg');
      btn.disabled = true; msg.className = 'msg'; msg.textContent = 'Aguarde...';
      try {
        const json = await post(form.dataset.endpoint, Object.fromEntries(new FormData(form)));
        if (!json.ok) {
          const fields = json.error.fields ? ' ' + Object.values(json.error.fields).join(' ') : '';
          msg.className = 'msg err'; msg.textContent = json.error.message + fields; return;
        }
        if (form.dataset.success === 'message') { msg.className = 'msg ok'; msg.textContent = json.data.message; return; }
        const next = new URLSearchParams(location.search).get('next');
        location.href = base + (next && next.startsWith('/') && !next.startsWith('//') ? next : '/dashboard');
      } catch (e) { msg.className = 'msg err'; msg.textContent = 'Falha de conexão. Tente novamente.'; }
      finally { btn.disabled = false; }
    });
  });
  const logout = document.getElementById('logout');
  if (logout) logout.addEventListener('click', async function () { await post('/api/auth/logout', {}); location.href = base + '/login'; });
})();
</script>
</body>
</html>
