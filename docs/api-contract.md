# API Contract — Controle de Brindes v1.0

> Single source of truth between the **backend** (`app/`, `database/`, `config/`, `public/index.php`, `public/assets/js/api.js`)
> and the **UI** (`resources/views/`, `public/assets/` except `api.js`).
> Status: **Step 1 endpoints are LIVE and covered by `tests/scenarios.php` (84 scenarios).** Step 2/3 endpoints are listed at the end as *planned*.

---

## 0. Quick start for the UI

| | |
|---|---|
| Local URL | `http://127.0.0.1:8000` (start with `bin\serve.bat`, see README) |
| Demo logins (password `Demo@123`) | `admin@brindes.local` (Administrador) · `gestor@brindes.local` (Gestor/Aprovador) · `operacao@brindes.local` (Operação/Estoque) · `solicitante@brindes.local` (Solicitante) |
| API client | `public/assets/js/api.js` → global `Api` (handles CSRF, Idempotency-Key, errors, 401 redirect, PT-BR formatters, labels) |
| Pages | Server-rendered PHP shells in `resources/views/pages/...`; each page loads its data with `Api.get(...)` |

---

## 1. Conventions

### 1.1 Envelope
```jsonc
// success
{ "ok": true, "data": { ... } | [ ... ], "meta": { "page": 1, "per_page": 25, "total": 12, "last_page": 1 } }  // meta only on lists
// error
{ "ok": false, "error": { "code": "VALIDATION_ERROR", "message": "Verifique os campos destacados.",
                          "fields": { "name": "Campo obrigatório." },      // only VALIDATION_ERROR
                          "details": { "available": 5 } } }                  // some business errors
```
All `message` texts are **PT-BR, ready to show to the user**.

### 1.2 Error handling in the UI
| Situation | What the UI does |
|---|---|
| `VALIDATION_ERROR` (422) | show each `fields[name]` under its input (Bootstrap `is-invalid` + `invalid-feedback`) |
| any other error | toast/alert with `error.message` |
| `401 UNAUTHENTICATED` | `api.js` redirects to `/login?next=<current page>` automatically |
| `403 PASSWORD_CHANGE_REQUIRED` | `api.js` redirects to `/perfil?trocar-senha=1` automatically |
| `NETWORK_ERROR` (status 0) | toast "Sem conexão…" and keep the form filled (same Idempotency-Key on retry) |

### 1.3 CSRF
Every `POST/PUT/DELETE` needs header `X-CSRF-Token`. The token is in `<meta name="csrf-token">` (every page) and in the `data.csrf_token` of `/api/auth/csrf`, `/login`, `/logout`, `/me`. `api.js` does all of it.

### 1.4 Idempotency (duplicate-submission protection)
Stock writes (`POST /api/stock/entries|exits|adjustments`, and Step 2 deliveries/withdrawals) **require** `Idempotency-Key`.
Rule for the UI: **generate one key when the form opens** (`Api.newKey()`), reuse it if the user retries the same submission, and generate a new one after success (next form).
Repeated key + same body → the original response is returned again (header `Idempotent-Replayed: true`), **no second movement**. Same key + different body → `422 IDEMPOTENCY_KEY_REUSED`.
Also: disable the submit button while the request is in flight.

### 1.5 Lists: pagination, search, sort
`?page=1&per_page=25` (max 100) · `?q=text` · `?sort=name` or `?sort=-name` (desc; whitelisted per endpoint) · `?trashed=1` (records in the trash — admins).
`meta = { page, per_page, total, last_page }`.

### 1.6 Formats
| Type | Format in JSON | Display (`Api.fmt`) |
|---|---|---|
| date | `"2026-09-14"` | `14/09/2026` |
| datetime | `"2026-09-14 10:05:00"` (São Paulo time) | `14/09/2026 10:05` |
| money | number `389.9` | `R$ 389,90` |
| quantity | integer; movements are signed (`-2` = saída) | `+10` / `-2` |
| booleans | `true/false` | — |

Money **inputs** accept `389.90`, `389,90`, `1.234,56` or numbers.

### 1.7 Soft delete / trash (applies to items, users and all lookups)
| UI action | Endpoint | Rule |
|---|---|---|
| **Inativar / Ativar** | `POST …/{id}/deactivate` · `/activate` | record stays visible, cannot be used in new operations |
| **Excluir** (→ lixeira) | `DELETE …/{id}` | hidden from lists; history kept; reversible |
| **Restaurar** | `POST …/{id}/restore` | from the trash view (`?trashed=1`) |
| **Excluir definitivamente** | `POST …/{id}/purge` body `{"confirm":"<code/name/email>"}` | only from the trash, only if never used (`409 IN_USE` lists where it is used), only with the typed confirmation. Show a modal asking the user to type the value. |

---

## 2. Views (pages) contract

### 2.1 Every view receives `$app`
```php
$app = [
  'user'        => [...same shape as /api/auth/me user...] | null,
  'permissions' => ['items.view', 'stock.entry', ...],
  'csrf'        => '64-hex token',              // put in <meta name="csrf-token">
  'settings'    => ['company_name' => 'Controle de Brindes', 'primary_color' => '#2563EB', 'logo_url' => '/api/settings/logo?v=..'|null],
  'menu'        => [ ['group' => 'Estoque', 'items' => [ ['label','icon','url','path','active','soon'], ... ]], ... ],  // already filtered by permission
  'page'        => ['path' => '/brindes/5', 'view' => 'pages/items/show', 'title' => 'Brinde', 'params' => ['id' => '5'], 'query' => [...]],
  'base_url'    => '',                          // '' at domain root, '/sub' when installed in a sub-folder
  'error'       => ['status' => 404, 'message' => '...'],   // only on error pages
];
```
Helpers available in views: `e($value)` (escape — **always** use it), `url('/brindes')`, `asset('css/app.css')` (adds cache-busting `?v=`), `can('items.manage')`, `setting('company_name')`, `json_script($data)` (safe JSON inside `<script>`).

### 2.2 Required `<head>` tags (the layout)
```html
<meta name="csrf-token" content="<?= e($app['csrf']) ?>">
<meta name="base-url"   content="<?= e($app['base_url']) ?>">
<style>:root { --brand-primary: <?= e($app['settings']['primary_color']) ?>; }</style>
<script src="<?= asset('js/api.js') ?>"></script>
```
Suggested structure: `resources/views/layouts/app.php` (sidebar + top bar) and `layouts/auth.php` (login pages). A page does:
```php
<?php $title = 'Brindes'; ob_start(); ?>
  ... page HTML ...
<?php $content = ob_get_clean(); include BASE_PATH . '/resources/views/layouts/app.php'; ?>
```

### 2.3 Page routes → view files
| URL | View file (`resources/views/…`) | Permission | Step |
|---|---|---|---|
| `/login` | `pages/auth/login.php` | guest | 1 |
| `/esqueci-senha` | `pages/auth/forgot.php` | guest | 1 |
| `/redefinir-senha?token=…` | `pages/auth/reset.php` | guest | 1 |
| `/dashboard` | `pages/dashboard.php` | dashboard.view | 1 |
| `/perfil` (`?trocar-senha=1` = forced change) | `pages/profile.php` | logged | 1 |
| `/notificacoes` | `pages/notifications.php` | logged | 2 |
| `/brindes` | `pages/items/index.php` | items.view | 1 |
| `/brindes/novo` · `/brindes/{id}/editar` | `pages/items/form.php` (`$app['page']['params']['id']` when editing) | items.manage | 1 |
| `/brindes/{id}` | `pages/items/show.php` | items.view | 1 |
| `/estoque` | `pages/stock/index.php` | stock.view | 1 |
| `/estoque/entrada` · `/saida` · `/ajuste` | `pages/stock/entry.php` · `exit.php` · `adjust.php` (`?item_id=` pre-selects) | stock.entry · stock.exit · stock.adjust | 1 |
| `/cadastros/{categorias|departamentos|industrias|locais|fornecedores}` | `pages/lookups/index.php` (one generic page, see `/api/lookups/schema`) | lookups.view | 1 |
| `/usuarios` | `pages/users/index.php` | users.view | 1 |
| `/perfis` | `pages/roles/index.php` | roles.manage | 1 |
| `/auditoria` | `pages/audit/index.php` | audit.view | 1 |
| `/configuracoes` | `pages/settings/index.php` | settings.manage | 1 |
| `/solicitacoes`, `/solicitacoes/nova`, `/solicitacoes/{id}`, `/aprovacoes`, `/operacao`, `/operacao/{id}/entrega`, `/eventos`, `/eventos/{id}`, `/eventos/{id}/modo-evento`, `/protocolos`, `/protocolos/{id}`, `/regras-aprovacao` | `pages/requests/…`, `approvals/`, `operations/`, `events/`, `deliveries/`, `rules/` | see `app/pages.php` | 2 |
| `/relatorios`, `/importar` | `pages/reports/index.php`, `pages/import/index.php` | reports.view, import.run | 3 |
| errors | `pages/errors/403.php`, `404.php`, `500.php` or `generic.php` (receive `$app['error']`) | — | 1 |

Until a view file exists, a neutral placeholder is shown (login and forced password change already work on it).

### 2.4 Menu icons
`$app['menu'][*]['items'][*]['icon']` are **Bootstrap Icons** names (`gift`, `box-arrow-in-down`, …) → `<i class="bi bi-<icon>"></i>`. `soon: true` = Step 2/3 page (show an "em breve" badge until then).

---

## 3. Endpoints — Step 1 (LIVE)

Permission = required permission slug (admin always passes). "Logged" = any authenticated user.

### 3.1 Auth
| Method & path | Auth | Body | Returns |
|---|---|---|---|
| `GET /api/auth/csrf` | public | — | `{csrf_token}` |
| `POST /api/auth/login` | public | `{email, password}` | **session payload** (below). Errors: `INVALID_CREDENTIALS` 401, `USER_INACTIVE` 403, `TOO_MANY_ATTEMPTS` 429 (5 wrong passwords / 15 min) |
| `POST /api/auth/logout` | logged | — | `{csrf_token}` (new, for the login page) |
| `GET /api/auth/me` | logged | — | session payload |
| `POST /api/auth/change-password` | logged | `{current_password, new_password, new_password_confirmation}` | session payload. Policy: ≥ 8 chars with letters and numbers |
| `PUT /api/auth/profile` | logged | `{name?, phone?}` | session payload |
| `POST /api/auth/forgot` | public | `{email}` | `{message}` (always the same message) |
| `POST /api/auth/reset` | public | `{token, password, password_confirmation}` | `{message}` · `RESET_TOKEN_INVALID` |

**Session payload**
```json
{
  "user": {
    "id": 1, "name": "Administrador", "email": "admin@brindes.local", "phone": null,
    "role": { "id": 1, "slug": "admin", "name": "Administrador" },
    "department": null, "active": true, "must_change_password": false,
    "last_login_at": "2026-09-14 14:34:50", "created_at": "2026-09-14 14:27:56", "updated_at": "2026-09-14 14:34:50", "deleted_at": null
  },
  "permissions": ["alerts.stock", "audit.view", "dashboard.view", "items.manage", "..."],
  "must_change_password": false,
  "csrf_token": "…",
  "settings": { "company_name": "Controle de Brindes", "primary_color": "#2563EB", "logo_url": null }
}
```
Role slugs: `admin` Administrador · `approver` Gestor/Aprovador · `operations` Operação/Estoque · `requester` Solicitante.

### 3.2 Settings / branding
| Method & path | Permission | Body / notes |
|---|---|---|
| `GET /api/settings/public` | public | `{company_name, primary_color, logo_url}` — for the login page |
| `GET /api/settings/logo` | public | PNG image |
| `GET /api/settings` | settings.manage | `{company_name, primary_color, logo_url, stalled_days, event_email_mode, alert_emails: []}` |
| `PUT /api/settings` | settings.manage | any subset: `company_name` (≤100), `primary_color` (`#RRGGBB`), `stalled_days` (1–60), `event_email_mode` (`por_retirada`/`consolidado`), `alert_emails` (array or "a@b.com, c@d.com") |
| `POST /api/settings/logo` | settings.manage | multipart, field `logo` (PNG/JPG/WEBP ≤ 5 MB; stored as PNG ≤ 600px) |
| `DELETE /api/settings/logo` | settings.manage | — |

### 3.3 Users
| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/users?q=&role_id=&department_id=&active=1|0&trashed=1&sort=name|email|last_login_at|created_at` | users.view | list of user objects |
| `GET /api/users/options` | logged | `[{id, name, department:{id,name}|null}]` active users — for selects (e.g. "Solicitante") |
| `POST /api/users` | users.manage | `{name, email, password, role_id, department_id?, phone?, active?, must_change_password? (default true)}` → 201 |
| `GET /api/users/{id}` · `PUT /api/users/{id}` | users.view · users.manage | PUT: any of `name, email, role_id, department_id, phone, must_change_password, password` (new password → user must change it on next login unless `must_change_password:false`) |
| `POST /api/users/{id}/activate` · `/deactivate` | users.manage | deactivation ends the user's sessions |
| `DELETE /api/users/{id}` · `POST …/restore` · `POST …/purge {confirm: email}` | users.manage | trash rules §1.7. Errors: `SELF_ACTION`, `SELF_ROLE_CHANGE`, `LAST_ADMIN` |

### 3.4 Roles & permissions
| Method & path | Permission | Returns |
|---|---|---|
| `GET /api/roles` | users.view or roles.manage | `[{id, slug, name, description, users_count, editable, permissions:[slugs]}]` |
| `GET /api/permissions` | roles.manage | `[{module:"Brindes", permissions:[{slug, name}]}]` — build a checkbox matrix |
| `PUT /api/roles/{id}/permissions` | roles.manage | body `{permissions:[slugs]}` → full roles list. Admin role → `ROLE_NOT_EDITABLE` |

### 3.5 Lookups (cadastros auxiliares)
`{type}` = `categories` · `departments` · `industries` · `locations` · `suppliers`
| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/lookups/schema` | lookups.view | field definitions for the generic page (below) |
| `GET /api/{type}?q=&active=&trashed=&sort=name|created_at|updated_at` | lookups.view | paginated |
| `GET /api/{type}?all=1` | lookups.view | **all active records, not paginated — for `<select>`s** |
| `POST /api/{type}` · `PUT /api/{type}/{id}` | lookups.manage | fields per schema; name unique (case-insensitive) |
| `POST …/{id}/activate` · `/deactivate` | lookups.manage | |
| `DELETE …/{id}` · `POST …/restore` | lookups.delete | |
| `POST …/{id}/purge {confirm: name}` | lookups.purge | `409 IN_USE` with `details.uses` e.g. `["3 brinde(s)"]` |

Schema (UI builds the form/table from it; URL slug → type via `page`):
```json
[{ "type": "industries", "page": "industrias", "label": "Indústria", "label_plural": "Indústrias", "endpoint": "/api/industries",
   "fields": [ {"name":"name","label":"Nome","type":"text","required":true,"max":150},
               {"name":"cnpj","label":"CNPJ","type":"text","required":false,"max":20},
               {"name":"contact_name","label":"Contato","type":"text","required":false,"max":120},
               {"name":"contact_email","label":"E-mail do contato","type":"email","required":false,"max":190},
               {"name":"contact_phone","label":"Telefone","type":"text","required":false,"max":30},
               {"name":"notes","label":"Observações","type":"textarea","required":false,"max":2000} ] }, ...]
```
Record shape: `{id, <fields...>, active, created_at, updated_at, deleted_at}`. categories/departments/locations have `name, description`; industries/suppliers have the contact fields above.

### 3.6 Items (brindes)
| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/items` | items.view | filters: `q` (code/name/description), `category_id`, `location_id`, `supplier_id`, `status=ativo|inativo`, `stock_level=ok|low|zero|attention` (attention = low+zero), `trashed=1`; `sort=name|code|available|on_hand|unit_value|updated_at|created_at` |
| `GET /api/items/options?q=` | items.view | light list of ACTIVE items (max 50) for pickers: `[{id, code, name, unit_value, available, level, thumb_url}]` |
| `GET /api/items/next-code` | items.manage | `{code:"BRD-00013"}` preview for the form placeholder |
| `POST /api/items` | items.manage | body below → 201 item |
| `GET /api/items/{id}` | items.view | item (also works for items in the trash) |
| `PUT /api/items/{id}` | items.manage | any subset of the body (except `initial_quantity`) |
| `POST /api/items/{id}/photo` | items.manage | multipart field `photo` (JPG/PNG/WEBP ≤ 5 MB) → item |
| `DELETE /api/items/{id}/photo` | items.manage | → item |
| `GET /api/items/{id}/photo[?size=thumb]` | items.view | image (use `photo_url` / `thumb_url` from the item — they include a cache-buster) |
| `POST …/activate` · `/deactivate` | items.manage | deactivate blocked if units are reserved (`ITEM_HAS_RESERVATIONS`) |
| `DELETE /api/items/{id}` | items.delete | trash; blocked while stock > 0 (`ITEM_HAS_STOCK` → tell user to zero it with an adjustment or just inactivate) |
| `POST …/restore` | items.delete | |
| `POST …/purge {confirm: code}` | items.purge | only never-used items (`IN_USE`) |
| `GET /api/items/{id}/history?page=` | items.view | full timeline, newest first (below) |

**Create/update body**
```json
{ "code": "TV-43",            // optional; empty = automatic BRD-00001…; uppercased; unique forever
  "name": "Smart TV 43\"",   // required, ≤150
  "description": "…", "category_id": 2,   // category required
  "location_id": 1, "supplier_id": 1,
  "unit_value": "1.899,00",  // optional, ≥0
  "min_stock": 2,            // default 0 (0 = no low-stock alert)
  "status": "ativo",         // ativo | inativo
  "entry_date": "2026-09-14",// optional; auto-filled on the first entrada
  "notes": "…",
  "initial_quantity": 10     // CREATE only; creates an "entrada" movement "Saldo inicial" (needs stock.entry)
}
```
**Item object** (real sample)
```json
{ "id": 12, "code": "BRD-00012", "name": "Squeeze 600ml", "description": "Squeeze 600ml para ações e eventos.",
  "category": {"id": 6, "name": "Utilidades"}, "location": {"id": 1, "name": "CD - Centro de Distribuição"},
  "supplier": {"id": 1, "name": "Fornecedor Exemplo Ltda"},
  "unit_value": 14.9, "min_stock": 100, "status": "ativo", "entry_date": "2026-08-20", "notes": null,
  "photo_url": null, "thumb_url": null,
  "stock": {"on_hand": 347, "reserved": 0, "available": 347, "level": "ok", "level_label": "Disponível"},
  "stock_value": 5170.3,
  "created_at": "2026-08-20 09:00:00", "updated_at": "2026-09-14 14:27:57", "deleted_at": null }
```
`stock.level`: `zero` (available ≤ 0) · `low` (min_stock > 0 and available ≤ min_stock) · `ok`. **available = on_hand − reserved** (reserved = approved requests / open events, Step 2). Show *available* as the main number.

**History entry**
```json
{ "kind": "movement", "id": 52, "at": "2026-09-12 14:52:00", "user": {"id":1,"name":"Administrador"},
  "action": "saida", "action_label": "Saída", "quantity": -13, "balance_after": 99,
  "reason": null, "purpose": "Convenção comercial", "recipient": "Cliente 535", "purchase_ticket_no": null, "notes": null,
  "before": null, "after": null }
{ "kind": "change", "id": 40, "at": "…", "user": {…}, "action": "update", "action_label": "Alteração",
  "quantity": null, "balance_after": null, "before": {"min_stock": 5}, "after": {"min_stock": 12}, ... }
```

### 3.7 Stock
| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/stock/summary` | stock.view | `{items_active, units_on_hand, units_reserved, units_available, stock_value, low_stock, zero_stock}` |
| `GET /api/stock/movements` | stock.view | filters `item_id, type=entrada|saida|ajuste, from, to (YYYY-MM-DD), user_id, industry_id, department_id, requester_id, category_id, q` (item code/name, recipient, purpose, purchase ticket, document); `sort=created_at|item|qty` (default newest first) |
| `GET /api/stock/movements/{id}` | stock.view | one movement |
| `POST /api/stock/entries` 🔑 | stock.entry | `{item_id, quantity (≥1), unit_value?, supplier_id?, purchase_ticket_no?, document_ref? (NF), notes?}` → 201 |
| `POST /api/stock/exits` 🔑 | stock.exit | `{item_id, quantity, purpose (required), recipient?, industry_id?, department_id?, requester_id?, purchase_ticket_no?, notes?}` → 201 · `STOCK_INSUFFICIENT` (details.available) |
| `POST /api/stock/adjustments` 🔑 | stock.adjust | `{item_id, mode: "set"|"delta", quantity, reason (required), notes?}` — **set = counted quantity** (monthly count at the CD: type what you counted), delta = ±units → 201 · `STOCK_INSUFFICIENT`, `STOCK_BELOW_RESERVED`, `ADJUSTMENT_NO_CHANGE` |

🔑 = requires `Idempotency-Key`. Inactive items can't have entries/exits (`ITEM_INACTIVE`); adjustments are allowed.

**Write response (201)**
```json
{ "movement": { …movement object… }, "stock": {"on_hand": 3, "reserved": 0, "available": 3, "level": "ok", "level_label": "Disponível"} }
```
**Movement object** (real sample)
```json
{ "id": 53, "type": "saida", "type_label": "Saída", "quantity": -1, "balance_after": 5,
  "unit_value": 1899, "total_value": 1899,
  "item": {"id": 2, "code": "BRD-00002", "name": "Smart TV 43\""}, "user": {"id": 1, "name": "Administrador"},
  "requester": null, "department": {"id": 3, "name": "Trade Marketing"}, "industry": {"id": 4, "name": "Indústria Delta"},
  "supplier": null, "recipient": "Cliente 391", "purpose": "Ação de vendas",
  "purchase_ticket_no": null, "document_ref": null, "reason": null, "notes": null,
  "request_id": null, "delivery_id": null, "event_id": null, "created_at": "2026-09-13 16:11:00" }
```

### 3.8 Dashboard
`GET /api/dashboard?from=&to=&category_id=&item_id=&department_id=&industry_id=&user_id=` (dashboard.view). Period defaults to the current month. Blocks the user cannot see come as `null` (e.g. Solicitante has no `stock.view`).
```json
{ "period": {"from": "2026-09-01", "to": "2026-09-14"},
  "stock": {"items_active": 12, "units_on_hand": 1080, "units_reserved": 0, "units_available": 1080, "stock_value": 57829.4, "low_stock": 2, "zero_stock": 1},
  "requests": {"pending": 0, "awaiting_approval": 0, "to_prepare": 0, "ready": 0, "finalized_period": 0},
  "period_totals": {"entries_units": 150, "exits_units": 227, "exits_value": 23340.7, "adjustments": 1},
  "series": {"granularity": "day", "points": [{"date": "2026-09-01", "entries": 0, "exits": 44}, …]},   // "month" when period > 92 days (date = "2026-09")
  "top_items": [{"item": {"id": 9, "code": "BRD-00009", "name": "Caderno Personalizado"}, "units": 61, "value": 1128.5}],
  "by_department": [{"id": 1, "name": "Comercial", "units": 103, "value": 13593.9}],
  "by_industry": [{"id": 1, "name": "Indústria Alfa", "units": 40, "value": 9000.0}],
  "alerts": [{"item": {"id": 10, "code": "BRD-00010", "name": "Kit Churrasco"}, "available": 0, "min_stock": 5, "level": "zero", "level_label": "Sem estoque"}],
  "recent_movements": [ …10 movement objects… ] }
```
`requests` counters are live (0 until Step 2 creates requests). KPI cards should link to filtered lists (e.g. low stock → `/brindes?stock_level=low`).

### 3.9 Audit
| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/audit?entity_type=&entity_id=&user_id=&action=&from=&to=&q=` | audit.view | 50 per page; `meta.filters` has PT-BR labels for actions/entities |
| `GET /api/audit/{id}` | audit.view | one entry |
```json
{ "id": 40, "action": "update", "action_label": "Alteração", "entity_type": "item", "entity_label": "Brinde", "entity_id": 1,
  "entity_name": "BRD-00001 - Air Fryer 4L", "user": {"id": 1, "name": "Administrador"},
  "before": {"min_stock": 5}, "after": {"min_stock": 12}, "ip": "10.0.0.1", "user_agent": "…", "created_at": "…" }
```
Show `before → after` as a small diff table.

### 3.10 Health
`GET /api/health` (public) → `{status, version, database, time}`.

---

## 4. Error codes
| Code | HTTP | Meaning |
|---|---|---|
| `VALIDATION_ERROR` | 422 | field errors in `fields` |
| `UNAUTHENTICATED` | 401 | session missing/expired (auto-redirect) |
| `INVALID_CREDENTIALS` | 401 | wrong e-mail/password |
| `FORBIDDEN` | 403 | no permission |
| `CSRF_INVALID` | 403 | reload the page |
| `PASSWORD_CHANGE_REQUIRED` | 403 | auto-redirect to /perfil |
| `USER_INACTIVE` | 403 | login of an inactive user |
| `NOT_FOUND` / `METHOD_NOT_ALLOWED` | 404 / 405 | |
| `INVALID_JSON` / `IDEMPOTENCY_KEY_REQUIRED` | 400 | client bug |
| `STOCK_INSUFFICIENT` | 422 | `details.available`, `details.requested` |
| `STOCK_BELOW_RESERVED` | 422 | adjustment below reserved units |
| `ADJUSTMENT_NO_CHANGE` | 422 | counted = current |
| `ITEM_INACTIVE` | 422 | reactivate first |
| `IDEMPOTENCY_KEY_REUSED` | 422 | reload the form |
| `RESET_TOKEN_INVALID` | 422 | expired/used link |
| `SELF_ACTION` / `SELF_ROLE_CHANGE` / `LAST_ADMIN` / `ROLE_NOT_EDITABLE` | 422 | user-management guards |
| `NOT_IN_TRASH` / `IN_USE` / `ITEM_HAS_STOCK` / `ITEM_HAS_RESERVATIONS` | 409 | deletion guards (`IN_USE` → `details.uses`) |
| `DUPLICATE` / `REQUEST_IN_PROGRESS` | 409 | |
| `TOO_MANY_ATTEMPTS` | 429 | wait 15 min |
| `SERVER_ERROR` | 500 | logged in `storage/logs` |

---

## 5. Planned — Step 2 (Wed) and Step 3 (Thu)
Shapes will follow the same conventions; this section will be replaced by full specs.
```
REQUESTS   GET|POST /api/requests   GET|PUT /api/requests/{id}   POST /api/requests/check-availability
           POST /api/requests/{id}/submit | /cancel | /approve | /reject | /start-picking | /ready | /deliver 🔑
RULES      CRUD /api/approval-rules
EVENTS     GET|POST /api/events   GET|PUT /api/events/{id}   POST /api/events/{id}/open | /close
           GET|PUT /api/events/{id}/allocations   POST /api/events/{id}/withdrawals 🔑   GET /api/events/{id}/balance?industry_id=
PROTOCOLS  GET /api/deliveries   GET /api/deliveries/{id}   GET /api/deliveries/{id}/pdf   POST /api/deliveries/{id}/resend
NOTIF      GET /api/notifications   POST /api/notifications/{id}/read | /read-all
REPORTS    GET /api/reports/{type}?filters&format=json|csv|xlsx          (Step 3)
IMPORT     POST /api/import/{items|requests}/preview | /commit   GET /api/import/template/{type}   (Step 3)
SEARCH     GET /api/search?q=                                             (Step 3)
```
Request statuses (labels/colors already in `Api.labels.requestStatus`):
`rascunho → solicitada → aguardando_aprovacao → aprovada → em_separacao → pronta → finalizada` · `reprovada` · `cancelada`.
