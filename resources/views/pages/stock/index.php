<?php ob_start(); ?>
<div x-data="stockIndex()" x-init="init()">
  <!-- Header & Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card card-body bg-light border-0 shadow-sm">
        <div class="text-muted small">Itens Ativos</div>
        <div class="fs-4 fw-bold text-dark" x-text="summary ? summary.items_active : '—'"></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card card-body bg-light border-0 shadow-sm">
        <div class="text-muted small">Unidades em Estoque</div>
        <div class="fs-4 fw-bold text-primary" x-text="summary ? summary.units_on_hand : '—'"></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card card-body bg-light border-0 shadow-sm">
        <div class="text-muted small">Disponível para Solicitação</div>
        <div class="fs-4 fw-bold text-success" x-text="summary ? summary.units_available : '—'"></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card card-body bg-light border-0 shadow-sm">
        <div class="text-muted small">Estoque Zerado / Baixo</div>
        <div class="fs-4 fw-bold text-danger">
          <span x-text="summary ? summary.zero_stock : '—'"></span> / <span x-text="summary ? summary.low_stock : '—'"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters & Actions -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <select class="form-select" style="width:auto" x-model="type" @change="page=1;load()">
        <option value="">Todos os tipos</option>
        <option value="entrada">Entrada</option>
        <option value="saida">Saída</option>
        <option value="ajuste">Ajuste</option>
        <option value="transferencia">Transferência</option>
        <option value="estorno">Estorno</option>
      </select>
      <input class="form-control" style="max-width:220px" placeholder="Buscar por código ou nome" x-model="q" @input="debounced()">
      <input type="date" class="form-control" style="width:auto" title="De" x-model="from" @change="load()">
      <input type="date" class="form-control" style="width:auto" title="Até" x-model="to" @change="load()">
    </div>
    <div>
      <button class="btn btn-outline-secondary btn-sm" type="button" @click="reconcile()" :disabled="reconciling">
        <i class="bi bi-arrow-repeat"></i> Conciliar Estoque
      </button>
    </div>
  </div>

  <!-- Movements Table -->
  <div class="table-responsive bg-white rounded border shadow-sm mb-3">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Data / Hora</th>
          <th>Tipo</th>
          <th>Brinde</th>
          <th class="text-end">Qtd</th>
          <th class="text-end">Saldo Pós</th>
          <th>Responsável</th>
          <th>Detalhes</th>
          <th class="text-center">Nota</th>
        </tr>
      </thead>
      <tbody>
        <template x-for="m in rows" :key="m.id">
          <tr>
            <td x-text="Api.fmt.datetime(m.created_at)" class="text-nowrap small text-muted"></td>
            <td>
              <span class="badge" :class="badgeClass(m.type)" x-text="m.type_label"></span>
            </td>
            <td>
              <div class="fw-semibold text-dark" x-text="m.item.name"></div>
              <div class="small text-muted" x-text="m.item.code"></div>
            </td>
            <td class="text-end fw-bold" :class="m.qty > 0 ? 'text-success' : 'text-danger'" x-text="(m.qty > 0 ? '+' : '') + Api.fmt.qty(m.qty)"></td>
            <td class="text-end fw-semibold" x-text="m.balance_after"></td>
            <td x-text="m.user.name" class="small"></td>
            <td class="small text-muted" x-text="m.purpose || m.reason || m.recipient || m.document_ref || '—'"></td>
            <td class="text-center">
              <a class="btn btn-sm btn-outline-primary py-0 px-2" x-show="m.attachment_url" :href="m.attachment_url" target="_blank">Ver NF</a>
              <span class="text-muted" x-show="!m.attachment_url">—</span>
            </td>
          </tr>
        </template>
        <tr x-show="!loading && rows.length === 0">
          <td colspan="8" class="text-center py-4 text-muted">
            <i class="bi bi-inbox fs-3 d-block mb-1"></i>
            Nenhuma movimentação de estoque encontrada para os filtros selecionados.
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="d-flex align-items-center justify-content-between">
    <button class="btn btn-sm btn-outline-secondary" :disabled="!meta || meta.page<=1" @click="page--;load()">Anterior</button>
    <span class="small text-muted" x-text="meta ? ('Página ' + meta.page + ' de ' + meta.last_page + ' (' + meta.total + ' registros)') : ''"></span>
    <button class="btn btn-sm btn-outline-secondary" :disabled="!meta || meta.page>=meta.last_page" @click="page++;load()">Próxima</button>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function stockIndex() {
  return {
    type: '', q: '', from: '', to: '', page: 1, rows: [], meta: null, summary: null, t: null, loading: false, reconciling: false,
    async init() {
      await Promise.all([this.loadSummary(), this.load()]);
    },
    debounced() { clearTimeout(this.t); this.t = setTimeout(() => { this.page = 1; this.load(); }, 300); },
    async loadSummary() {
      try {
        const { data } = await Api.get('/api/stock/summary');
        this.summary = data;
      } catch (e) {}
    },
    async load() {
      this.loading = true;
      try {
        const { data, meta } = await Api.get('/api/stock/movements', { type: this.type, q: this.q, from: this.from, to: this.to, page: this.page });
        this.rows = data; this.meta = meta;
      } catch (e) {
        UI.toast(e.message || 'Erro ao carregar movimentações.', 'err');
      }
      this.loading = false;
    },
    async reconcile() {
      this.reconciling = true;
      try {
        const { data } = await Api.post('/api/stock/reconcile');
        UI.toast(`Conciliação concluída. Movimentos ajustados: ${data.fixed_movements}, Posições: ${data.fixed_positions}`, 'ok');
        await Promise.all([this.loadSummary(), this.load()]);
      } catch (e) {
        UI.toast(e.message || 'Erro na conciliação.', 'err');
      }
      this.reconciling = false;
    },
    badgeClass(t) {
      switch (t) {
        case 'entrada': return 'bg-success';
        case 'saida': return 'bg-danger';
        case 'ajuste': return 'bg-warning text-dark';
        case 'transferencia': return 'bg-info text-dark';
        case 'estorno': return 'bg-secondary';
        default: return 'bg-dark';
      }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';

