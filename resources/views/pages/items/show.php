<?php
$id = (int) ($app['page']['params']['id'] ?? 0);
ob_start(); ?>
<div x-data="itemShow(<?= $id ?>)" x-init="load()">
  <template x-if="it">
    <div>
      <div class="d-flex flex-wrap gap-3 mb-3">
        <img class="thumb" style="width:96px;height:96px" :src="it.thumb_url || <?= json_script(asset('img/placeholder.svg')) ?>" alt="">
        <div>
          <div class="text-muted" x-text="it.code"></div>
          <h2 class="h4 mb-1" x-text="it.name"></h2>
          <span class="badge" :class="'badge-stock-' + it.stock.level" x-text="it.stock.level_label"></span>
          <span class="badge text-bg-secondary" x-text="it.status"></span>
        </div>
        <div class="ms-auto d-flex flex-wrap gap-2">
          <?php if (can('stock.entry')): ?><a class="btn btn-outline-primary" :href="'<?= e(url('/estoque/entrada')) ?>?item_id=' + it.id">Entrada</a><?php endif; ?>
          <?php if (can('stock.exit') && !is_cd_operations()): ?><a class="btn btn-outline-primary" :href="'<?= e(url('/estoque/saida')) ?>?item_id=' + it.id">Registrar saída</a><?php endif; ?>
          <?php if (can('stock.adjust')): ?><a class="btn btn-outline-secondary" :href="'<?= e(url('/estoque/ajuste')) ?>?item_id=' + it.id">Ajuste</a><?php endif; ?>
          <?php if (can('items.manage')): ?><a class="btn btn-primary" :href="'<?= e(url('/brindes')) ?>/' + it.id + '/editar'">Editar</a><?php endif; ?>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card kpi"><div class="label">Disponível</div><div class="value" x-text="it.stock.available"></div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi"><div class="label">Em mãos</div><div class="value" x-text="it.stock.on_hand"></div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi"><div class="label">Reservado</div><div class="value" x-text="it.stock.reserved"></div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi"><div class="label">Valor</div><div class="value" x-text="Api.fmt.money(it.stock_value)"></div></div></div>
      </div>
      <div class="card card-body mb-3">
        <div><b>Categoria:</b> <span x-text="it.category?.name || '—'"></span></div>
        <div><b>Local:</b> <span x-text="it.location?.name || '—'"></span></div>
        <div><b>Fornecedor:</b> <span x-text="it.supplier?.name || '—'"></span></div>
        <div><b>Mínimo:</b> <span x-text="it.min_stock"></span></div>
        <p class="mt-2 mb-0" x-text="it.description"></p>
      </div>
      <div class="card card-body">
        <h3 class="h6">Histórico completo</h3>
        <div class="timeline">
          <template x-for="h in history" :key="h.kind + h.id">
            <div class="timeline-item">
              <div class="small text-muted" x-text="Api.fmt.datetime(h.at) + ' · ' + (h.user?.name || '')"></div>
              <div><span x-text="h.action_label"></span>
                <span x-show="h.quantity !== null" x-text="' ' + Api.fmt.qty(h.quantity) + ' (saldo ' + h.balance_after + ')'"></span>
              </div>
              <div class="small" x-show="h.reason" x-text="h.reason"></div>
              <div class="small" x-show="h.before">alterado</div>
            </div>
          </template>
        </div>
        <button class="btn btn-sm btn-outline-secondary mt-2" x-show="meta && meta.page < meta.last_page" @click="more()">Carregar mais</button>
      </div>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function itemShow(id) {
  return {
    it: null, history: [], meta: null, page: 1,
    async load() {
      const { data } = await Api.get('/api/items/' + id);
      this.it = data;
      await this.more(true);
    },
    async more(reset) {
      if (reset) { this.page = 1; this.history = []; }
      const { data, meta } = await Api.get('/api/items/' + id + '/history', { page: this.page, per_page: 20 });
      this.history = this.history.concat(data);
      this.meta = meta;
      this.page++;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
