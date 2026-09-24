# Dependências — Controle de Brindes v1.1.0

Nenhuma licença paga. Tudo abaixo já vai **dentro do zip** (`vendor/` no PHP e `public/assets/vendor/` no navegador). Produção **não precisa** de `composer install` nem `npm`.

Versões travadas em `composer.lock` na data da entrega (2026-09-18).

---

## Runtime

| Item | Versão |
|---|---|
| PHP | 8.2 ou 8.3 (`composer.json`: `>=8.2`) |
| Extensões | `pdo`, `pdo_mysql`, `mbstring`, `json`, `gd`, `fileinfo`, `openssl`, `zip`, `xml`, `dom` (`intl` recomendado) |
| Banco produção | MySQL 8.0.16+ **ou** MariaDB 10.4+ (InnoDB, utf8mb4) |
| Web | Apache `mod_rewrite` ou Nginx; HTTPS |

---

## Pacotes PHP (`composer.json` / `composer.lock`)

Diretos:

| Pacote | Versão no lock | Uso |
|---|---|---|
| `dompdf/dompdf` | 3.1.6 | PDF do comprovante |
| `phpoffice/phpspreadsheet` | 2.4.7 | Relatórios Excel e importação |
| `phpmailer/phpmailer` | 6.12.0 | SMTP |
| `ifsnop/mysqldump-php` | 2.13 | Backup SQL |

Transitivos (vêm junto, não se instala à mão):

| Pacote | Versão |
|---|---|
| `dompdf/php-font-lib` | 1.0.2 |
| `dompdf/php-svg-lib` | 1.0.2 |
| `sabberworm/php-css-parser` | 9.4.0 |
| `masterminds/html5` | 2.11.0 |
| `maennchen/zipstream-php` | 3.1.2 |
| `markbaker/complex` | 3.0.2 |
| `markbaker/matrix` | 3.0.1 |
| `psr/simple-cache` | 3.0.0 |
| `composer/pcre` | 3.4.0 |
| `thecodingmachine/safe` | 3.4.0 |

Autoload PSR-4: namespace `App\` → pasta `app/`.

---

## Bibliotecas do navegador (arquivos locais)

Não há `package.json`. Os arquivos estão em `public/assets/vendor/`.

| Biblioteca | Versão | Arquivo |
|---|---|---|
| Bootstrap | 5.3.3 | `bootstrap/bootstrap.min.css` + `bootstrap.bundle.min.js` |
| Bootstrap Icons | (CSS local) | `bootstrap-icons/bootstrap-icons.min.css` |
| Alpine.js | 3.x | `alpinejs/alpine.min.js` |
| Chart.js | 4.4.7 | `chartjs/chart.umd.min.js` |
| Signature Pad | 5.0.4 | `signature_pad/signature_pad.umd.min.js` |
| jsQR | UMD local | `jsqr/jsQR.js` |

JS próprio do sistema: `public/assets/js/app.js` e `api.js`.

---

## Demo na Vercel (só teste, não é produção)

A demo pública usa `vercel-php@0.6.2` e SQLite em `/tmp`. Isso **não** entra no servidor da empresa. Produção = PHP + MySQL no painel da empresa (Hostinger ou equivalente).
