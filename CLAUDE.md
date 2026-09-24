# Controle de Brindes — conventions

Plain PHP 8.2 (no framework) + MySQL/MariaDB + server-rendered PHP view shells whose JS calls a JSON API.
Plan and business rules: `../schedule.md` (§5–§9). API contract: `docs/api-contract.md`. DB: `docs/database.md`.

## Folder ownership
- Backend track (Claude): `app/`, `database/`, `config/`, `bin/`, `tests/`, `docs/`, `public/index.php`, `public/assets/js/api.js`.
- UI track (Cursor): `resources/views/`, `public/assets/` (except `js/api.js`). Do not edit each other's folders.
- Any API change must update `docs/api-contract.md` in the same change.

## Rules
- Controllers are thin; business rules live in `app/Services`. Stock balances change ONLY through `StockService`
  (row lock `FOR UPDATE`, immutable `stock_movements`, `balance_after`, audit). Never UPDATE/DELETE movements or audit rows.
- Every write runs inside `Db::transaction()` and calls `Audit::log()` / `Audit::logUpdate()`.
- Validate input with `Validator::validate()` (PT-BR messages). Throw `HttpException` for expected errors;
  error codes are UPPER_SNAKE and documented in the contract.
- JSON envelope: `Response::ok($data, $meta)` / `Response::created()`; lists use `Paginator::run()`.
- Routes in `app/routes.php` with `perm` (and `idempotent => true` for stock/delivery writes); pages in `app/pages.php`.
- New permission = add it to `database/seed.sql` (+ role grants) — test S1 fails otherwise.
- Soft delete (`deleted_at`) + restore + purge (trash only, `Trash::assertUnused`, typed confirmation).
- DB identifiers in English; UI texts/messages in PT-BR. Money DECIMAL(12,2); timestamps via `now()` (Clock).
- White-label: no personal names, e-mails or credits anywhere (code headers, composer.json, HTML meta, PDF/Office metadata).

## Commands
- Dev DB (this machine): MariaDB 10.11 on port 3307, data `C:\Tools\mysqldata-brindes`
  `Start-Process C:\Tools\mariadb-10.11.19-winx64\bin\mariadbd.exe -ArgumentList '--defaults-file=C:\Tools\mysqldata-brindes\my.ini' -WindowStyle Hidden`
- Dev server: `set PHP_BIN=C:\Tools\php82\php.exe && bin\serve.bat` → http://127.0.0.1:8000
- Reinstall with demo data: `php bin/install.php --fresh --demo` (demo password `Demo@123`)
- Tests (must stay green): `php tests/scenarios.php` (uses DB `brindes_test`)
- Lint: `php -l` on changed files; `node --check public/assets/js/api.js`
