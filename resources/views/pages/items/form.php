<?php
$id = (int) ($app['page']['params']['id'] ?? 0);
$isEdit = $id > 0;
ob_start(); ?>
<div class="card card-body" x-data="itemForm(<?= $id ?>)" x-init="init()">
  <form @submit.prevent="save">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Código</label>
        <input class="form-control" name="code" x-model="f.code" :placeholder="nextCode">
      </div>
      <div class="col-md-8">
        <label class="form-label">Nome *</label>
        <input class="form-control" name="name" x-model="f.name" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Categoria *</label>
        <select class="form-select" name="category_id" x-model="f.category_id" required>
          <option value="">Selecione</option>
          <template x-for="c in cats" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Local</label>
        <select class="form-select" x-model="f.location_id">
          <option value="">—</option>
          <template x-for="c in locs" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Fornecedor</label>
        <select class="form-select" x-model="f.supplier_id">
          <option value="">—</option>
          <template x-for="c in sups" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Tipo</label>
        <select class="form-select" x-model="f.kind">
          <option value="fisico">Produto físico</option>
          <option value="voucher">Voucher</option>
          <option value="cartao">Cartão pré-pago / crédito</option>
          <option value="outro">Outro</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Valor unitário (R$)</label>
        <input class="form-control" x-model="f.unit_value" placeholder="0,00">
      </div>
      <div class="col-md-3">
        <label class="form-label">Estoque mínimo</label>
        <input class="form-control" type="number" min="0" x-model="f.min_stock">
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" x-model="f.status">
          <option value="ativo">Ativo</option>
          <option value="inativo">Inativo</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Data de entrada</label>
        <input class="form-control" type="date" x-model="f.entry_date">
      </div>
      <div class="col-12">
        <label class="form-label">Descrição</label>
        <textarea class="form-control" rows="2" x-model="f.description"></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">Observações</label>
        <textarea class="form-control" rows="2" x-model="f.notes"></textarea>
      </div>
      <?php if (!$isEdit && can('stock.entry')): ?>
      <div class="col-md-4">
        <label class="form-label">Quantidade inicial</label>
        <input class="form-control" type="number" min="0" x-model="f.initial_quantity">
      </div>
      <?php endif; ?>
      <?php if ($isEdit): ?>
      <div class="col-md-6">
        <label class="form-label">Foto</label>
        <input class="form-control" type="file" accept="image/*" @change="photo = $event.target.files[0]">
        <img class="thumb mt-2" x-show="f.thumb_url" :src="f.thumb_url">
      </div>
      <?php endif; ?>
    </div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit" :disabled="saving">Salvar</button>
      <a class="btn btn-outline-secondary" href="<?= e(url('/brindes')) ?>">Cancelar</a>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function itemForm(id) {
  return {
    id, nextCode: '', cats: [], locs: [], sups: [], photo: null, saving: false,
    f: { code: '', name: '', category_id: '', location_id: '', supplier_id: '', kind: 'fisico', unit_value: '', min_stock: 0, status: 'ativo', entry_date: '', description: '', notes: '', initial_quantity: 0, thumb_url: null },
    async init() {
      const [c, l, s] = await Promise.all([
        Api.get('/api/categories', { all: 1 }), Api.get('/api/locations', { all: 1 }), Api.get('/api/suppliers', { all: 1 })
      ]);
      this.cats = c.data; this.locs = l.data; this.sups = s.data;
      if (id) {
        const { data } = await Api.get('/api/items/' + id);
        this.f = {
          code: data.code, name: data.name, category_id: data.category?.id || '', location_id: data.location?.id || '',
          supplier_id: data.supplier?.id || '', kind: data.kind || 'fisico', unit_value: data.unit_value ?? '', min_stock: data.min_stock,
          status: data.status, entry_date: data.entry_date || '', description: data.description || '', notes: data.notes || '',
          thumb_url: data.thumb_url
        };
      } else {
        const n = await Api.get('/api/items/next-code');
        this.nextCode = n.data.code;
      }
    },
    body() {
      const b = { ...this.f };
      ['category_id','location_id','supplier_id'].forEach(k => { b[k] = b[k] ? Number(b[k]) : null; });
      b.min_stock = Number(b.min_stock || 0);
      if (this.f.initial_quantity) b.initial_quantity = Number(this.f.initial_quantity);
      return b;
    },
    async save() {
      this.saving = true;
      try {
        let item;
        if (this.id) { const r = await Api.put('/api/items/' + this.id, this.body()); item = r.data; }
        else { const r = await Api.post('/api/items', this.body()); item = r.data; this.id = item.id; }
        if (this.photo) {
          const fd = new FormData(); fd.append('photo', this.photo);
          await Api.upload('/api/items/' + item.id + '/photo', fd);
        }
        UI.toast('Brinde salvo. Ele já aparece na lista de brindes.', 'ok');
        location.href = Api.url('/brindes/' + item.id);
      } catch (e) {
        if (e && e.code === 'VALIDATION_ERROR') UI.fieldErrors(document.querySelector('form'), e.fields);
        else UI.toast(e.message, 'err');
      }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
