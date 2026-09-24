<?php ob_start(); ?>
<div x-data="eventsIndex()" x-init="load()">
  <?php if (can('events.manage')): ?>
  <div class="card card-body mb-3">
    <form class="row g-2" @submit.prevent="create">
      <div class="col-md-4"><input class="form-control" placeholder="Nome do evento" x-model="n.name" required></div>
      <div class="col-md-3"><input class="form-control" placeholder="Local" x-model="n.venue"></div>
      <div class="col-md-2"><input class="form-control" type="date" x-model="n.starts_on"></div>
      <div class="col-md-2"><input class="form-control" type="date" x-model="n.ends_on"></div>
      <div class="col-md-1"><button class="btn btn-primary w-100">Criar</button></div>
    </form>
  </div>
  <?php endif; ?>
  <template x-for="e in rows" :key="e.id">
    <a class="card card-body d-block mb-2 text-decoration-none" :href="'<?= e(url('/eventos')) ?>/' + e.id">
      <div class="d-flex justify-content-between">
        <b x-text="e.name"></b>
        <span class="badge text-bg-secondary" x-text="e.status_label"></span>
      </div>
      <div class="small text-muted" x-text="(e.venue||'') + ' · ' + (e.starts_on||'')"></div>
    </a>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function eventsIndex() {
  return {
    rows: [], n: { name: '', venue: '', starts_on: '', ends_on: '' },
    async load() { this.rows = (await Api.get('/api/events')).data; },
    async create() {
      try { const { data } = await Api.post('/api/events', this.n); location.href = Api.url('/eventos/' + data.id); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
