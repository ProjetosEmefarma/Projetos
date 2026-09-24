<?php
$canItems = can('items.manage');
ob_start(); ?>
<div x-data="dashboardPage()" x-init="load()">
  <div class="d-flex flex-wrap gap-2 mb-3">
    <select class="form-select form-select-sm" style="width:auto" x-model="preset" @change="applyPreset()">
      <option value="month">Este mês</option>
      <option value="7">Últimos 7 dias</option>
      <option value="30">Últimos 30 dias</option>
    </select>
    <input type="date" class="form-control form-control-sm" style="width:auto" x-model="from" @change="load()">
    <input type="date" class="form-control form-control-sm" style="width:auto" x-model="to" @change="load()">
    <?php if (can('stock.exit') && !is_cd_operations()): ?>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/estoque/saida')) ?>">Registrar saída de brindes</a>
    <?php endif; ?>
    <?php if (can('requests.create') && !is_cd_operations()): ?>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/solicitacoes-trade/nova')) ?>">Nova solicitação TRADE</a>
    <?php endif; ?>
  </div>
  <template x-if="loading"><div class="empty">Carregando…</div></template>
  <template x-if="d">
    <div>
      <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/brindes')) ?>">
          <div class="label">Itens ativos</div><div class="value" x-text="d.stock.items_active"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/brindes')) ?>">
          <div class="label">Disponíveis</div><div class="value" x-text="Api.fmt.number(d.stock.units_available)"></div></a></div>
        <div class="col-6 col-lg-3" x-show="d.stock.stock_value !== null"><div class="card kpi">
          <div class="label">Valor em estoque</div><div class="value" x-text="Api.fmt.money(d.stock.stock_value)"></div></div></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/brindes?stock_level=low')) ?>">
          <div class="label">Abaixo do mínimo</div><div class="value text-warning" x-text="d.stock.low_stock"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/brindes?stock_level=zero')) ?>">
          <div class="label">Sem estoque</div><div class="value text-danger" x-text="d.stock.zero_stock"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/solicitacoes')) ?>">
          <div class="label">Solicitações pendentes</div><div class="value" x-text="d.requests.pending"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/aprovacoes')) ?>">
          <div class="label">Aguardando aprovação</div><div class="value text-warning" x-text="d.requests.awaiting_approval"></div></a></div>
        <div class="col-6 col-lg-3" x-show="d.period_totals"><div class="card kpi">
          <div class="label">Saídas no período</div>
          <div class="value" x-text="d.period_totals ? Api.fmt.number(d.period_totals.exits_units) : '—'"></div>
          <div class="hint" x-text="d.period_totals ? Api.fmt.money(d.period_totals.exits_value) : ''"></div>
        </div></div>
      </div>
      <div class="row g-3 mb-3" x-show="d.trade">
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/solicitacoes-trade')) ?>">
          <div class="label">Solicitações TRADE</div><div class="value" x-text="d.trade.total"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/recebimento')) ?>">
          <div class="label">Aguardando recebimento</div><div class="value text-warning" x-text="d.trade.awaiting_receipt"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/retirada')) ?>">
          <div class="label">Prontas para retirada</div><div class="value text-success" x-text="d.trade.ready"></div></a></div>
        <div class="col-6 col-lg-3"><a class="card kpi" href="<?= e(url('/solicitacoes-trade?status=solicitada')) ?>">
          <div class="label">Aguardando aprovação</div><div class="value text-danger" x-text="d.trade.awaiting_approval"></div></a></div>
        <div class="col-lg-6" x-show="d.trade.current_event">
          <a class="card kpi" :href="d.trade.current_event ? '<?= e(url('/eventos')) ?>/' + d.trade.current_event.id : '#'">
            <div class="label">Evento atual</div>
            <div class="value" x-text="d.trade.current_event ? d.trade.current_event.name : '—'"></div>
            <div class="hint" x-show="d.trade.current_event"
                 x-text="d.trade.current_event ? ('Disponíveis ' + d.trade.current_event.saldo + ' · retirados ' + d.trade.current_event.withdrawn) : ''"></div>
          </a>
        </div>
        <div class="col-lg-6" x-show="d.period_totals"><div class="card kpi">
          <div class="label">Movimentações do período</div>
          <div class="value" x-text="d.period_totals ? Api.fmt.number((d.period_totals.entries_units||0) - (d.period_totals.exits_units||0)) : '—'"></div>
          <div class="hint" x-text="d.period_totals ? ('Entradas ' + Api.fmt.number(d.period_totals.entries_units) + ' · saídas ' + Api.fmt.number(d.period_totals.exits_units)) : ''"></div>
        </div></div>
      </div>
      <div class="row g-3 mb-3" x-show="d.series">
        <div class="col-lg-8"><div class="card card-body"><canvas id="chartSeries" height="120"></canvas></div></div>
        <div class="col-lg-4"><div class="card card-body"><canvas id="chartTop" height="120"></canvas></div></div>
      </div>
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card card-body">
            <h2 class="h6">Alertas de estoque</h2>
            <template x-if="!d.alerts.length"><p class="text-muted mb-0">Nenhum alerta.</p></template>
            <template x-for="a in d.alerts" :key="a.item.id">
              <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <div>
                  <div x-text="a.item.code + ' — ' + a.item.name"></div>
                  <span class="badge" :class="'badge-stock-' + a.level" x-text="a.level_label + ' · ' + a.available"></span>
                </div>
                <?php if (can('stock.entry')): ?>
                <a class="btn btn-sm btn-outline-primary" :href="'<?= e(url('/estoque/entrada')) ?>?item_id=' + a.item.id">Entrada</a>
                <?php endif; ?>
              </div>
            </template>
          </div>
        </div>
        <div class="col-lg-7" x-show="d.recent_movements">
          <div class="card card-body">
            <h2 class="h6">Movimentações recentes</h2>
            <template x-for="m in (d.recent_movements || [])" :key="m.id">
              <div class="d-flex justify-content-between py-2 border-bottom small">
                <div>
                  <span class="badge text-bg-secondary" x-text="m.type_label"></span>
                  <span x-text="m.item.name"></span>
                  <span class="fw-semibold" x-text="Api.fmt.qty(m.quantity)"></span>
                </div>
                <div class="text-muted" x-text="Api.fmt.datetime(m.created_at)"></div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script>
function dashboardPage() {
  const iso = (d) => d.toISOString().slice(0, 10);
  const today = new Date();
  return {
    loading: true, d: null, preset: 'month',
    from: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
    to: iso(today),
    charts: {},
    applyPreset() {
      const t = new Date();
      if (this.preset === 'month') this.from = iso(new Date(t.getFullYear(), t.getMonth(), 1));
      else {
        const n = this.preset === '7' ? 6 : 29;
        const f = new Date(); f.setDate(f.getDate() - n); this.from = iso(f);
      }
      this.to = iso(t);
      this.load();
    },
    async load() {
      this.loading = true;
      try {
        const { data } = await Api.get('/api/dashboard', { from: this.from, to: this.to });
        this.d = data;
        this.$nextTick(() => this.draw());
      } catch (e) { UI.toast(e.message, 'err'); }
      this.loading = false;
    },
    draw() {
      if (!this.d || !this.d.series || !window.Chart) return;
      const color = getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim();
      const destroy = (id) => { if (this.charts[id]) { this.charts[id].destroy(); } };
      const el = document.getElementById('chartSeries');
      if (el) {
        destroy('s');
        this.charts.s = new Chart(el, {
          type: 'line',
          data: {
            labels: this.d.series.points.map(p => p.date),
            datasets: [
              { label: 'Entradas', data: this.d.series.points.map(p => p.entries), borderColor: '#10b981', tension: .3 },
              { label: 'Saídas', data: this.d.series.points.map(p => p.exits), borderColor: color, tension: .3 }
            ]
          },
          options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
      }
      const top = document.getElementById('chartTop');
      if (top && this.d.top_items) {
        destroy('t');
        this.charts.t = new Chart(top, {
          type: 'bar',
          data: {
            labels: this.d.top_items.map(x => x.item.name),
            datasets: [{ label: 'Unidades', data: this.d.top_items.map(x => x.units), backgroundColor: color }]
          },
          options: { indexAxis: 'y', plugins: { legend: { display: false } } }
        });
      }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
