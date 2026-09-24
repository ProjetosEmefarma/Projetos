# Arquitetura e estrutura de pastas — Controle de Brindes

## O que é o sistema

PHP **puro** (8.2+), **sem Laravel, sem Symfony, sem WordPress**.
Há um MVC pequeno, escrito para este projeto:

- **Rotas** em `app/routes.php` (API JSON) e `app/pages.php` (telas HTML)
- **Controllers** em `app/Controllers/`
- **Regras de negócio** em `app/Services/`
- **Telas** em `resources/views/`
- **Banco** MySQL/MariaDB via PDO (`app/Core/Db.php`)

O front das telas usa Bootstrap 5, Alpine.js e JavaScript próprio (`public/assets/js/`). Cada página HTML é um “casco”: os dados vêm da API `/api/...`.

Não há licença paga. O código-fonte completo (incluindo `vendor/`) vai no zip de entrega. **Não é necessário Composer no servidor de produção.**

A demonstração na Vercel usa SQLite só porque a Vercel não tem MySQL. **Produção da empresa = PHP + MySQL/MariaDB**, como no Manual de Instalação.

---

## Como uma requisição anda

1. O servidor aponta o document root para `public/`.
2. `public/index.php` carrega `app/bootstrap.php` (`.env`, config, timezone).
3. `App\Core\App` decide:
   - caminho `/api/...` → `Router` + controller → JSON
   - outro caminho → view em `resources/views/` + menu filtrado por permissão
4. Sessão em cookie `BRINDES_SID` (HttpOnly, SameSite=Lax, Secure no HTTPS).
5. CSRF em todo POST/PUT/DELETE da API (exceto login). Movimentações de estoque também usam `Idempotency-Key` para não gravar duas vezes no duplo clique.

```
Navegador  →  public/index.php  →  App
                                   ├─ /api/*   Controllers → Services → Db (MySQL)
                                   └─ páginas  View + Alpine.js → chama /api/*
```

---

## Pastas principais

| Pasta / arquivo | Para que serve |
|---|---|
| `public/` | Única pasta pública. `index.php` é a entrada. CSS/JS/imagens em `public/assets/`. |
| `public/.htaccess` | Apache: tudo que não for arquivo real vai para `index.php`. |
| `app/bootstrap.php` | Liga o autoload, lê `.env`, configura o app. |
| `app/Core/` | Núcleo: App, Router, Auth, Db, Session, Csrf, Validator, View. |
| `app/Controllers/` | Recebem HTTP, checam permissão, chamam o serviço, devolvem JSON. |
| `app/Services/` | Regras: estoque, TRADE, solicitações, PDF, e-mail, backup. |
| `app/Support/` | Apoio: menu, instalador, QR, lookups, seeder de demo. |
| `app/routes.php` | Contrato da API. |
| `app/pages.php` | Mapa URL → tela HTML + permissão da página. |
| `app/helpers.php` | Funções globais (`url()`, `e()`, `now()`, `can()`). |
| `config/config.php` | Lê `.env` e monta o array de configuração. |
| `.env` | Segredos (banco, SMTP, APP_KEY). **Nunca publicar.** Copiar de `.env.example`. |
| `resources/views/` | HTML das telas e layouts (`app.php`, `auth.php`, `kiosk.php`). |
| `database/schema.sql` | Criação das tabelas (banco vazio). |
| `database/seed.sql` | Perfis, permissões, admin inicial, categoria/local padrão. |
| `database/migrate_trade.sql` | Upgrade de instalações antigas (antes do módulo TRADE). Instalação nova **não** precisa deste arquivo. |
| `storage/` | Uploads, assinaturas, PDFs, logs, backups. Tem de ser gravável. Não é pública. |
| `cron/` | Tarefas: e-mail, alertas, backup. |
| `bin/install.php` | Instalação/reset via linha de comando. |
| `bin/restore-backup.php` | Restaura um dump SQL no banco configurado no `.env`. |
| `vendor/` | Bibliotecas PHP (Dompdf, PhpSpreadsheet, PHPMailer, Mysqldump). Já vem no zip. |
| `tests/` | Cenários de regra de negócio (`php tests/scenarios.php`). |
| `docs/` | Manuais e documentação técnica. |
| `VERSION` | Número e data desta entrega. |

Arquivos na raiz que o Apache/Nginx **não** devem servir: `app/`, `config/`, `storage/`, `.env`, `vendor/` (exceto se alguém apontar o document root errado — o `.htaccess` da raiz já bloqueia).

---

## Front-end

Não é um SPA compilado (não há npm/build obrigatório).

- Layout e componentes visuais: Bootstrap 5.3.3 + Bootstrap Icons
- Interatividade nas telas: Alpine.js
- Gráficos do dashboard/relatórios: Chart.js 4.4.7
- Assinatura no comprovante: Signature Pad 5.0.4
- Leitura de QR no celular: jsQR
- JS próprio: `public/assets/js/app.js` (toasts, pad de assinatura) e `api.js` (chamadas HTTP + CSRF)

---

## O que não existe neste código

- Framework PHP de mercado
- Banco embutido em produção (SQLite só na demo Vercel)
- Dependência de conta do desenvolvedor depois que a empresa hospeda o zip + MySQL no painel dela
