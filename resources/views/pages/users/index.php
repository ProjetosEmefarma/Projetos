<?php ob_start(); ?>
<div x-data="usersPage()" x-init="load()">
  <div class="d-flex mb-3"><button class="btn btn-primary ms-auto" @click="open()">Novo usuário</button></div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th></th></tr></thead>
      <tbody>
        <template x-for="u in rows" :key="u.id">
          <tr>
            <td x-text="u.name"></td>
            <td x-text="u.email"></td>
            <td x-text="u.role.name"></td>
            <td x-text="u.active ? 'Sim' : 'Não'"></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" @click="open(u)">Editar</button>
              <button class="btn btn-sm btn-outline-secondary" @click="toggle(u)" x-text="u.active ? 'Inativar' : 'Ativar'"></button>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" @submit.prevent="save">
    <div class="modal-header"><h5 class="modal-title">Usuário</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Nome</label><input class="form-control" x-model="f.name" required></div>
      <div class="mb-2"><label class="form-label">E-mail</label><input class="form-control" type="email" x-model="f.email" required></div>
      <div class="mb-2"><label class="form-label">Perfil</label>
        <select class="form-select" x-model="f.role_id">
          <template x-for="r in roles" :key="r.id"><option :value="r.id" x-text="r.name"></option></template>
        </select>
      </div>
      <div class="mb-2"><label class="form-label">Departamento</label>
        <select class="form-select" x-model="f.department_id"><option value="">—</option>
          <template x-for="d in depts" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
        </select>
      </div>
      <div class="mb-2" x-show="isIndustry">
        <label class="form-label">Indústria (portal da parceira)</label>
        <select class="form-select" x-model="f.industry_id"><option value="">—</option>
          <template x-for="d in inds" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
        </select>
      </div>
      <div class="mb-2"><label class="form-label">Senha</label><input class="form-control" type="password" x-model="f.password" :required="!editId"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Salvar</button></div>
  </form></div></div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function usersPage() {
  return {
    rows: [], roles: [], depts: [], inds: [], f: {}, editId: null, modal: null,
    get isIndustry() {
      const r = this.roles.find(x => x.id == this.f.role_id);
      return r && r.slug === 'industry';
    },
    async load() {
      this.modal = this.modal || new bootstrap.Modal(document.getElementById('userModal'));
      this.rows = (await Api.get('/api/users')).data;
      this.roles = (await Api.get('/api/roles')).data;
      this.depts = (await Api.get('/api/departments', { all: 1 })).data;
      this.inds = (await Api.get('/api/industries', { all: 1 })).data;
    },
    open(u) {
      this.editId = u ? u.id : null;
      this.f = u ? { name: u.name, email: u.email, role_id: u.role.id, department_id: u.department?.id || '', industry_id: u.industry?.id || '', password: '' } : { name: '', email: '', role_id: 4, department_id: '', industry_id: '', password: '' };
      this.modal.show();
    },
    async save() {
      const body = { ...this.f, role_id: Number(this.f.role_id), department_id: this.f.department_id ? Number(this.f.department_id) : null, industry_id: this.f.industry_id ? Number(this.f.industry_id) : null, must_change_password: true };
      if (!body.password) delete body.password;
      try {
        if (this.editId) await Api.put('/api/users/' + this.editId, body);
        else await Api.post('/api/users', body);
        this.modal.hide(); this.load();
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async toggle(u) {
      try { await Api.post('/api/users/' + u.id + (u.active ? '/deactivate' : '/activate')); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
