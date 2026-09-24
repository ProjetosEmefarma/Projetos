<?php ob_start(); ?>
<div class="card card-body" x-data="scanPage()" x-init="init()">
  <p class="text-muted">Aponte a câmera para o QR Code, envie uma foto do código, ou digite (ex.: EME-2026-000001). O sistema identifica indústria, produto e saldo.</p>
  <div class="input-group mb-3">
    <input class="form-control" x-model="code" placeholder="EME-2026-000001" @keydown.enter.prevent="lookup">
    <button class="btn btn-primary" @click="lookup">Identificar</button>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <button class="btn btn-outline-secondary btn-sm" type="button" @click="toggleCam" x-text="camOn ? 'Fechar câmera' : 'Abrir câmera do celular'"></button>
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
  <template x-if="r">
    <div>
      <h2 class="h5" x-text="r.public_code || r.code"></h2>
      <img x-show="r.qr_png" :src="r.qr_png" alt="QR Code" width="200" height="200"
           class="bg-white p-2 rounded mb-2" style="image-rendering:pixelated">
      <p><b x-text="r.industry?.name"></b> · <span x-text="r.status_label"></span><br>
        <span x-text="r.purpose"></span></p>
      <template x-for="it in r.items" :key="it.id">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="flex-grow-1" x-text="it.item.name + ' · disponível ' + Math.max(0, (Number(it.qty_received) || 0) - (Number(it.qty_delivered) || 0))"></span>
          <input class="form-control" style="width:110px" type="number" min="0" x-model.number="it.qty_now" placeholder="Qtd">
        </div>
      </template>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label">Quem está retirando *</label>
          <input class="form-control" x-model="who" required></div>
        <div class="col-md-6"><label class="form-label">E-mail para o comprovante *</label>
          <input class="form-control" type="email" x-model="email" placeholder="ex.:  contato@industria.com"></div>
        <div class="col-md-6"><label class="form-label">CPF / matrícula</label>
          <input class="form-control" x-model="doc"></div>
        <div class="col-md-6"><label class="form-label">Para quem se destina</label>
          <input class="form-control" x-model="dest"></div>
        <div class="col-12"><label class="form-label">Observação</label>
          <textarea class="form-control" x-model="notes"></textarea></div>
      </div>
      <div class="mt-2"><canvas id="sigPad" class="sig-pad"></canvas>
        <button type="button" class="btn btn-sm btn-link" @click="clearSig">Limpar assinatura</button></div>
      <button class="btn btn-primary mt-3" :disabled="saving" @click="withdraw">Confirmar retirada (atualiza estoque)</button>
    </div>
  </template>
</div>
<?php
$content = ob_get_clean();
ob_start(); ?>
<script src="<?= e(asset('vendor/signature_pad/signature_pad.umd.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/jsqr/jsQR.js')) ?>"></script>
<script>
function scanPage() {
  const qs = new URLSearchParams(location.search);
  return {
    code: qs.get('code') || '', r: null, who: '', email: '', doc: '', dest: '', notes: '', saving: false, pad: null,
    camOn: false, stream: null, camErr: '', timer: null, detector: null,
    async init() { if (this.code) this.lookup(); },
    extract(raw) {
      const s = String(raw || '').trim();
      const m = s.match(/EME-\d{4}-\d+/i);
      if (m) return m[0].toUpperCase();
      try {
        const u = new URL(s);
        const c = u.searchParams.get('code');
        if (c) return this.extract(c);
      } catch (e) {}
      return s;
    },
    async lookup() {
      const code = this.extract(this.code);
      if (!code) { UI.toast('Informe ou leia o código.', 'err'); return; }
      this.code = code;
      try {
        const { data } = await Api.get('/api/trade/lookup', { code: code });
        if (data.type === 'event') { location.href = Api.url('/eventos/' + data.event.id + '/modo-evento'); return; }
        data.items.forEach(it => { it.qty_now = 0; });
        this.r = data;
        this.$nextTick(() => {
          const c = document.getElementById('sigPad');
          this.pad = UI.signaturePad(c);
        });
      } catch (e) { UI.toast(e.message, 'err'); }
    },
    stopCam() {
      if (this.timer) { clearTimeout(this.timer); this.timer = null; }
      if (this.stream) this.stream.getTracks().forEach(t => t.stop());
      this.stream = null;
      this.camOn = false;
      const v = document.getElementById('qrVideo');
      if (v) v.srcObject = null;
    },
    async toggleCam() {
      this.camErr = '';
      if (this.camOn) { this.stopCam(); return; }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.camErr = 'Este navegador não abre a câmera. Use “Ler QR pela foto” ou digite o código.';
        return;
      }
      try {
        this.camOn = true;
        await this.$nextTick();
        this.stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        const v = document.getElementById('qrVideo');
        v.setAttribute('playsinline', 'true');
        v.setAttribute('webkit-playsinline', 'true');
        v.muted = true;
        v.srcObject = this.stream;
        await v.play();
        if ('BarcodeDetector' in window) {
          try { this.detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { this.detector = null; }
        }
        this.tick();
      } catch (e) {
        this.stopCam();
        this.camErr = 'Não foi possível abrir a câmera. Permita o acesso ou envie uma foto do QR.';
      }
    },
    async tick() {
      if (!this.camOn) return;
      const v = document.getElementById('qrVideo');
      const canvas = document.getElementById('qrCanvas');
      if (!v || v.readyState < 2) { this.timer = setTimeout(() => this.tick(), 200); return; }
      try {
        if (this.detector) {
          const codes = await this.detector.detect(v);
          if (codes && codes[0] && codes[0].rawValue) { this.gotCode(codes[0].rawValue); return; }
        }
        if (typeof jsQR === 'function' && canvas) {
          const w = v.videoWidth || 640;
          const h = v.videoHeight || 480;
          canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d', { willReadFrequently: true });
          ctx.drawImage(v, 0, 0, w, h);
          const img = ctx.getImageData(0, 0, w, h);
          const found = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
          if (found && found.data) { this.gotCode(found.data); return; }
        }
      } catch (e) {}
      this.timer = setTimeout(() => this.tick(), 180);
    },
    gotCode(raw) {
      this.code = this.extract(raw);
      this.stopCam();
      UI.toast('QR lido: ' + this.code, 'ok');
      this.lookup();
    },
    fromFile(ev) {
      const file = ev.target.files && ev.target.files[0];
      ev.target.value = '';
      if (!file) return;
      if (typeof jsQR !== 'function') { UI.toast('Leitor de QR indisponível. Digite o código.', 'err'); return; }
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth || img.width;
        canvas.height = img.naturalHeight || img.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const found = jsQR(data.data, data.width, data.height, { inversionAttempts: 'attemptBoth' });
        URL.revokeObjectURL(img.src);
        if (found && found.data) this.gotCode(found.data);
        else UI.toast('Não deu para ler o QR nesta foto. Tente outra ou digite o código.', 'err');
      };
      img.onerror = () => UI.toast('Não foi possível abrir essa imagem.', 'err');
      img.src = URL.createObjectURL(file);
    },
    clearSig() { this.pad?.clear(); },
    async withdraw() {
      if (!this.who.trim()) { UI.toast('Informe quem está retirando.', 'err'); return; }
      if (!this.email.trim() || !this.email.includes('@')) { UI.toast('Informe o e-mail para enviar o comprovante.', 'err'); return; }
      const items = this.r.items.filter(i => Number(i.qty_now) > 0).map(i => ({ item_id: i.item.id, qty: Number(i.qty_now) }));
      if (!items.length) { UI.toast('Informe a quantidade retirada.', 'err'); return; }
      if (!this.pad || this.pad.isEmpty()) { UI.toast('A assinatura é obrigatória.', 'err'); return; }
      this.saving = true;
      try {
        const { data } = await Api.postIdem('/api/trade/requests/' + this.r.id + '/withdraw', {
          received_by_name: this.who, received_by_email: this.email, received_by_document: this.doc, recipient: this.dest,
          notes: this.notes, signature: this.pad.toDataURL('image/png'), items
        });
        UI.toast('Retirada registrada. Abra o protocolo para enviar as informações por e-mail.', 'ok');
        location.href = Api.url('/protocolos/' + data.id);
      } catch (e) { UI.toast(e.message, 'err'); }
      this.saving = false;
    }
  };
}
</script>
<?php
$scripts = ob_get_clean();
include BASE_PATH . '/resources/views/layouts/app.php';
