<?php
$page = (string) ($app['page']['params']['type'] ?? 'categorias');
ob_start(); ?>
<div class="card card-body" x-data="lookupsPage()" x-init="init()" data-lookup-page="<?= e($page) ?>">
  <p class="text-muted" x-show="hint" x-text="hint"></p>
  <div class="alert alert-danger" x-show="error" x-text="error"></div>
  <div class="d-flex gap-2 mb-3">
    <input class="form-control" style="max-width:260px" placeholder="Buscar" x-model="q" @input="debounced()">
    <?php if (can('lookups.manage')): ?>
    <button class="btn btn-primary ms-auto" type="button" @click="open()">Novo</button>
    <?php endif; ?>
  </div>
  <p class="text-muted" x-show="!error && !loading && !rows.length">Nenhum registro ainda. Clique em <b>Novo</b> para cadastrar.</p>
  <div class="table-responsive" x-show="rows.length">
    <table class="table">
      <thead><tr><th>Nome</th><th>Ativo</th><th></th></tr></thead>
      <tbody>
        <template x-for="r in rows" :key="r.id">
          <tr>
            <td>
              <div x-text="r.name"></div>
              <div class="small text-muted" x-show="r.kind_label" x-text="r.kind_label"></div>
              <div class="small text-muted" x-show="r.contact_email" x-text="r.contact_email"></div>
            </td>
            <td><span class="badge" :class="r.active ? 'text-bg-success' : 'text-bg-secondary'" x-text="r.active ? 'Sim' : 'Não'"></span></td>
            <td class="text-end">
              <?php if (can('lookups.manage')): ?>
              <button class="btn btn-sm btn-outline-primary" @click="open(r)">Editar</button>
              <?php endif; ?>
              <?php if (can('lookups.delete')): ?>
              <button class="btn btn-sm btn-outline-danger" @click="remove(r)">Excluir</button>
              <?php endif; ?>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <div class="modal fade" id="lookupModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form @submit.prevent="save">
        <div class="modal-header"><h5 class="modal-title" x-text="editId ? 'Editar' : 'Novo'"></h5>
          <button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <template x-for="f in fields" :key="f.name">
            <div class="mb-2">
              <label class="form-label" x-text="f.label + (f.required ? ' *' : '')"></label>
              <textarea class="form-control" x-show="f.type==='textarea'" x-model="form[f.name]"></textarea>
              <select class="form-select" x-show="f.type==='select'" x-model="form[f.name]">
                <option value="">Selecione</option>
                <template x-for="(lab, val) in (f.options || {})" :key="val">
                  <option :value="val" x-text="lab"></option>
                </template>
              </select>
              <input class="form-control" x-show="f.type!=='textarea' && f.type!=='select'" :type="f.type || 'text'" x-model="form[f.name]">
            </div>
          </template>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Salvar</button></div>
      </form>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function lookupsPage() {
  const hints = {
    industrias: 'Cadastre aqui as indústrias parceiras. O e-mail de contato recebe o comprovante da retirada.',
    locais: 'Cadastre o CD, o escritório e outros depósitos. Depois use esses locais na transferência.',
    categorias: 'Categorias dos brindes (eletrônicos, vestuário, etc.).',
    departamentos: 'Áreas internas da empresa.',
    fornecedores: 'Fornecedores de compra dos brindes.'
  };
  return {
    page: '', schema: null, rows: [], fields: [], q: '', form: {}, editId: null, t: null, modal: null,
    error: '', hint: '', loading: true,
    async init() {
      this.page = (this.$el && this.$el.dataset && this.$el.dataset.lookupPage) || '';
      this.hint = hints[this.page] || '';
      try {
        const { data } = await Api.get('/api/lookups/schema');
        const list = Array.isArray(data) ? data : [];
        this.schema = list.find(s => s && s.page === this.page) || null;
        if (!this.schema || !this.schema.endpoint) {
          this.error = 'Este cadastro não está disponível. Atualize a página; se continuar, avise o suporte.';
          this.loading = false;
          return;
        }
        this.fields = this.schema.fields || [];
        const el = document.getElementById('lookupModal');
        if (el && window.bootstrap) this.modal = new bootstrap.Modal(el);
        await this.load();
      } catch (e) {
        this.error = (e && e.message) || 'Não foi possível abrir este cadastro.';
        this.loading = false;
      }
    },
    debounced() { clearTimeout(this.t); this.t = setTimeout(() => this.load(), 300); },
    async load() {
      if (!this.schema) return;
      this.loading = true;
      try {
        const { data } = await Api.get(this.schema.endpoint, { q: this.q, per_page: 50, sort: '-updated_at' });
        this.rows = (data || []).map(r => ({
          ...r,
          kind_label: r.kind === 'cd' ? 'CD / depósito' : r.kind === 'evento' ? 'Evento' : r.kind === 'outro' ? 'Escritório / outro' : ''
        }));
        this.error = '';
      } catch (e) {
        this.error = (e && e.message) || 'Falha ao carregar a lista.';
      }
      this.loading = false;
    },
    open(r) {
      this.editId = r ? r.id : null;
      this.form = {};
      this.fields.forEach(f => {
        const fallback = f.name === 'kind' ? 'outro' : '';
        this.form[f.name] = r && r[f.name] != null && r[f.name] !== '' ? r[f.name] : fallback;
      });
      this.modal && this.modal.show();
    },
    async save() {
      try {
        if (this.editId) await Api.put(this.schema.endpoint + '/' + this.editId, this.form);
        else await Api.post(this.schema.endpoint, this.form);
        this.modal && this.modal.hide(); UI.toast('Salvo. Já aparece na lista.', 'ok'); this.load();
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    async remove(r) {
      if (!await UI.confirm('Enviar para a lixeira?')) return;
      try { await Api.del(this.schema.endpoint + '/' + r.id); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
