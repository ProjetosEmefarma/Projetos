<?php ob_start(); ?>
<form id="resetForm">
  <h2 class="h5 mb-3">Redefinir senha</h2>
  <input type="hidden" name="token" value="<?= e($app['page']['query']['token'] ?? '') ?>">
  <div class="mb-3">
    <label class="form-label">Nova senha</label>
    <input class="form-control" type="password" name="password" autocomplete="new-password" required>
  </div>
  <div class="mb-3">
    <label class="form-label">Confirmar nova senha</label>
    <input class="form-control" type="password" name="password_confirmation" required>
  </div>
  <button class="btn btn-primary w-100" type="submit">Redefinir</button>
</form>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
document.getElementById('resetForm').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  try {
    const { data } = await Api.post('/api/auth/reset', Object.fromEntries(new FormData(this)));
    UI.toast(data.message, 'ok');
    setTimeout(function () { location.href = Api.url('/login'); }, 1200);
  } catch (e) { UI.toast(e.message, 'err'); UI.fieldErrors(this, e.fields || {}); }
});
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/auth.php';
