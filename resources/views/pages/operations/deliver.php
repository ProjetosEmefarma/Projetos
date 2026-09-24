<?php
$id = (int) ($app['page']['params']['id'] ?? 0);
ob_start(); ?>
<div class="card card-body" style="max-width:640px" x-data="deliverPage(<?= $id ?>)" x-init="init()">
  <template x-if="done">
    <div>
      <div class="alert alert-success">Protocolo <b x-text="done.code"></b> gerado.</div>
      <a class="btn btn-primary" :href="done.pdf_url" target="_blank">Abrir PDF</a>
      <a class="btn btn-outline-primary" href="<?= e(url('/operacao')) ?>">Próxima entrega</a>
    </div>
  </template>
  <form x-show="!done" @submit.prevent="save">
    <template x-if="req">
      <div class="mb-3">
        <div class="fw-semibold" x-text="req.code + ' — ' + req.purpose"></div>
        <template x-for="it in req.items" :key="it.id">
          <div x-text="it.item.name + ' × ' + (it.qty_approved ?? it.qty_requested)"></div>
        </template>
      </div>
    </template>
    <div class="mb-3"><label class="form-label">Nome de quem retirou *</label><input class="form-control" x-model="f.received_by_name" required></div>
    <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" x-model="f.received_by_email"></div>
    <div class="mb-3"><label class="form-label">Documento</label><input class="form-control" x-model="f.received_by_document"></div>
    <div class="mb-3">
      <label class="form-label">Assinatura *</label>
      <canvas id="sig" class="sig-pad"></canvas>
      <button type="button" class="btn btn-sm btn-outline-secondary mt-1" @click="clear()">Limpar</button>
    </div>
    <button class="btn btn-primary btn-lg w-100" :disabled="saving">Confirmar entrega</button>
  </form>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script src="<?= e(asset('vendor/signature_pad/signature_pad.umd.min.js')) ?>"></script>
<script>
function deliverPage(id) {
  return {
    req: null, pad: null, saving: false, done: null, key: Api.newKey(),
    f: { received_by_name: '', received_by_email: '', received_by_document: '' },
    async init() {
      this.req = (await Api.get('/api/requests/' + id)).data;
      this.$nextTick(() => {
        const c = document.getElementById('sig');
        this.pad = UI.signaturePad(c);
      });
    },
    clear() { this.pad && this.pad.clear(); },
    async save() {
      if (!this.pad || this.pad.isEmpty()) { UI.toast('Assine no campo indicado.', 'err'); return; }
      this.saving = true;
      try {
        const { data } = await Api.post('/api/requests/' + id + '/deliver', {
          ...this.f, signature: this.pad.toDataURL('image/png')
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
include BASE_PATH . '/resources/views/layouts/app.php';
