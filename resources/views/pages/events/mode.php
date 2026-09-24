<?php
$id = (int) ($app['page']['params']['id'] ?? 0);
ob_start(); ?>
<div class="p-3" style="max-width:560px;margin:0 auto" x-data="modoEvento(<?= $id ?>)" x-init="init()">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0" x-text="eventName"></h1>
    <a class="btn btn-sm btn-outline-light" href="<?= e(url('/eventos/' . $id)) ?>">Sair</a>
  </div>
  <template x-if="done">
    <div class="text-center py-4">
      <div class="display-6 mb-2">✓</div>
      <div>Protocolo <b x-text="done.code"></b></div>
      <p class="small" x-show="done.pdf_url"><a :href="done.pdf_url" target="_blank">Baixar comprovante PDF</a></p>
      <p class="small" x-text="mailHint(done)"></p>
      <button class="btn btn-primary big-btn w-100 mt-3" @click="next()">Próxima retirada</button>
    </div>
  </template>
  <div x-show="!done">
    <label class="form-label">Indústria</label>
    <input class="form-control form-control-lg mb-2" placeholder="Buscar indústria" x-model="q" @input="filterInd()">
    <div class="list-group mb-3">
      <template x-for="i in filtered" :key="i.id">
        <button type="button" class="list-group-item list-group-item-action" :class="industry && industry.id===i.id ? 'active' : ''" @click="pickInd(i)" x-text="i.name + ' (saldo ' + i.saldo + ')'"></button>
      </template>
    </div>
    <template x-if="industry">
      <div>
        <template x-for="line in lines" :key="line.item.id">
          <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:#1e293b">
            <div class="flex-grow-1">
              <div x-text="line.item.name"></div>
              <div class="small">saldo <span x-text="line.saldo"></span></div>
            </div>
            <button class="btn btn-light" @click="line.qty = Math.max(0, line.qty-1)">−</button>
            <span class="qty-lg" x-text="line.qty"></span>
            <button class="btn btn-light" @click="line.qty = Math.min(line.saldo, line.qty+1)">+</button>
          </div>
        </template>
        <div class="mb-2"><input class="form-control" placeholder="Nome de quem retira *" x-model="recv"></div>
        <div class="mb-2"><input class="form-control" type="email" placeholder="E-mail para o comprovante *" x-model="email"></div>
        <canvas id="sigEv" class="sig-pad mb-2"></canvas>
        <button type="button" class="btn btn-sm btn-outline-light mb-3" @click="clear()">Limpar assinatura</button>
        <button class="btn btn-primary big-btn w-100" :disabled="saving" @click="confirm()">Confirmar retirada</button>
      </div>
    </template>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script src="<?= e(asset('vendor/signature_pad/signature_pad.umd.min.js')) ?>"></script>
<script>
function modoEvento(id) {
  return {
    eventName: '', industries: [], filtered: [], q: '', industry: null, lines: [], recv: '', email: '',
    pad: null, saving: false, done: null, key: Api.newKey(),
    async init() {
      const { data } = await Api.get('/api/events/' + id);
      this.eventName = data.name;
      this.industries = data.industries || [];
      this.filtered = this.industries;
    },
    filterInd() {
      const q = this.q.toLowerCase();
      this.filtered = this.industries.filter(i => i.name.toLowerCase().includes(q));
    },
    async pickInd(i) {
      this.industry = i;
      this.lines = ((await Api.get('/api/events/' + id + '/balance', { industry_id: i.id })).data || []).map(l => ({ ...l, qty: 0 }));
      this.$nextTick(() => {
        const c = document.getElementById('sigEv');
        if (c) this.pad = UI.signaturePad(c);
      });
    },
    clear() { this.pad && this.pad.clear(); },
    mailHint(done) {
      if (!done) return '';
      const addrs = (done.mail && done.mail.addresses) || [];
      if (done.mail && done.mail.smtp && addrs.length) return 'E-mail enviado para ' + addrs.join(', ') + '.';
      if (addrs.length) return 'Comprovante registrado para ' + addrs.join(', ') + '. No servidor de produção, com SMTP, o e-mail sai na hora.';
      return 'Protocolo gerado. Informe um e-mail válido para receber o comprovante.';
    },
    next() { this.done = null; this.industry = null; this.lines = []; this.recv = ''; this.email = ''; this.key = Api.newKey(); this.q = ''; this.filtered = this.industries; },
    async confirm() {
      const items = this.lines.filter(l => l.qty > 0).map(l => ({ item_id: l.item.id, qty: l.qty }));
      if (!items.length) { UI.toast('Informe as quantidades.', 'err'); return; }
      if (!this.recv) { UI.toast('Informe o nome.', 'err'); return; }
      if (!this.email || !this.email.includes('@')) { UI.toast('Informe o e-mail para enviar o comprovante.', 'err'); return; }
      if (!this.pad || this.pad.isEmpty()) { UI.toast('Assine no campo.', 'err'); return; }
      this.saving = true;
      try {
        const { data } = await Api.post('/api/events/' + id + '/withdrawals', {
          industry_id: this.industry.id,
          received_by_name: this.recv,
          received_by_email: this.email,
          items,
          signature: this.pad.toDataURL('image/png')
        }, { idempotencyKey: this.key });
        this.done = data;
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/kiosk.php';
