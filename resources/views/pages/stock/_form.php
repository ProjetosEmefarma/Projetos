<?php
/** @var string $mode entrada|saida|ajuste */
$mode = $mode ?? 'entrada';
$endpoint = match ($mode) {
    'saida' => '/api/stock/exits',
    'ajuste' => '/api/stock/adjustments',
    default => '/api/stock/entries',
};
$titleBtn = match ($mode) {
    'saida' => 'Registrar saída',
    'ajuste' => 'Registrar ajuste',
    default => 'Registrar entrada',
};
ob_start(); ?>
<?php if ($mode === 'saida'): ?>
<p class="text-muted mb-3">Registre a saída aqui: escolha o brinde, a quantidade e a finalidade. Depois clique em <b>Registrar saída</b>. O CD só confirma no depósito (menu <b>Confirmar saída</b>, com QR).</p>
<?php endif; ?>
<div class="card card-body" style="max-width:640px" x-data="stockForm()" x-init="init()" data-mode="<?= e($mode) ?>" data-endpoint="<?= e($endpoint) ?>">
  <template x-if="done">
    <div>
      <div class="alert alert-success" x-show="done.order">
        Saída registrada. O CD confirma no menu <b>Confirmar saída</b> (QR abaixo). Disponível agora: <b x-text="done.stock.available"></b>
      </div>
      <div class="alert alert-success" x-show="!done.order">Movimentação registrada. Novo saldo: <b x-text="done.stock.available"></b></div>
      <div class="mb-3" x-show="done.order">
        <div class="fw-semibold" x-text="done.order.code"></div>
        <img x-show="done.order.qr_png" :src="done.order.qr_png" alt="QR" width="200" height="200" class="bg-white p-2 rounded my-2" style="image-rendering:pixelated">
      </div>
      <a class="btn btn-outline-primary" :href="'<?= e(url('/brindes')) ?>/' + ((done.order && done.order.item && done.order.item.id) || (done.movement && done.movement.item && done.movement.item.id))">Ver brinde</a>
      <a class="btn btn-outline-secondary" x-show="(done.order && done.order.attachment_url) || (done.movement && done.movement.attachment_url)" :href="(done.order && done.order.attachment_url) || (done.movement && done.movement.attachment_url)" target="_blank">Ver nota anexada</a>
      <button class="btn btn-primary" type="button" @click="reset()">Nova movimentação</button>
    </div>
  </template>
  <form x-show="!done" @submit.prevent="save">
    <div class="mb-3">
      <label class="form-label">Brinde *</label>
      <input class="form-control mb-2" placeholder="Digite o código ou o nome" x-model="q" @input="search()" autocomplete="off">
      <div class="list-group mb-2" style="max-height:220px;overflow:auto" x-show="opts.length">
        <template x-for="o in opts" :key="o.id">
          <button type="button" class="list-group-item list-group-item-action" @click="pick(o)">
            <span x-text="o.code + ' — ' + o.name + ' (disp. ' + o.available + ')'"></span>
          </button>
        </template>
      </div>
      <div class="alert alert-light" x-show="item" x-text="item ? (item.code + ' — ' + item.name + ' · disponível ' + item.available) : ''"></div>
      <div class="form-text">Digite para buscar, toque no brinde da lista e preencha quantidade e finalidade abaixo.</div>
    </div>
    <?php if ($mode === 'ajuste'): ?>
    <div class="mb-3">
      <label class="form-label">Modo</label>
      <select class="form-select" x-model="f.mode">
        <option value="set">Contagem (quantidade contada)</option>
        <option value="delta">Somar / subtrair</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="mb-3">
      <label class="form-label">Quantidade *</label>
      <input class="form-control qty-lg" type="number" min="1" x-model.number="f.quantity" required>
    </div>
    <?php if ($mode === 'entrada'): ?>
    <div class="mb-3"><label class="form-label">Indústria</label>
      <select class="form-select" x-model="f.industry_id"><option value="">—</option>
        <template x-for="i in industries" :key="i.id"><option :value="i.id" x-text="i.name"></option></template>
      </select>
    </div>
    <div class="mb-3"><label class="form-label">Valor unitário</label><input class="form-control" x-model="f.unit_value"></div>
    <div class="mb-3"><label class="form-label">Nº chamado de compra</label><input class="form-control" x-model="f.purchase_ticket_no"></div>
    <div class="mb-3"><label class="form-label">NF / documento (número)</label><input class="form-control" x-model="f.document_ref" placeholder="Nº da nota"></div>
    <div class="mb-3">
      <label class="form-label">Anexar nota (PDF, JPG ou PNG)</label>
      <input class="form-control" type="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" @change="invoice = $event.target.files[0]">
      <div class="form-text" x-show="invoice" x-text="invoice ? ('Arquivo: ' + invoice.name) : ''"></div>
    </div>
    <?php endif; ?>
    <?php if ($mode === 'saida'): ?>
    <div class="mb-3"><label class="form-label">Finalidade *</label><input class="form-control" name="purpose" x-model="f.purpose" required></div>
    <div class="mb-3"><label class="form-label">Destinatário</label><input class="form-control" x-model="f.recipient"></div>
    <div class="mb-3"><label class="form-label">Indústria</label>
      <select class="form-select" x-model="f.industry_id"><option value="">—</option>
        <template x-for="i in industries" :key="i.id"><option :value="i.id" x-text="i.name"></option></template>
      </select>
    </div>
    <div class="mb-3"><label class="form-label">Departamento</label>
      <select class="form-select" x-model="f.department_id"><option value="">—</option>
        <template x-for="i in departments" :key="i.id"><option :value="i.id" x-text="i.name"></option></template>
      </select>
    </div>
    <div class="mb-3"><label class="form-label">NF / documento (número)</label><input class="form-control" x-model="f.document_ref" placeholder="Nº da nota"></div>
    <div class="mb-3">
      <label class="form-label">Anexar nota (PDF, JPG ou PNG)</label>
      <input class="form-control" type="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" @change="invoice = $event.target.files[0]">
      <div class="form-text" x-show="invoice" x-text="invoice ? ('Arquivo: ' + invoice.name) : ''"></div>
    </div>
    <?php endif; ?>
    <?php if ($mode === 'ajuste'): ?>
    <div class="mb-3"><label class="form-label">Motivo *</label><input class="form-control" name="reason" x-model="f.reason" required></div>
    <?php endif; ?>
    <div class="mb-3"><label class="form-label">Observações</label><textarea class="form-control" x-model="f.notes"></textarea></div>
    <p class="small text-danger" x-show="err" x-text="err"></p>
    <button class="btn btn-primary btn-lg w-100" type="submit" :disabled="saving"><?= e($titleBtn) ?></button>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function stockForm() {
  const pre = new URLSearchParams(location.search).get('item_id');
  return {
    mode: '', endpoint: '', q: '', opts: [], item: null, industries: [], departments: [],
    saving: false, done: null, err: '', key: Api.newKey(), invoice: null,
    f: { quantity: 1, mode: 'set', purpose: '', recipient: '', industry_id: '', department_id: '', reason: '', notes: '', unit_value: '', purchase_ticket_no: '', document_ref: '' },
    async init() {
      this.mode = (this.$el && this.$el.dataset && this.$el.dataset.mode) || 'entrada';
      this.endpoint = (this.$el && this.$el.dataset && this.$el.dataset.endpoint) || '/api/stock/entries';
      try {
        if (this.mode === 'saida' || this.mode === 'entrada') {
          this.industries = (await Api.get('/api/industries', { all: 1 })).data || [];
        }
        if (this.mode === 'saida') {
          this.departments = (await Api.get('/api/departments', { all: 1 })).data || [];
        }
        if (pre) {
          const { data } = await Api.get('/api/items/' + pre);
          this.pick({ id: data.id, code: data.code, name: data.name, available: data.stock.available });
        }
      } catch (e) {
        this.err = e.message || 'Não foi possível abrir o formulário.';
      }
    },
    t: null,
    picking: false,
    search() {
      if (this.picking) { this.picking = false; return; }
      this.item = null;
      clearTimeout(this.t);
      this.t = setTimeout(async () => {
        try {
          const q = (this.q || '').trim();
          if (q.length < 1) { this.opts = []; return; }
          this.opts = (await Api.get('/api/items/options', { q })).data || [];
        } catch (e) { UI.toast(e.message, 'err'); }
      }, 250);
    },
    pick(o) {
      this.picking = true;
      this.item = o;
      this.q = o.code + ' — ' + o.name;
      this.opts = [];
    },
    reset() { this.done = null; this.key = Api.newKey(); this.f.quantity = 1; this.err = ''; this.invoice = null; },
    async save() {
      this.err = '';
      if (!this.item) { this.err = 'Selecione um brinde na lista.'; UI.toast(this.err, 'err'); return; }
      if (!this.f.quantity || Number(this.f.quantity) < 1) { this.err = 'Informe a quantidade.'; UI.toast(this.err, 'err'); return; }
      this.saving = true;
      const fd = new FormData();
      fd.append('item_id', String(this.item.id));
      fd.append('quantity', String(this.f.quantity));
      if (this.f.notes) fd.append('notes', this.f.notes);
      if (this.mode === 'entrada' || this.mode === 'saida') {
        if (this.f.document_ref) fd.append('document_ref', this.f.document_ref);
        if (this.f.industry_id) fd.append('industry_id', String(this.f.industry_id));
      }
      if (this.mode === 'entrada') {
        if (this.f.unit_value) fd.append('unit_value', this.f.unit_value);
        if (this.f.purchase_ticket_no) fd.append('purchase_ticket_no', this.f.purchase_ticket_no);
      }
      if (this.mode === 'saida') {
        fd.append('purpose', this.f.purpose);
        if (this.f.recipient) fd.append('recipient', this.f.recipient);
        if (this.f.department_id) fd.append('department_id', String(this.f.department_id));
      }
      if (this.mode === 'ajuste') {
        fd.append('mode', this.f.mode);
        fd.append('reason', this.f.reason);
      }
      if (this.invoice) fd.append('invoice', this.invoice);
      try {
        const { data } = await Api.upload(this.endpoint, fd, { idempotencyKey: this.key });
        this.done = data;
        UI.toast('Movimentação registrada.', 'ok');
      } catch (e) {
        this.err = e.message;
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
