<?php ob_start(); ?>
<div x-data="reportsPage()" x-init="load()">
  <div class="row g-2 mb-3">
    <div class="col-md-4">
      <select class="form-select" x-model="type" @change="run()">
        <option value="">Escolha o relatório</option>
        <template x-for="t in types" :key="t.id"><option :value="t.id" x-text="t.label"></option></template>
      </select>
    </div>
    <div class="col-md-3"><input type="date" class="form-control" x-model="from" @change="run()"></div>
    <div class="col-md-3"><input type="date" class="form-control" x-model="to" @change="run()"></div>
    <div class="col-md-2 d-flex gap-1">
      <button class="btn btn-outline-secondary" type="button" @click="exp('csv')">CSV</button>
      <button class="btn btn-outline-secondary" type="button" @click="exp('xlsx')">Excel</button>
    </div>
  </div>
  <div class="table-responsive" x-show="rows.length">
    <table class="table table-sm">
      <thead><tr><template x-for="c in cols" :key="c"><th x-text="c"></th></template></tr></thead>
      <tbody>
        <template x-for="(row, i) in rows" :key="i">
          <tr><template x-for="c in cols" :key="c"><td x-text="row[c]"></td></template></tr>
        </template>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function reportsPage() {
  const iso = (d) => d.toISOString().slice(0,10);
  const t = new Date();
  return {
    types: [], type: '', from: iso(new Date(t.getFullYear(), t.getMonth(), 1)), to: iso(t), rows: [], cols: [],
    async load() { this.types = (await Api.get('/api/reports')).data; },
    async run() {
      if (!this.type) return;
      const { data } = await Api.get('/api/reports/' + this.type, { from: this.from, to: this.to });
      this.cols = data.columns || [];
      this.rows = (data.rows || []).map(function (r) {
        const o = {};
        (data.columns || []).forEach(function (c, i) { o[c] = Array.isArray(r) ? r[i] : r[c]; });
        return o;
      });
    },
    exp(fmt) {
      if (!this.type) return;
      location.href = Api.url('/api/reports/' + this.type, { from: this.from, to: this.to, format: fmt });
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
