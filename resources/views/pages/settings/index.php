<?php ob_start(); ?>
<div class="card card-body" style="max-width:640px" x-data="settingsPage()" x-init="load()">
  <div class="mb-3">
    <label class="form-label">Nome da empresa</label>
    <input class="form-control" x-model="f.company_name">
  </div>
  <div class="mb-3">
    <label class="form-label">Cor principal</label>
    <input class="form-control form-control-color" type="color" x-model="f.primary_color" @input="document.documentElement.style.setProperty('--brand-primary', f.primary_color)">
  </div>
  <div class="mb-3">
    <label class="form-label">Logo</label>
    <input class="form-control" type="file" accept="image/*" @change="logo=$event.target.files[0]">
    <img class="mt-2" style="max-height:48px" x-show="f.logo_url" :src="f.logo_url">
  </div>
  <div class="mb-3">
    <label class="form-label">Dias para solicitação parada</label>
    <input class="form-control" type="number" min="1" x-model="f.stalled_days">
  </div>
  <div class="mb-3">
    <label class="form-label">E-mail no evento</label>
    <select class="form-select" x-model="f.event_email_mode">
      <option value="por_retirada">A cada retirada</option>
      <option value="consolidado">Resumo no encerramento</option>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">E-mails de alerta (separados por vírgula)</label>
    <input class="form-control" x-model="alertText">
  </div>
  <div class="alert" :class="f.mail_smtp ? 'alert-success' : 'alert-warning'" x-show="f.mail_driver">
    <span x-show="f.mail_smtp">Envio de e-mail por SMTP ativo.</span>
    <span x-show="!f.mail_smtp">Nesta demonstração o e-mail fica registrado no sistema. No servidor de vocês, configure SMTP no arquivo .env (MAIL_DRIVER=smtp + host/usuário/senha) para o comprovante da retirada sair na hora.</span>
  </div>
  <button class="btn btn-primary" @click="save">Salvar</button>
  <button class="btn btn-outline-secondary" @click="testMail">Testar e-mail</button>
  <a class="btn btn-outline-secondary" :href="Api.url('/api/admin/backup')">Baixar backup do banco</a>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function settingsPage() {
  return {
    f: {}, logo: null, alertText: '',
    async load() {
      this.f = (await Api.get('/api/settings')).data;
      this.alertText = (this.f.alert_emails || []).join(', ');
    },
    async save() {
      try {
        await Api.put('/api/settings', {
          company_name: this.f.company_name,
          primary_color: this.f.primary_color,
          stalled_days: Number(this.f.stalled_days),
          event_email_mode: this.f.event_email_mode,
          alert_emails: this.alertText
        });
        if (this.logo) {
          const fd = new FormData(); fd.append('logo', this.logo);
          await Api.upload('/api/settings/logo', fd);
        }
        UI.toast('Configurações salvas.', 'ok');
        location.reload();
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async testMail() {
      try { const { data } = await Api.post('/api/settings/test-email'); UI.toast(data.message, 'ok'); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
