<?php ob_start(); ?>
<div x-data="protIndex()" x-init="load()">
  <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
    <div>
      <label class="form-label small mb-1">Buscar</label>
      <input class="form-control" style="max-width:220px" placeholder="Código, nome…" x-model="q" @input="debounced()">
    </div>
    <div>
      <label class="form-label small mb-1">De</label>
      <input type="date" class="form-control" x-model="from" @change="load()">
    </div>
    <div>
      <label class="form-label small mb-1">Até</label>
      <input type="date" class="form-control" x-model="to" @change="load()">
    </div>
  </div>
  <template x-for="d in rows" :key="d.id">
    <a class="card card-body d-block mb-2 text-decoration-none" :href="'<?= e(url('/protocolos')) ?>/' + d.id">
      <div class="d-flex justify-content-between">
        <b x-text="d.code"></b>
        <span class="badge text-bg-secondary" x-text="d.type_label"></span>
      </div>
      <div x-text="d.received_by_name"></div>
      <div class="small text-muted" x-text="Api.fmt.datetime(d.created_at)"></div>
    </a>
  </template>
  <p class="text-muted" x-show="!rows.length">Nenhum comprovante neste período.</p>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function protIndex() {
  return {
    q: '', from: '', to: '', rows: [], t: null,
    debounced() { clearTimeout(this.t); this.t = setTimeout(() => this.load(), 300); },
    async load() {
      this.rows = (await Api.get('/api/deliveries', { q: this.q, from: this.from, to: this.to })).data;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
