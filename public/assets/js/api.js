/*!
 * API client - the single integration point between the UI and the backend.
 * Owned by the backend track: the UI uses it, it does not change it.
 * Contract: docs/api-contract.md
 *
 * Usage:
 *   const { data, meta } = await Api.get('/api/items', { q: 'air', page: 2 });
 *   const key = Api.newKey();                       // once, when a stock form opens
 *   await Api.post('/api/stock/exits', body, { idempotencyKey: key });
 *   await Api.upload('/api/items/5/photo', formData);
 *   try { ... } catch (e) { if (e.code === 'VALIDATION_ERROR') showFieldErrors(e.fields); else toast(e.message); }
 */
(function (global) {
  'use strict';

  const meta = (name) => (document.querySelector('meta[name="' + name + '"]') || {}).content || '';
  const BASE = meta('base-url');
  let csrf = meta('csrf-token');

  class ApiError extends Error {
    constructor(status, error) {
      super((error && error.message) || 'Erro inesperado. Tente novamente.');
      this.name = 'ApiError';
      this.status = status;
      this.code = (error && error.code) || 'UNKNOWN';
      this.fields = (error && error.fields) || {};
      this.details = (error && error.details) || {};
    }
  }

  const handlers = {
    /** 401: session expired -> back to login, returning to the current page afterwards. */
    unauthenticated() {
      const here = location.pathname.slice(BASE.length) + location.search;
      if (!here.startsWith('/login')) {
        location.href = BASE + '/login?next=' + encodeURIComponent(here);
      }
    },
    passwordChangeRequired() {
      location.href = BASE + '/perfil?trocar-senha=1';
    },
  };

  function buildUrl(path, query) {
    let url = BASE + path;
    if (query) {
      const qs = new URLSearchParams();
      Object.entries(query).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') qs.append(k, v === true ? '1' : v === false ? '0' : v);
      });
      const s = qs.toString();
      if (s) url += (url.includes('?') ? '&' : '?') + s;
    }
    return url;
  }

  async function request(method, path, opts) {
    opts = opts || {};
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrf;
    if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;

    let body;
    if (opts.body instanceof FormData) {
      body = opts.body;
    } else if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }

    let res;
    try {
      res = await fetch(buildUrl(path, opts.query), { method, headers, body, credentials: 'same-origin', signal: opts.signal });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      throw new ApiError(0, { code: 'NETWORK_ERROR', message: 'Sem conexão com o servidor. Verifique a internet e tente novamente.' });
    }

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      /* non-JSON response */
    }
    if (json && json.data && typeof json.data.csrf_token === 'string') {
      csrf = json.data.csrf_token; // rotated on login/logout
    }
    if (!res.ok || !json || json.ok === false) {
      const err = new ApiError(res.status, json && json.error);
      if (res.status === 401 && !opts.noRedirect) handlers.unauthenticated(err);
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') handlers.passwordChangeRequired(err);
      throw err;
    }
    return json; // { ok: true, data, meta? }
  }

  /** New Idempotency-Key: create ONE per form opening, reuse it on retries of that same submission. */
  function newKey() {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') return global.crypto.randomUUID();
    const rnd = () => Math.random().toString(36).slice(2);
    return ('k' + Date.now().toString(36) + rnd() + rnd()).slice(0, 40);
  }

  // ---------------------------------------------------------------- formatters (PT-BR)
  const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const int = new Intl.NumberFormat('pt-BR');

  const fmt = {
    money: (v) => (v === null || v === undefined ? '—' : brl.format(v)),
    number: (v) => (v === null || v === undefined ? '—' : int.format(v)),
    /** "2026-09-14" -> "14/09/2026" */
    date: (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '—'),
    /** "2026-09-14 10:05:00" -> "14/09/2026 10:05" */
    datetime: (v) => (v ? fmt.date(v) + ' ' + String(v).slice(11, 16) : '—'),
    /** signed stock quantity: +10 / -3 */
    qty: (v) => (v > 0 ? '+' + int.format(v) : int.format(v)),
  };

  // ---------------------------------------------------------------- shared labels / colors
  const labels = {
    stockLevel: {
      ok: { label: 'Disponível', color: 'success' },
      low: { label: 'Estoque baixo', color: 'warning' },
      zero: { label: 'Sem estoque', color: 'danger' },
    },
    movementType: {
      entrada: { label: 'Entrada', color: 'success', icon: 'box-arrow-in-down' },
      saida: { label: 'Saída', color: 'primary', icon: 'box-arrow-up' },
      ajuste: { label: 'Ajuste', color: 'secondary', icon: 'sliders' },
      transferencia: { label: 'Transferência', color: 'info', icon: 'arrow-left-right' },
      estorno: { label: 'Estorno', color: 'warning', icon: 'arrow-counterclockwise' },
    },
    itemStatus: {
      ativo: { label: 'Ativo', color: 'success' },
      inativo: { label: 'Inativo', color: 'secondary' },
    },
    requestStatus: {
      rascunho: { label: 'Rascunho', color: 'light' },
      solicitada: { label: 'Solicitada', color: 'secondary' },
      aguardando_aprovacao: { label: 'Aguardando aprovação', color: 'warning' },
      aprovada: { label: 'Aprovada', color: 'primary' },
      em_separacao: { label: 'Em separação', color: 'indigo' },
      pronta: { label: 'Pronta para retirada/entrega', color: 'info' },
      finalizada: { label: 'Entregue / Finalizada', color: 'success' },
      reprovada: { label: 'Reprovada', color: 'danger' },
      cancelada: { label: 'Cancelada', color: 'dark' },
      compra_realizada: { label: 'Compra realizada', color: 'primary' },
      aguardando_recebimento: { label: 'Aguardando recebimento', color: 'warning' },
      recebido_cd: { label: 'Recebido no CD', color: 'info' },
      retirado: { label: 'Retirado', color: 'secondary' },
      entregue: { label: 'Entregue', color: 'success' },
    },
  };

  global.Api = {
    get: (path, query, opts) => request('GET', path, Object.assign({}, opts, { query })),
    post: (path, body, opts) => request('POST', path, Object.assign({}, opts, { body: body === undefined ? {} : body })),
    postIdem: (path, body, opts) => request('POST', path, Object.assign({}, opts, { body: body === undefined ? {} : body, idempotencyKey: (opts && opts.idempotencyKey) || newKey() })),
    put: (path, body, opts) => request('PUT', path, Object.assign({}, opts, { body: body === undefined ? {} : body })),
    del: (path, body, opts) => request('DELETE', path, Object.assign({}, opts, { body })),
    upload: (path, formData, opts) => request('POST', path, Object.assign({}, opts, { body: formData })),
    newKey,
    url: (path, query) => buildUrl(path, query),
    csrf: () => csrf,
    handlers,
    ApiError,
    fmt,
    labels,
  };
})(window);
