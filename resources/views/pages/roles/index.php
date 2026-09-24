<?php ob_start(); ?>
<div x-data="rolesPage()" x-init="load()">
  <p class="text-muted">O perfil Administrador sempre tem acesso total.</p>
  <template x-for="role in roles" :key="role.id">
    <div class="card card-body mb-3">
      <h3 class="h6" x-text="role.name + ' (' + role.users_count + ' usuários)'"></h3>
      <template x-if="!role.editable"><p class="mb-0 small">Acesso total (não editável).</p></template>
      <template x-if="role.editable">
        <div>
          <template x-for="g in groups" :key="g.module">
            <div class="mb-2">
              <div class="small text-muted" x-text="g.module"></div>
              <template x-for="p in g.permissions" :key="p.slug">
                <label class="me-3 small">
                  <input type="checkbox" :checked="role.permissions.includes(p.slug)" @change="toggle(role, p.slug, $event.target.checked)">
                  <span x-text="p.name"></span>
                </label>
              </template>
            </div>
          </template>
          <button class="btn btn-sm btn-primary" @click="save(role)">Salvar</button>
        </div>
      </template>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script>
function rolesPage() {
  return {
    roles: [], groups: [],
    async load() {
      this.roles = (await Api.get('/api/roles')).data;
      this.groups = (await Api.get('/api/permissions')).data;
    },
    toggle(role, slug, on) {
      if (on && !role.permissions.includes(slug)) role.permissions.push(slug);
      if (!on) role.permissions = role.permissions.filter(s => s !== slug);
    },
    async save(role) {
      try { await Api.put('/api/roles/' + role.id + '/permissions', { permissions: role.permissions }); UI.toast('Permissões salvas.', 'ok'); this.load(); }
      catch (e) { UI.toast(e.message, 'err'); }
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
