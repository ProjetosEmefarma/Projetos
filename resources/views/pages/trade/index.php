<?php ob_start(); ?>
<div x-data="tradeIndex()" x-init="load()">
  <div class="d-flex flex-wrap gap-2 mb-3">
    <input class="form-control" style="max-width:260px" placeholder="Buscar código, indústria…" x-model="q" @input="load()">
    <select class="form-select" style="max-width:220px" x-model="status" @change="load()">
      <option value="">Todos os status</option>
      <option value="solicitada">Aguardando aprovação</option>
      <option value="compra_realizada">Compra realizada</option>
      <option value="aguardando_recebimento">Aguardando recebimento</option>
      <option value="pronta">Pronto para retirada</option>
      <option value="retirado">Retirado</option>
      <option value="entregue">Entregue</option>
      <option value="reprovada">Reprovada</option>
    </select>
    <?php if (can('requests.create') && !is_cd_operations()): ?>
    <a class="btn btn-primary ms-auto" href="<?= e(url('/solicitacoes-trade/nova')) ?>">Nova solicitação</a>
    <?php endif; ?>
    <?php if (can('stock.exit') && !is_cd_operations()): ?>
    <a class="btn btn-outline-primary" href="<?= e(url('/estoque/saida')) ?>">Registrar saída de brindes</a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Nº</th><th>Indústria</th><th>Ação</th><th>Status</th><th>Valor</th><th>Data</th></tr></thead>
      <tbody>
        <template x-for="r in rows" :key="r.id">
          <tr role="button" @click="location.href = Api.url('/solicitacoes-trade/' + r.id)">
            <td class="fw-semibold" x-text="r.public_code || r.code"></td>
            <td x-text="r.industry?.name || '—'"></td>
            <td x-text="r.action_type_label || r.purpose"></td>
            <td><span class="badge text-bg-secondary" x-text="r.status_label"></span></td>
            <td x-text="Api.fmt.money(r.total_value)"></td>
            <td class="text-muted small" x-text="Api.fmt.datetime(r.created_at)"></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <p class="text-muted" x-show="!rows.length">Nenhuma solicitação TRADE.</p>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function tradeIndex() {
  const qs = new URLSearchParams(location.search);
  return {
    rows: [], q: qs.get('q') || '', status: qs.get('status') || '', t: null,
    async load() {
      clearTimeout(this.t);
      this.t = setTimeout(async () => {
        const { data } = await Api.get('/api/requests', { flow: 'trade', q: this.q, status: this.status, per_page: 50 });
        this.rows = data;
      }, 200);
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
