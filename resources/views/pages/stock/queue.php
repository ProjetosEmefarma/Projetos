<?php ob_start(); ?>
<div class="card card-body" x-data="exitQueue()" x-init="load()">
  <p class="text-muted mb-3">O gestor registra a saída no painel dele. Aqui só aparece o que já foi liberado, com QR para escanear. Confirme para baixar o estoque.</p>

  <div class="input-group mb-3">
    <input class="form-control" x-model="code" placeholder="SAI-2026-0001" @keydown.enter.prevent="lookup">
    <button class="btn btn-primary" type="button" @click="lookup">Identificar</button>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <button class="btn btn-outline-secondary btn-sm" type="button" @click="toggleCam" x-text="camOn ? 'Fechar câmera' : 'Abrir câmera / escanear QR'"></button>
    <label class="btn btn-outline-secondary btn-sm mb-0">
      Ler QR pela foto
      <input type="file" accept="image/*" capture="environment" class="d-none" @change="fromFile($event)">
    </label>
  </div>
  <p class="small text-danger" x-show="camErr" x-text="camErr"></p>
  <div class="position-relative mb-3 rounded overflow-hidden" style="background:#111;max-width:480px" x-show="camOn">
    <video id="qrVideo" class="w-100" muted playsinline autoplay style="max-height:280px;object-fit:cover"></video>
    <canvas id="qrCanvas" class="d-none"></canvas>
  </div>

  <p class="small text-danger" x-show="err" x-text="err"></p>
  <p class="text-muted" x-show="!err && !rows.length">Nenhuma saída autorizada no momento.</p>

  <template x-for="o in rows" :key="o.id">
    <div class="border rounded p-3 mb-3" :class="picked && picked.id === o.id ? 'border-primary' : ''">
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <img :src="o.qr_png" alt="QR" width="140" height="140" class="bg-white p-2 rounded" style="image-rendering:pixelated">
        <div class="flex-grow-1">
          <div class="fw-semibold" x-text="o.code"></div>
          <div x-text="o.item.code + ' — ' + o.item.name"></div>
          <div>Qtd: <b x-text="o.qty"></b> · <span x-text="o.purpose"></span></div>
          <div class="text-muted small" x-show="o.recipient" x-text="'Destino: ' + o.recipient"></div>
          <div class="text-muted small" x-text="o.status_label + ' · autorizado por ' + (o.authorized_by?.name || '')"></div>
          <button class="btn btn-primary mt-2" type="button" :disabled="saving === o.id" @click="confirm(o)">
            Confirmar saída
          </button>
        </div>
      </div>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script src="<?= e(asset('vendor/jsqr/jsQR.js')) ?>"></script>
<script>
function exitQueue() {
  return {
    rows: [], code: '', err: '', picked: null, saving: 0,
    camOn: false, stream: null, camErr: '', timer: null,
    async load() {
      this.err = '';
      try {
        const { data } = await Api.get('/api/stock/exit-orders', { status: 'autorizada' });
        this.rows = data || [];
      } catch (e) {
        this.err = e.message;
      }
    },
    extract(raw) {
      const s = String(raw || '').trim();
      const m = s.match(/SAI-\d{4}-\d+/i);
      if (m) return m[0].toUpperCase();
      try {
        const u = new URL(s);
        const c = u.searchParams.get('code');
        if (c) return this.extract(c);
      } catch (e) {}
      return s.toUpperCase();
    },
    async lookup() {
      const code = this.extract(this.code);
      if (!code) { UI.toast('Informe ou leia o QR.', 'err'); return; }
      this.code = code;
      try {
        const { data } = await Api.get('/api/stock/exit-orders/lookup', { code });
        this.picked = data;
        if (data.status !== 'autorizada') {
          UI.toast(data.status_label || 'Esta saída já foi tratada.', 'err');
          return;
        }
        if (!this.rows.some(r => r.id === data.id)) this.rows.unshift(data);
        UI.toast('Saída identificada: ' + data.code, 'ok');
      } catch (e) {
        UI.toast(e.message, 'err');
      }
    },
    async confirm(o) {
      this.saving = o.id;
      try {
        await Api.post('/api/stock/exit-orders/' + o.id + '/confirm', {}, { idempotencyKey: Api.newKey() });
        UI.toast('Saída confirmada. Estoque atualizado.', 'ok');
        this.rows = this.rows.filter(r => r.id !== o.id);
        if (this.picked && this.picked.id === o.id) this.picked = null;
      } catch (e) {
        UI.toast(e.message, 'err');
      }
      this.saving = 0;
    },
    async toggleCam() {
      if (this.camOn) { this.stopCam(); return; }
      this.camErr = '';
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const v = document.getElementById('qrVideo');
        v.srcObject = this.stream;
        await v.play();
        this.camOn = true;
        this.tick();
      } catch (e) {
        this.camErr = 'Não foi possível abrir a câmera.';
      }
    },
    stopCam() {
      this.camOn = false;
      if (this.timer) cancelAnimationFrame(this.timer);
      if (this.stream) this.stream.getTracks().forEach(t => t.stop());
      this.stream = null;
    },
    tick() {
      if (!this.camOn) return;
      const v = document.getElementById('qrVideo');
      const c = document.getElementById('qrCanvas');
      if (v && v.readyState >= 2 && typeof jsQR === 'function') {
        c.width = v.videoWidth; c.height = v.videoHeight;
        const ctx = c.getContext('2d');
        ctx.drawImage(v, 0, 0);
        const img = ctx.getImageData(0, 0, c.width, c.height);
        const q = jsQR(img.data, img.width, img.height);
        if (q && q.data) {
          this.code = this.extract(q.data);
          this.stopCam();
          this.lookup();
          return;
        }
      }
      this.timer = requestAnimationFrame(() => this.tick());
    },
    fromFile(ev) {
      const f = ev.target.files && ev.target.files[0];
      if (!f) return;
      const img = new Image();
      img.onload = () => {
        const c = document.getElementById('qrCanvas');
        c.width = img.width; c.height = img.height;
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const data = ctx.getImageData(0, 0, c.width, c.height);
        const q = typeof jsQR === 'function' ? jsQR(data.data, data.width, data.height) : null;
        if (q && q.data) { this.code = this.extract(q.data); this.lookup(); }
        else UI.toast('Não li o QR nesta foto.', 'err');
      };
      img.src = URL.createObjectURL(f);
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
