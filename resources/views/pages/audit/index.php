<?php ob_start(); ?>
<div x-data="auditPage()" x-init="load()">
  <div class="d-flex flex-wrap gap-2 mb-3">
    <input class="form-control" style="max-width:220px" placeholder="Buscar" x-model="q" @change="load()">
    <input type="date" class="form-control" style="width:auto" x-model="from" @change="load()">
    <input type="date" class="form-control" style="width:auto" x-model="to" @change="load()">
  </div>
  <template x-for="a in rows" :key="a.id">
    <div class="card card-body mb-2">
      <div class="d-flex justify-content-between">
        <div><b x-text="a.action_label"></b> · <span x-text="a.entity_label"></span> <span x-text="a.entity_name"></span></div>
        <div class="small text-muted" x-text="Api.fmt.datetime(a.created_at)"></div>
      </div>
      <div class="small" x-text="a.user.name"></div>
      <div class="small" x-show="a.before || a.after">
        <span x-text="JSON.stringify(a.before || {})"></span> → <span x-text="JSON.stringify(a.after || {})"></span>
      </div>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function auditPage() {
  return {
    q: '', from: '', to: '', rows: [],
    async load() {
      this.rows = (await Api.get('/api/audit', { q: this.q, from: this.from, to: this.to })).data;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
