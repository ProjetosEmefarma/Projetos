<?php
$q = $app['page']['query'] ?? [];
ob_start(); ?>
<div x-data="itemsIndex()" x-init="init()">
  <!-- Toolbar -->
  <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2 flex-grow-1">
      <input class="form-control" style="max-width:300px" placeholder="Buscar código ou nome…" x-model="q" @input="debounced()">
      <select class="form-select" style="width:auto" x-model="status" @change="page=1;load()">
        <option value="">Todos os status</option>
        <option value="ativo">Ativo</option>
        <option value="inativo">Inativo</option>
      </select>
      <select class="form-select" style="width:auto" x-model="stock_level" @change="page=1;load()">
        <option value="">Todos os estoques</option>
        <option value="ok">Disponível</option>
        <option value="low">Estoque baixo</option>
        <option value="zero">Zerado</option>
      </select>
      <?php if (can('items.delete')): ?>
      <label class="form-check d-flex align-items-center gap-2 mt-1 mb-0">
        <input class="form-check-input" type="checkbox" x-model="trashed" @change="page=1;load()">
        <span class="small">Lixeira</span>
      </label>
      <?php endif; ?>
    </div>
    <?php if (can('items.manage')): ?>
    <a class="btn btn-primary ms-auto" href="<?= e(url('/brindes/novo')) ?>">
      <i class="bi bi-plus-lg me-1"></i>Novo brinde
    </a>
    <?php endif; ?>
  </div>

  <!-- Loading -->
  <template x-if="loading">
    <div class="empty"><i class="bi bi-hourglass-split spin"></i>Carregando…</div>
  </template>

  <!-- Empty State -->
  <template x-if="!loading && !rows.length">
    <div class="empty">
      <i class="bi bi-box-seam"></i>
      <div class="fw-semibold mb-1">Nenhum brinde encontrado</div>
      <div class="small">Tente ajustar os filtros ou cadastre um novo brinde.</div>
    </div>
  </template>

  <!-- Table -->
  <div class="table-responsive bg-white rounded border shadow-sm mb-3" x-show="rows.length">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:52px"></th>
          <th>Código</th>
          <th>Brinde</th>
          <th>Categoria</th>
          <th class="text-center">Em Estoque</th>
          <th class="text-center">Disponível</th>
          <th class="text-center">Nível</th>
          <th class="text-end">Valor Unit.</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <template x-for="it in rows" :key="it.id">
          <tr>
            <td>
              <img class="thumb" :src="it.thumb_url || <?= json_script(asset('img/placeholder.svg')) ?>" alt="">
            </td>
            <td>
              <code class="text-muted" x-text="it.code"></code>
            </td>
            <td>
              <a class="fw-semibold text-dark" :href="'<?= e(url('/brindes')) ?>/' + it.id" x-text="it.name"></a>
              <div class="small text-muted" x-show="it.supplier" x-text="it.supplier ? it.supplier.name : ''"></div>
            </td>
            <td x-text="it.category ? it.category.name : '—'" class="small text-muted"></td>
            <td class="text-center fw-semibold" x-text="it.stock.on_hand"></td>
            <td class="text-center fw-bold" :class="it.stock.available <= 0 ? 'text-danger' : 'text-success'" x-text="it.stock.available"></td>
            <td class="text-center">
              <span class="badge" :class="'badge-stock-' + it.stock.level" x-text="it.stock.level_label"></span>
            </td>
            <td class="text-end small" x-text="Api.fmt.money(it.unit_value)"></td>
            <td class="text-end text-nowrap">
              <?php if (can('stock.entry')): ?>
              <a class="btn btn-sm btn-outline-secondary me-1" :href="'<?= e(url('/estoque/entrada')) ?>?item_id=' + it.id">
                <i class="bi bi-box-arrow-in-down"></i>
              </a>
              <?php endif; ?>
              <?php if (can('items.manage')): ?>
              <a class="btn btn-sm btn-outline-primary me-1" :href="'<?= e(url('/brindes')) ?>/' + it.id + '/editar'">
                <i class="bi bi-pencil"></i>
              </a>
              <?php endif; ?>
              <?php if (can('items.delete')): ?>
              <button class="btn btn-sm btn-outline-danger" type="button" x-show="!trashed" @click="remove(it)">
                <i class="bi bi-trash"></i>
              </button>
              <button class="btn btn-sm btn-outline-success" type="button" x-show="trashed" @click="restore(it)">
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="d-flex align-items-center justify-content-between" x-show="meta">
    <button class="btn btn-sm btn-outline-secondary" :disabled="!meta || meta.page<=1" @click="page--; load()">Anterior</button>
    <span class="small text-muted" x-text="meta ? ('Página ' + meta.page + ' de ' + meta.last_page + ' · ' + meta.total + ' brindes') : ''"></span>
    <button class="btn btn-sm btn-outline-secondary" :disabled="!meta || meta.page>=meta.last_page" @click="page++; load()">Próxima</button>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function itemsIndex() {
  const params = new URLSearchParams(location.search);
  return {
    q: params.get('q') || '', status: params.get('status') || '', stock_level: params.get('stock_level') || '',
    trashed: params.get('trashed') === '1', page: 1, rows: [], meta: null, loading: true,
    debounce: null,
    init() { this.load(); },
    debounced() { clearTimeout(this.debounce); this.debounce = setTimeout(() => { this.page = 1; this.load(); }, 300); },
    async load() {
      this.loading = true;
      try {
        const { data, meta } = await Api.get('/api/items', {
          q: this.q, status: this.status, stock_level: this.stock_level, trashed: this.trashed ? 1 : '', page: this.page, per_page: 25
        });
        this.rows = data; this.meta = meta;
      } catch (e) { UI.toast(e.message, 'err'); }
      this.loading = false;
    },
    async remove(it) {
      if (!await UI.confirm('Enviar ' + it.name + ' para a lixeira?')) return;
      try { await Api.del('/api/items/' + it.id); UI.toast('Enviado para a lixeira.', 'ok'); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    },
    async restore(it) {
      try { await Api.post('/api/items/' + it.id + '/restore'); UI.toast('Restaurado com sucesso.', 'ok'); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';

