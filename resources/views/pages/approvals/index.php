<?php ob_start(); ?>
<div x-data="apprPage()" x-init="load()">
  <template x-if="!rows.length"><div class="empty">Nenhuma aprovação pendente.</div></template>
  <template x-for="r in rows" :key="r.id">
    <a class="card card-body d-block mb-2 text-decoration-none" :href="'<?= e(url('/solicitacoes')) ?>/' + r.id">
      <div class="d-flex justify-content-between">
        <b x-text="r.code"></b>
        <span class="badge text-bg-warning" x-text="r.status_label"></span>
      </div>
      <div x-text="r.purpose"></div>
      <div class="small text-muted" x-text="r.requester.name + ' · ' + Api.fmt.money(r.total_value)"></div>
    </a>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function apprPage() {
  return { rows: [], async load() { this.rows = (await Api.get('/api/requests', { status: 'aguardando_aprovacao' })).data; } };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
