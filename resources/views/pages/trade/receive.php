<?php ob_start(); ?>
<div class="card card-body" x-data="receivePage()" x-init="init()">
  <p class="text-muted">Receba somente pedidos já aprovados (com número do chamado). Anexe a NF quando o brinde chegar no CD.</p>
  <div class="row g-3 mb-3">
    <div class="col-md-8">
      <label class="form-label">Solicitação TRADE aguardando recebimento</label>
      <select class="form-select" x-model="id" @change="pick()">
        <option value="">Selecione</option>
        <template x-for="r in list" :key="r.id">
          <option :value="r.id" x-text="(r.public_code || r.code) + ' — ' + (r.industry?.name || '') + ' — ' + r.status_label"></option>
        </template>
      </select>
      <p class="small text-muted mt-2 mb-0" x-show="!list.length">Nenhuma solicitação aprovada aguardando chegada. O gestor precisa aprovar o pedido TRADE e informar o número do chamado antes de o CD confirmar o recebimento.</p>
    </div>
    <div class="col-md-4"><label class="form-label">Nº da NF (opcional)</label>
      <input class="form-control" x-model="invoice_no"></div>
  </div>
  <template x-if="r">
    <div>
      <p class="mb-1"><b>Chamado:</b> <span x-text="r.purchase_ticket_no || '—'"></span></p>
      <p class="text-muted" x-text="r.purpose"></p>
      <div class="mb-3">
        <label class="form-label">Anexar NF (PDF, JPG ou PNG) *</label>
        <input class="form-control" type="file" accept=".pdf,image/jpeg,image/png,application/pdf" @change="invoiceFile = $event.target.files[0]">
      </div>
      <template x-for="it in r.items" :key="it.id">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="flex-grow-1" x-text="it.item.name + ' (solicitado ' + it.qty_requested + ', já recebido ' + it.qty_received + ')'"></span>
          <input class="form-control" style="width:110px" type="number" min="0" x-model.number="it.qty_now" placeholder="Qtd">
        </div>
      </template>
      <div class="mb-3"><label class="form-label">Observações</label><textarea class="form-control" x-model="notes"></textarea></div>
      <button class="btn btn-primary" :disabled="saving" @click="save">Confirmar chegada no CD (anexa NF e alimenta o estoque)</button>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function receivePage() {
  const qs = new URLSearchParams(location.search);
  return {
    list: [], id: qs.get('id') || '', r: null, invoice_no: '', notes: '', invoiceFile: null, saving: false,
    async init() {
      const { data } = await Api.get('/api/requests', {
        flow: 'trade',
        status_in: 'compra_realizada,aguardando_recebimento,recebido_cd',
        per_page: 100
      });
      this.list = data;
      if (this.id) this.pick();
    },
    async pick() {
      if (!this.id) { this.r = null; return; }
      const { data } = await Api.get('/api/requests/' + this.id);
      data.items.forEach(it => { it.qty_now = Math.max(0, it.qty_requested - it.qty_received); });
      this.r = data;
      this.invoice_no = data.invoice_no || '';
    },
    async save() {
      if (!this.invoiceFile && !this.invoice_no) { UI.toast('Anexe a NF quando o brinde chegar no CD.', 'err'); return; }
      this.saving = true;
      try {
        const fd = new FormData();
        fd.append('invoice_no', this.invoice_no);
        fd.append('notes', this.notes);
        fd.append('items', JSON.stringify(this.r.items.filter(i => Number(i.qty_now) > 0).map(i => ({ item_id: i.item.id, qty: Number(i.qty_now) }))));
        if (this.invoiceFile) fd.append('invoice', this.invoiceFile);
        await Api.postIdem('/api/trade/requests/' + this.id + '/receive', fd);
        UI.toast('Recebimento registrado. NF anexada e estoque atualizado.', 'ok');
        location.href = Api.url('/solicitacoes-trade/' + this.id);
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
