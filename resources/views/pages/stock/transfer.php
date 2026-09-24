<?php ob_start(); ?>
<div class="card card-body" x-data="transferPage()" x-init="init()">
  <p class="text-muted">A transferência <b>não é retirada</b>. O saldo total permanece; só muda o local (CD → evento, escritório ou outro depósito).</p>
  <form @submit.prevent="save">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Indústria *</label>
        <select class="form-select" x-model="f.industry_id" required>
          <option value="">Selecione</option>
          <template x-for="d in inds" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
        </select></div>
      <div class="col-md-6"><label class="form-label">Brinde *</label>
        <select class="form-select" x-model="f.item_id" required>
          <option value="">Selecione</option>
          <template x-for="d in items" :key="d.id"><option :value="d.id" x-text="d.code + ' — ' + d.name + ' (disp. ' + d.available + ')'"></option></template>
        </select></div>
      <div class="col-md-4"><label class="form-label">Quantidade *</label>
        <input class="form-control" type="number" min="1" x-model.number="f.quantity" required></div>
      <div class="col-md-4"><label class="form-label">Origem</label>
        <select class="form-select" x-model="f.from_location_id">
          <option value="">CD (padrão)</option>
          <template x-for="d in locs" :key="'from-'+d.id"><option :value="d.id" x-text="d.name"></option></template>
        </select></div>
      <div class="col-md-4"><label class="form-label">Tipo de destino *</label>
        <select class="form-select" x-model="destType">
          <option value="event">Evento / feirão</option>
          <option value="location">Local (escritório, outro CD…)</option>
        </select></div>
      <div class="col-md-8" x-show="destType==='event'"><label class="form-label">Evento de destino *</label>
        <select class="form-select" x-model="f.event_id">
          <option value="">Selecione</option>
          <template x-for="d in evs" :key="d.id"><option :value="d.id" x-text="d.name + ' (' + d.status_label + ')'"></option></template>
        </select></div>
      <div class="col-md-8" x-show="destType==='location'">
        <label class="form-label">Local de destino *</label>
        <div class="input-group">
          <select class="form-select" x-model="f.to_location_id">
            <option value="">Selecione</option>
            <template x-for="d in locs" :key="'to-'+d.id"><option :value="d.id" x-text="d.name"></option></template>
          </select>
        </div>
        <?php if (can('lookups.manage')): ?>
        <div class="input-group mt-2">
          <input class="form-control" placeholder="Novo local, ex.: Escritório São Paulo" x-model="newLoc">
          <button class="btn btn-outline-primary" type="button" @click="addLocation">Cadastrar local</button>
        </div>
        <div class="form-text">Exemplo: transferir do CD para o escritório. O local fica salvo em Cadastros → Locais.</div>
        <?php else: ?>
        <div class="form-text">Para incluir um local novo, peça ao gestor em Cadastros → Locais.</div>
        <?php endif; ?>
      </div>
      <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" x-model="f.notes"></textarea></div>
    </div>
    <button class="btn btn-primary mt-3" :disabled="saving">Confirmar transferência</button>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function transferPage() {
  return {
    f: { industry_id: '', item_id: '', quantity: 1, from_location_id: '', event_id: '', to_location_id: '', notes: '' },
    destType: 'location', newLoc: '',
    inds: [], items: [], locs: [], evs: [], saving: false,
    async init() {
      this.inds = (await Api.get('/api/industries', { all: 1 })).data;
      this.items = (await Api.get('/api/items/options')).data;
      await this.loadLocs();
      this.evs = (await Api.get('/api/events', { per_page: 50 })).data;
    },
    async loadLocs() {
      this.locs = (await Api.get('/api/locations', { all: 1 })).data;
    },
    async addLocation() {
      const name = (this.newLoc || '').trim();
      if (!name) { UI.toast('Informe o nome do local.', 'err'); return; }
      try {
        const { data } = await Api.post('/api/locations', { name, kind: 'outro', description: 'Local de destino da transferência' });
        this.newLoc = '';
        await this.loadLocs();
        this.f.to_location_id = String(data.id);
        UI.toast('Local cadastrado.', 'ok');
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async save() {
      if (this.destType === 'event' && !this.f.event_id) { UI.toast('Selecione o evento de destino.', 'err'); return; }
      if (this.destType === 'location' && !this.f.to_location_id) { UI.toast('Selecione o local de destino.', 'err'); return; }
      this.saving = true;
      try {
        const body = {
          industry_id: Number(this.f.industry_id), item_id: Number(this.f.item_id),
          quantity: Number(this.f.quantity),
          from_location_id: this.f.from_location_id ? Number(this.f.from_location_id) : null,
          notes: this.f.notes
        };
        if (this.destType === 'event') body.event_id = Number(this.f.event_id);
        else body.to_location_id = Number(this.f.to_location_id);
        await Api.postIdem('/api/stock/transfers', body);
        UI.toast('Transferência registrada. O saldo mudou de local, não foi uma retirada.', 'ok');
        location.href = this.destType === 'event' ? Api.url('/eventos/' + this.f.event_id) : Api.url('/estoque/por-industria');
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
