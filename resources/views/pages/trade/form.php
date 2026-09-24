<?php ob_start(); ?>
<div class="card card-body" x-data="tradeForm()" x-init="init()">
  <p class="text-muted">Informe indústria, brinde, quantidade e finalidade. O número do chamado é preenchido só se o pedido for aprovado. A NF é anexada quando o material chegar no CD.</p>
  <form @submit.prevent="save">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Indústria *</label>
        <select class="form-select" x-model="f.industry_id" required>
          <option value="">Selecione</option>
          <template x-for="d in inds" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
        </select></div>
      <div class="col-md-6"><label class="form-label">Tipo de ação</label>
        <select class="form-select" x-model="f.action_type">
          <option value="campanha">Campanha</option>
          <option value="premiacao">Premiação</option>
          <option value="evento">Evento</option>
          <option value="feirao">Feirão</option>
          <option value="outro">Outro</option>
        </select></div>
      <div class="col-md-6"><label class="form-label">Descrição / finalidade *</label>
        <input class="form-control" x-model="f.purpose" required placeholder="Ex.: Air Fryer 5L — campanha setembro"></div>
      <div class="col-md-6"><label class="form-label">Para quem será entregue</label>
        <input class="form-control" x-model="f.recipient" placeholder="Vendedor, cliente, evento…"></div>
      <div class="col-md-6"><label class="form-label">Local de entrega</label>
        <input class="form-control" x-model="f.delivery_place" placeholder="CD Belford Roxo"></div>
      <div class="col-12"><label class="form-label">Observações</label>
        <textarea class="form-control" x-model="f.notes"></textarea></div>
    </div>
    <h3 class="h6 mt-4">Brindes</h3>
    <input class="form-control mb-2" placeholder="Buscar brinde (físico, voucher, cartão…)" x-model="q" @input="search()">
    <div class="list-group mb-2">
      <template x-for="o in opts" :key="o.id">
        <button type="button" class="list-group-item" @click="add(o)" x-text="o.code + ' — ' + o.name"></button>
      </template>
    </div>
    <template x-for="(line, i) in items" :key="line.item_id">
      <div class="row g-2 align-items-center mb-2">
        <div class="col-md-5" x-text="line.name"></div>
        <div class="col-md-2"><input class="form-control" type="number" min="1" x-model.number="line.qty_requested" placeholder="Qtd"></div>
        <div class="col-md-3"><input class="form-control" x-model="line.unit_value" placeholder="Valor unit. R$"></div>
        <div class="col-md-1 small text-muted" x-text="Api.fmt.money((Number(line.qty_requested)||0) * (Number(String(line.unit_value).replace(',','.'))||0))"></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" @click="items.splice(i,1)">×</button></div>
      </div>
    </template>
    <button class="btn btn-primary mt-3" :disabled="saving">Enviar solicitação de compra</button>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function tradeForm() {
  return {
    f: { industry_id: '', purpose: '', recipient: '', action_type: 'campanha', delivery_place: 'CD Belford Roxo', notes: '' },
    inds: [], q: '', opts: [], items: [], saving: false, t: null,
    async init() { this.inds = (await Api.get('/api/industries', { all: 1 })).data; },
    search() {
      clearTimeout(this.t);
      this.t = setTimeout(async () => {
        this.opts = this.q ? (await Api.get('/api/items/options', { q: this.q })).data : [];
      }, 250);
    },
    add(o) {
      if (this.items.find(i => i.item_id === o.id)) return;
      this.items.push({ item_id: o.id, name: o.code + ' — ' + o.name, qty_requested: 1, unit_value: o.unit_value ?? '' });
      this.opts = []; this.q = '';
    },
    async save() {
      this.saving = true;
      try {
        const { data } = await Api.post('/api/trade/requests', {
          ...this.f,
          industry_id: Number(this.f.industry_id),
          items: this.items.map(i => ({
            item_id: i.item_id,
            qty_requested: Number(i.qty_requested),
            unit_value: i.unit_value === '' ? null : Number(String(i.unit_value).replace(',', '.'))
          }))
        });
        location.href = Api.url('/solicitacoes-trade/' + data.id);
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
