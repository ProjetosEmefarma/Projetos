<?php ob_start(); ?>
<form id="forgotForm">
  <h2 class="h5 mb-3">Esqueci minha senha</h2>
  <div class="mb-3">
    <label class="form-label">E-mail</label>
    <input class="form-control" type="email" name="email" required autofocus>
  </div>
  <button class="btn btn-primary w-100" type="submit">Enviar instruções</button>
  <p class="mt-3 mb-0 text-center"><a href="<?= e(url('/login')) ?>">Voltar ao login</a></p>
</form>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
document.getElementById('forgotForm').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  try {
    const { data } = await Api.post('/api/auth/forgot', Object.fromEntries(new FormData(this)));
    UI.toast(data.message, 'ok');
  } catch (e) { UI.toast(e.message, 'err'); }
});
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/auth.php';
