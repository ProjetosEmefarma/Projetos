# resources/views — user interface

PHP view shells rendered by the backend. Each file receives `$app` (user, permissions, csrf, settings, menu, page, base_url)
and loads its data from `/api` with the global `Api` client (`public/assets/js/api.js`).

- Which URL renders which file, and the exact `$app` structure: `docs/api-contract.md` §2.
- UI rules: `.cursor/rules/brindes-ui.mdc`.
- Until a file exists, the backend shows a neutral "em construção" placeholder for that URL.

Suggested tree:
```
layouts/app.php            sidebar + top bar (logged pages)
layouts/auth.php           centered card (login, forgot, reset)
partials/                  toasts, confirm modal, purge modal, pagination, empty state...
pages/auth/login.php · forgot.php · reset.php
pages/dashboard.php
pages/profile.php
pages/items/index.php · form.php · show.php
pages/stock/index.php · entry.php · exit.php · adjust.php
pages/lookups/index.php
pages/users/index.php
pages/roles/index.php
pages/audit/index.php
pages/settings/index.php
pages/errors/403.php · 404.php · 500.php · generic.php
```
