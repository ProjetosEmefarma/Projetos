<?php ob_start(); ?>
<div x-data="notifPage()" x-init="load()">
  <button class="btn btn-sm btn-outline-secondary mb-3" @click="readAll">Marcar todas como lidas</button>
  <template x-for="n in rows" :key="n.id">
    <a class="card card-body d-block mb-2 text-decoration-none" :href="n.link_url ? Api.url(n.link_url) : '#'" @click="read(n)">
      <div class="d-flex justify-content-between">
        <b x-text="n.subject"></b>
        <span class="badge text-bg-primary" x-show="!n.read_at">Nova</span>
      </div>
      <div class="small text-muted" x-text="Api.fmt.datetime(n.created_at)"></div>
    </a>
  </template>
  <template x-if="!rows.length"><div class="empty">Nenhuma notificação.</div></template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function notifPage() {
  return {
    rows: [],
    async load() { this.rows = (await Api.get('/api/notifications')).data; },
    async read(n) { if (!n.read_at) await Api.post('/api/notifications/' + n.id + '/read'); },
    async readAll() { await Api.post('/api/notifications/read-all'); this.load(); }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
