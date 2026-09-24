<?php ob_start(); ?>
<div class="card card-body" x-data="reqForm()" x-init="init()">
  <form @submit.prevent="save">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Finalidade *</label><input class="form-control" x-model="f.purpose" required></div>
      <div class="col-md-6"><label class="form-label">Destinatário</label><input class="form-control" x-model="f.recipient"></div>
      <div class="col-md-4"><label class="form-label">Departamento</label>
        <select class="form-select" x-model="f.department_id"><option value="">—</option>
          <template x-for="d in depts" :key="d.id"><option :value="d.id" x-text="d.name"></option></template></select></div>
      <div class="col-md-4"><label class="form-label">Indústria</label>
        <select class="form-select" x-model="f.industry_id"><option value="">—</option>
          <template x-for="d in inds" :key="d.id"><option :value="d.id" x-text="d.name"></option></template></select></div>
      <div class="col-md-4"><label class="form-label">Data necessária</label><input class="form-control" type="date" x-model="f.needed_date"></div>
      <div class="col-md-4"><label class="form-label">Nº chamado de compra</label><input class="form-control" x-model="f.purchase_ticket_no"></div>
      <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" x-model="f.notes"></textarea></div>
    </div>
    <h3 class="h6 mt-4">Brindes</h3>
    <div class="d-flex gap-2 mb-2">
      <input class="form-control" placeholder="Buscar brinde" x-model="q" @input="search()">
    </div>
    <div class="list-group mb-2">
      <template x-for="o in opts" :key="o.id">
        <button type="button" class="list-group-item" @click="add(o)" x-text="o.code + ' — ' + o.name + ' (disp. ' + o.available + ')'"></button>
      </template>
    </div>
    <template x-for="(line, i) in items" :key="line.item_id">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="flex-grow-1" x-text="line.name"></span>
        <span class="small text-muted" x-text="'disp. ' + line.available"></span>
        <input class="form-control" style="width:90px" type="number" min="1" x-model.number="line.qty_requested">
        <button type="button" class="btn btn-sm btn-outline-danger" @click="items.splice(i,1)">×</button>
      </div>
    </template>
    <div class="alert alert-warning mt-2" x-show="needsPurchase">Alguns itens não têm estoque suficiente (Requer compra).</div>
    <button class="btn btn-primary mt-3" :disabled="saving">Enviar solicitação</button>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function reqForm() {
  return {
    f: { purpose: '', recipient: '', department_id: '', industry_id: '', needed_date: '', purchase_ticket_no: '', notes: '' },
    depts: [], inds: [], q: '', opts: [], items: [], saving: false, needsPurchase: false, t: null,
    async init() {
      this.depts = (await Api.get('/api/departments', { all: 1 })).data;
      this.inds = (await Api.get('/api/industries', { all: 1 })).data;
    },
    search() {
      clearTimeout(this.t);
      this.t = setTimeout(async () => {
        this.opts = this.q ? (await Api.get('/api/items/options', { q: this.q })).data : [];
      }, 250);
    },
    add(o) {
      if (this.items.find(i => i.item_id === o.id)) return;
      this.items.push({ item_id: o.id, name: o.code + ' — ' + o.name, qty_requested: 1, available: o.available });
      this.opts = []; this.q = ''; this.check();
    },
    async check() {
      if (!this.items.length) return;
      const { data } = await Api.post('/api/requests/check-availability', { items: this.items });
      this.needsPurchase = !!data.needs_purchase;
    },
    async save() {
      this.saving = true;
      try {
        const { data } = await Api.post('/api/requests', {
          ...this.f,
          department_id: this.f.department_id ? Number(this.f.department_id) : null,
          industry_id: this.f.industry_id ? Number(this.f.industry_id) : null,
          submit: true,
          items: this.items.map(i => ({ item_id: i.item_id, qty_requested: Number(i.qty_requested) }))
        });
        location.href = Api.url('/solicitacoes/' + data.id);
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
