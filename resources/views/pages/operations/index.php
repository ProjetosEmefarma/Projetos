<?php ob_start(); ?>
<div x-data="opsPage()" x-init="load()">
  <div class="btn-group mb-3">
    <button class="btn btn-outline-primary" :class="status==='aprovada' && 'active'" @click="status='aprovada';load()">Aprovadas</button>
    <button class="btn btn-outline-primary" :class="status==='em_separacao' && 'active'" @click="status='em_separacao';load()">Em separação</button>
    <button class="btn btn-outline-primary" :class="status==='pronta' && 'active'" @click="status='pronta';load()">Prontas</button>
  </div>
  <template x-for="r in rows" :key="r.id">
    <div class="card card-body mb-2 d-flex flex-row justify-content-between align-items-center">
      <div>
        <b x-text="r.code"></b> · <span x-text="r.purpose"></span>
        <div class="small text-muted" x-text="r.status_label"></div>
      </div>
      <div>
        <button class="btn btn-sm btn-primary" x-show="status==='aprovada'" @click="act(r,'/start-picking')">Iniciar</button>
        <button class="btn btn-sm btn-primary" x-show="status==='em_separacao'" @click="act(r,'/ready')">Pronta</button>
        <a class="btn btn-sm btn-primary" x-show="status==='pronta'" :href="'<?= e(url('/operacao')) ?>/' + r.id + '/entrega'">Entregar</a>
      </div>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function opsPage() {
  return {
    status: 'aprovada', rows: [],
    async load() { this.rows = (await Api.get('/api/requests', { status: this.status })).data; },
    async act(r, path) {
      try { await Api.post('/api/requests/' + r.id + path); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
