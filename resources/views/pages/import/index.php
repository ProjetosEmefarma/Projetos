<?php ob_start(); ?>
<div class="card card-body" style="max-width:640px" x-data="importPage()">
  <div class="mb-3">
    <label class="form-label">Tipo</label>
    <select class="form-select" x-model="type">
      <option value="items">Brindes</option>
      <option value="requests">Solicitações</option>
    </select>
  </div>
  <p><a :href="Api.url('/api/import/template/' + type)">Baixar modelo Excel</a></p>
  <div class="mb-3"><input class="form-control" type="file" accept=".xlsx" @change="file=$event.target.files[0]"></div>
  <button class="btn btn-outline-primary" @click="preview">Pré-visualizar</button>
  <button class="btn btn-primary" @click="commit" :disabled="!previewed">Importar</button>
  <div class="mt-3" x-show="result">
    <pre class="small" x-text="JSON.stringify(result, null, 2)"></pre>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function importPage() {
  return {
    type: 'items', file: null, previewed: false, result: null,
    async send(path) {
      if (!this.file) { UI.toast('Selecione o arquivo.', 'err'); return; }
      const fd = new FormData(); fd.append('file', this.file);
      const { data } = await Api.upload('/api/import/' + this.type + '/' + path, fd);
      this.result = data;
      return data;
    },
    async preview() { try { await this.send('preview'); this.previewed = true; } catch (e) { UI.toast(e.message, 'err'); } },
    async commit() { try { await this.send('commit'); UI.toast('Importação concluída.', 'ok'); } catch (e) { UI.toast(e.message, 'err'); } }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
