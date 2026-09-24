# Manual de Instalação e Migração — Controle de Brindes

Destinado à equipe de TI. PHP + MySQL/MariaDB, sem licenças pagas. Código-fonte e banco são entregues por completo; a empresa pode hospedar e migrar sem depender do desenvolvedor.

---

## Requisitos do servidor

| Item | Mínimo |
|---|---|
| PHP | 8.2+ (8.2 / 8.3) |
| Extensões | `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl`, `zip`, `xml`, `dom`, `intl` (recomendado) |
| Banco | MySQL 8.0.16+ **ou** MariaDB 10.4+ (InnoDB, utf8mb4) |
| Web | Apache (`mod_rewrite`) ou Nginx; HTTPS em produção |
| Cron | 1 tarefa a cada minuto (e-mails) e 1 diária (alertas + backup) |
| Disco | pasta `storage/` gravável (fotos, assinaturas, PDF, logs, backups) |

Custo de hospedagem típico: **US$ 3 a 5/mês** (Hostinger / Valebyte compartilhado). VPS (Hetzner, DigitalOcean) se crescer. OneDrive **não** hospeda sistema web.

Painel recomendado (conta no **nome do cliente**): Hostinger com PHP 8.2, MySQL, SSL, correio SMTP.

---

## Arquivos

O zip de entrega contém o projeto (incluindo `vendor/`). **Não é necessário Composer no servidor.**

Document root deve apontar para a pasta `public/`.  
Se o painel só publica a raiz do projeto, o `.htaccess` da raiz já redireciona para `public/` e bloqueia `app/`, `storage/`, `.env`.

---

## Instalação (primeira vez)

1. Envie os arquivos por FTP/SFTP ou extraia o zip.
2. Copie `.env.example` para `.env` e preencha:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://brindes.suaempresa.com.br
APP_KEY=   # php -r "echo bin2hex(random_bytes(32));"
APP_TIMEZONE=America/Sao_Paulo

DB_HOST=localhost
DB_PORT=3306
DB_NAME=...
DB_USER=...
DB_PASS=...

MAIL_DRIVER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=brindes@suaempresa.com.br
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=brindes@suaempresa.com.br
```

3. Crie o banco vazio no painel (charset `utf8mb4_unicode_ci`).
4. Importe **nessa ordem**: `database/schema.sql` e depois `database/seed.sql` (phpMyAdmin)  
   **ou**, com SSH: `php bin/install.php --admin-email=seu@email --admin-password='SenhaForte1' --admin-name='Seu Nome'`  
   Se o banco já existia antes do módulo TRADE: `php bin/install.php` aplica `database/migrate_trade.sql` automaticamente.
5. Permissões: `chmod -R 775 storage` (Linux).
6. SSL no domínio. Cookie de sessão já é `HttpOnly; SameSite=Lax` e `Secure` quando há HTTPS.
7. Primeiro acesso: `admin@brindes.local` / `Trocar@123` **se não usou --admin-email**. Troque a senha na hora.

**Não rode** `php bin/install.php --fresh` nem `--demo` em produção.

---

## Cron

```
* * * * * php /caminho/do/projeto/cron/send_notifications.php
5 2 * * * php /caminho/do/projeto/cron/daily_alerts.php
10 2 * * * php /caminho/do/projeto/cron/backup.php
```

- `send_notifications.php` — fila de e-mail (protocolos, aprovações). Falha de SMTP **não** impede retirada no evento.
- `daily_alerts.php` — estoque mínimo/zerado, solicitações paradas, lembrete de aprovação.
- `backup.php` — dump em `storage/backups/` (guarda 14 arquivos). O administrador também baixa backup em **Configurações**.

Restauração (perda do banco): `docs/Backup-e-Restauracao.md` e `php bin/restore-backup.php CAMINHO/backup.sql`. Fotos e PDFs estão em `storage/` — copie essa pasta junto com o `.sql`.

---

## Nginx (exemplo)

```
root /var/www/brindes/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
location ^~ /storage { deny all; }
```

## Apache

`public/.htaccess` já envia tudo que não for arquivo real para `index.php`.

## IIS / Azure App Service

Document root = `public`. Handler PHP 8.2. Importe o `.sql`. Variáveis do `.env` podem ir para Application Settings. Pasta `storage` com permissão de escrita para o pool.

---

## Migração para o servidor da empresa

1. Coloque o site em manutenção (ninguém movimenta estoque).
2. Copie **todos** os arquivos (incluindo `storage/` com fotos, assinaturas e PDFs).
3. Exporte o banco (`mysqldump --single-transaction --routines brindes`) ou use o backup da aplicação.
4. No destino: crie o banco, importe o dump, ajuste `.env` (URL, DB, SMTP, `APP_KEY` **o mesmo**).
5. Aponte o DNS / vhost. Teste login, uma entrada e um PDF de protocolo.
6. Recrie as linhas de cron.

Não é necessário reconstruir o sistema: são arquivos PHP + um banco MySQL.

---

## LGPD / segurança

Dados de pessoas (nome, e-mail, documento, **assinatura**) ficam no servidor da hospedagem. Use HTTPS, acesso por perfil, e não publique `storage/` nem `.env`. Backups são arquivos SQL — trate-os como informação confidencial.

---

## Garantia

30 dias após a entrega: correção de erros nas funcionalidades já entregues. Novas funções (SSO Microsoft, ERP, offline, etc.) são orçadas à parte.
