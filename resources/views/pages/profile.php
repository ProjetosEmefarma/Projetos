<?php ob_start(); ?>
<div class="row g-4" x-data="profilePage()">
  <div class="col-lg-6">
    <div class="card card-body">
      <h2 class="h6">Meus dados</h2>
      <form @submit.prevent="saveProfile">
        <div class="mb-3"><label class="form-label">Nome</label>
          <input class="form-control" x-model="name" required></div>
        <div class="mb-3"><label class="form-label">Telefone</label>
          <input class="form-control" x-model="phone"></div>
        <button class="btn btn-primary" type="submit">Salvar</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card card-body">
      <h2 class="h6">Trocar senha</h2>
      <?php if (!empty($app['user']['must_change_password']) || (($app['page']['query']['trocar-senha'] ?? '') === '1')): ?>
        <div class="alert alert-warning">Troca de senha obrigatória no primeiro acesso.</div>
      <?php endif; ?>
      <form @submit.prevent="savePassword">
        <div class="mb-3"><label class="form-label">Senha atual</label>
          <input class="form-control" type="password" x-model="current" required></div>
        <div class="mb-3"><label class="form-label">Nova senha</label>
          <input class="form-control" type="password" x-model="newp" required></div>
        <div class="mb-3"><label class="form-label">Confirmar</label>
          <input class="form-control" type="password" x-model="conf" required></div>
        <button class="btn btn-primary" type="submit">Salvar nova senha</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function profilePage() {
  return {
    name: <?= json_script($app['user']['name'] ?? '') ?>,
    phone: <?= json_script($app['user']['phone'] ?? '') ?>,
    current: '', newp: '', conf: '',
    async saveProfile() {
      try { await Api.put('/api/auth/profile', { name: this.name, phone: this.phone }); UI.toast('Perfil atualizado.', 'ok'); }
      catch (e) { UI.toast(e.message, 'err'); }
    },
    async savePassword() {
      try {
        await Api.post('/api/auth/change-password', {
          current_password: this.current, new_password: this.newp, new_password_confirmation: this.conf
        });
        UI.toast('Senha alterada.', 'ok');
        if (new URLSearchParams(location.search).get('trocar-senha')) location.href = Api.url('/dashboard');
      } catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
