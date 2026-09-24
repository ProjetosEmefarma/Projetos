<?php
$id = (int) ($app['page']['params']['id'] ?? 0);
ob_start(); ?>
<div x-data="eventShow(<?= $id ?>)" x-init="load()">
  <template x-if="e">
    <div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <div>
          <h2 class="h4 mb-1" x-text="e.name"></h2>
          <span class="badge text-bg-primary" x-text="e.status_label"></span>
        </div>
        <div class="ms-auto d-flex gap-2">
          <?php if (can(['stock.transfer', 'stock.exit'])): ?>
          <a class="btn btn-outline-primary" :href="'<?= e(url('/estoque/transferencia')) ?>'">Transferir do CD</a>
          <?php endif; ?>
          <?php if (can('events.withdraw')): ?>
          <a class="btn btn-primary" x-show="e.status==='aberto'" :href="'<?= e(url('/eventos')) ?>/' + e.id + '/modo-evento'">Modo Evento</a>
          <?php endif; ?>
          <?php if (can('events.manage')): ?>
          <button class="btn btn-outline-primary" x-show="e.status==='planejado'" @click="openEv">Abrir evento</button>
          <button class="btn btn-outline-danger" x-show="e.status==='aberto'" @click="closeEv">Encerrar</button>
          <?php endif; ?>
        </div>
      </div>
      <?php if (can('events.manage')): ?>
      <div class="card card-body mb-3" x-show="e.status==='planejado'">
        <h3 class="h6">Cotas (indústria × brinde)</h3>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <select class="form-select" x-model="row.industry_id">
              <option value="">Indústria</option>
              <template x-for="i in industries" :key="i.id"><option :value="i.id" x-text="i.name"></option></template>
            </select>
          </div>
          <div class="col-md-4">
            <select class="form-select" x-model="row.item_id">
              <option value="">Brinde</option>
              <template x-for="i in items" :key="i.id"><option :value="i.id" x-text="i.code + ' — ' + i.name"></option></template>
            </select>
          </div>
          <div class="col-md-2"><input class="form-control" type="number" min="1" x-model.number="row.qty_allocated" placeholder="Qtd"></div>
          <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="button" @click="addRow">Incluir</button></div>
        </div>
        <button class="btn btn-sm btn-primary" @click="saveAlloc">Salvar cotas</button>
      </div>
      <?php endif; ?>
      <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><button class="nav-link" :class="{ active: tab==='alloc' }" type="button" @click="tab='alloc'">Alocações</button></li>
        <?php if (can(['events.manage', 'stock.transfer'])): ?>
        <li class="nav-item" x-show="e.status==='aberto' || e.status==='encerrado'">
          <button class="nav-link" :class="{ active: tab==='return' }" type="button" @click="tab='return'">Devolução ao CD</button>
        </li>
        <?php endif; ?>
      </ul>
      <div class="card card-body" x-show="tab==='alloc'">
        <h3 class="h6">Alocações</h3>
        <template x-for="(a, i) in allocs" :key="i">
          <div class="d-flex justify-content-between py-1 border-bottom">
            <span x-text="a.industry.name + ' · ' + a.item.name"></span>
            <span x-text="a.qty_withdrawn + '/' + a.qty_allocated + ' (saldo ' + a.saldo + ')'"></span>
          </div>
        </template>
      </div>
      <?php if (can(['events.manage', 'stock.transfer'])): ?>
      <div class="card card-body" x-show="tab==='return'">
        <h3 class="h6">O que volta para o estoque do CD</h3>
        <p class="text-muted small">Informe as quantidades que não foram distribuídas e estão voltando para o CD. Isso libera a cota do evento e, se o brinde estava no local do evento, transfere de volta.</p>
        <template x-if="!returns.length"><p class="mb-0">Nada pendente de devolução neste evento.</p></template>
        <template x-for="(a, i) in returns" :key="a.industry.id + '-' + a.item.id">
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <span class="flex-grow-1" x-text="a.industry.name + ' · ' + a.item.name + ' (saldo ' + a.saldo + ')'"></span>
            <input class="form-control" style="width:110px" type="number" min="0" :max="a.saldo" x-model.number="a.qty_now">
          </div>
        </template>
        <button class="btn btn-primary mt-3" type="button" :disabled="savingReturn" @click="returnCd" x-show="returns.length">Confirmar devolução ao CD</button>
      </div>
      <?php endif; ?>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function eventShow(id) {
  return {
    e: null, allocs: [], returns: [], industries: [], items: [], tab: 'alloc', savingReturn: false,
    row: { industry_id: '', item_id: '', qty_allocated: 1 },
    async load() {
      const { data } = await Api.get('/api/events/' + id);
      this.e = data; this.allocs = data.allocations || [];
      this.returns = (data.returns || []).map(a => ({ ...a, qty_now: a.saldo }));
      this.industries = (await Api.get('/api/industries', { all: 1 })).data;
      this.items = (await Api.get('/api/items/options')).data;
    },
    addRow() {
      if (!this.row.industry_id || !this.row.item_id) return;
      const ind = this.industries.find(i => i.id == this.row.industry_id);
      const it = this.items.find(i => i.id == this.row.item_id);
      this.allocs.push({
        industry: { id: Number(this.row.industry_id), name: ind?.name },
        item: { id: Number(this.row.item_id), name: it?.name, code: it?.code },
        qty_allocated: Number(this.row.qty_allocated), qty_withdrawn: 0, saldo: Number(this.row.qty_allocated)
      });
    },
    async saveAlloc() {
      try {
        const body = this.allocs.map(a => ({ industry_id: a.industry.id, item_id: a.item.id, qty_allocated: a.qty_allocated }));
        const { data } = await Api.put('/api/events/' + id + '/allocations', { allocations: body });
        this.e = data; this.allocs = data.allocations; UI.toast('Cotas salvas.', 'ok');
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async openEv() { try { this.e = (await Api.post('/api/events/' + id + '/open')).data; UI.toast('Evento aberto.', 'ok'); } catch (e) { UI.toast(e.message, 'err'); } },
    async closeEv() { if (!await UI.confirm('Encerrar o evento?')) return; try { this.e = (await Api.post('/api/events/' + id + '/close')).data; } catch (e) { UI.toast(e.message, 'err'); } },
    async returnCd() {
      const items = this.returns.filter(a => Number(a.qty_now) > 0).map(a => ({
        industry_id: a.industry.id, item_id: a.item.id, qty: Number(a.qty_now)
      }));
      if (!items.length) { UI.toast('Informe as quantidades que voltam ao CD.', 'err'); return; }
      if (!await UI.confirm('Confirmar devolução ao estoque do CD?')) return;
      this.savingReturn = true;
      try {
        const { data } = await Api.postIdem('/api/events/' + id + '/returns', { items });
        this.e = data; this.allocs = data.allocations || [];
        this.returns = (data.returns || []).map(a => ({ ...a, qty_now: a.saldo }));
        UI.toast('Devolução registrada. Saldo voltou para o CD.', 'ok');
        this.tab = 'alloc';
      } catch (e) { UI.toast(e.message, 'err'); }
      this.savingReturn = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
