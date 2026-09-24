(function (global) {
  'use strict';

  function toast(msg, type) {
    type = type || 'info';
    let wrap = document.querySelector('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      wrap.setAttribute('aria-live', 'polite');
      document.body.appendChild(wrap);
    }
    const el = document.createElement('div');
    el.className = 'toast-item ' + (type === 'error' || type === 'err' ? 'err' : type === 'ok' || type === 'success' ? 'ok' : 'info');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.remove(); }, 4200);
  }

  function fieldErrors(root, fields) {
    clearErrors(root);
    Object.entries(fields || {}).forEach(function ([name, msg]) {
      const input = root.querySelector('[name="' + name + '"]');
      if (input) {
        input.classList.add('is-invalid');
        let fb = input.parentElement.querySelector('.invalid-feedback');
        if (!fb) {
          fb = document.createElement('div');
          fb.className = 'invalid-feedback';
          input.parentElement.appendChild(fb);
        }
        fb.textContent = msg;
        fb.style.display = 'block';
      } else {
        toast(msg, 'err');
      }
    });
  }

  function clearErrors(root) {
    root.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    root.querySelectorAll('.invalid-feedback').forEach(function (el) { el.textContent = ''; el.style.display = 'none'; });
  }

  function confirmDialog(message) {
    return Promise.resolve(window.confirm(message));
  }

  async function purgeDialog(expected) {
    const typed = window.prompt('Digite "' + expected + '" para confirmar a exclusão definitiva:');
    return typed && typed.trim().toLowerCase() === String(expected).toLowerCase() ? typed.trim() : null;
  }

  function qs() {
    return Object.fromEntries(new URLSearchParams(location.search));
  }

  function debounce(fn, ms) {
    let t;
    return function () {
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(null, args); }, ms);
    };
  }

  function badge(kind, slug) {
    const map = (Api.labels[kind] || {})[slug] || { label: slug, color: 'secondary' };
    return '<span class="badge text-bg-' + map.color + ' badge-stock-' + slug + '">' + map.label + '</span>';
  }

  function pager(meta) {
    if (!meta || meta.last_page <= 1) return '';
    let html = '<nav class="mt-3"><ul class="pagination pagination-sm mb-0">';
    for (let p = 1; p <= meta.last_page; p++) {
      html += '<li class="page-item' + (p === meta.page ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
    }
    return html + '</ul></nav>';
  }

  async function catchApi(fn, form) {
    try {
      return await fn();
    } catch (e) {
      if (e && e.name === 'AbortError') return;
      if (e && e.code === 'VALIDATION_ERROR' && form) fieldErrors(form, e.fields);
      else toast((e && e.message) || 'Erro inesperado', 'err');
      throw e;
    }
  }

  function signaturePad(canvas) {
    if (!canvas || !window.SignaturePad) return null;
    var ratio = Math.max(window.devicePixelRatio || 1, 1);
    var w = Math.max(canvas.offsetWidth || 360, 280);
    var h = Math.max(canvas.offsetHeight || 140, 120);
    canvas.width = Math.floor(w * ratio);
    canvas.height = Math.floor(h * ratio);
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    var ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    return new SignaturePad(canvas, {
      backgroundColor: 'rgb(255,255,255)',
      penColor: 'rgb(15, 23, 42)',
      minWidth: 0.8,
      maxWidth: 2.6
    });
  }

  global.UI = { toast, fieldErrors, clearErrors, confirmDialog, confirm: confirmDialog, purgeDialog, qs, debounce, badge, pager, catchApi, signaturePad };
})(window);
