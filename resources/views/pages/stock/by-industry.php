<?php ob_start(); ?>
<div x-data="byInd()" x-init="load()">
  <p class="text-muted">Saldo = entradas − saídas. O valor não é editável — só muda com movimentação.</p>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Indústria</th><th>Brinde</th><th>Recebido</th><th>Retirado</th><th>Saldo</th></tr></thead>
      <tbody>
        <template x-for="(row, i) in rows" :key="i">
          <tr>
            <td x-text="row.industry.name"></td>
            <td x-text="row.item.code + ' — ' + row.item.name"></td>
            <td x-text="row.received"></td>
            <td x-text="row.withdrawn"></td>
            <td class="fw-semibold" x-text="row.saldo"></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <h3 class="h6 mt-4">Onde está cada unidade (CD × evento)</h3>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Local</th><th>Brinde</th><th>Qtd</th></tr></thead>
      <tbody>
        <template x-for="(p, i) in pos" :key="i">
          <tr>
            <td><span class="badge text-bg-light" x-text="p.location.kind"></span> <span x-text="p.location.name"></span></td>
            <td x-text="p.item.name"></td>
            <td x-text="p.qty"></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function byInd() {
  return {
    rows: [], pos: [],
    async load() {
      this.rows = (await Api.get('/api/stock/by-industry')).data;
      this.pos = (await Api.get('/api/stock/positions')).data;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
