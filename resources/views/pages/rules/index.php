<?php ob_start(); ?>
<div x-data="rulesPage()" x-init="load()">
  <form class="card card-body mb-3" @submit.prevent="create">
    <div class="row g-2">
      <div class="col-md-3"><input class="form-control" placeholder="Nome" x-model="n.name" required></div>
      <div class="col-md-2">
        <select class="form-select" x-model="n.criterion">
          <option value="valor">Valor</option>
          <option value="quantidade">Quantidade</option>
          <option value="categoria">Categoria</option>
          <option value="departamento">Departamento</option>
          <option value="perfil">Perfil</option>
        </select>
      </div>
      <div class="col-md-1">
        <select class="form-select" x-model="n.operator"><option>></option><option>>=</option><option>=</option></select>
      </div>
      <div class="col-md-2"><input class="form-control" placeholder="Valor" x-model="n.value" required></div>
      <div class="col-md-2">
        <select class="form-select" x-model="n.approver_role_id">
          <option value="">Perfil aprovador</option>
          <template x-for="r in roles" :key="r.id"><option :value="r.id" x-text="r.name"></option></template>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Incluir</button></div>
    </div>
  </form>
  <template x-for="r in rows" :key="r.id">
    <div class="card card-body mb-2 d-flex flex-row justify-content-between">
      <div>
        <b x-text="r.name"></b>
        <div class="small" x-text="r.criterion + ' ' + r.operator + ' ' + r.value + ' → ' + (r.approver_role?.name || r.approver_user?.name)"></div>
      </div>
      <button class="btn btn-sm btn-outline-danger" @click="del(r)">Excluir</button>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function rulesPage() {
  return {
    rows: [], roles: [], n: { name: '', criterion: 'valor', operator: '>', value: '500', approver_role_id: '2' },
    async load() {
      this.rows = (await Api.get('/api/approval-rules')).data;
      this.roles = (await Api.get('/api/roles')).data;
    },
    async create() {
      try {
        await Api.post('/api/approval-rules', { ...this.n, approver_role_id: Number(this.n.approver_role_id) });
        this.load();
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async del(r) {
      if (!await UI.confirm('Excluir regra?')) return;
      await Api.del('/api/approval-rules/' + r.id); this.load();
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
