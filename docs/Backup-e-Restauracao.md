# Backup e restauração

O backup do **banco** é um arquivo `.sql`. Fotos de brindes, NF anexadas, assinaturas e PDFs ficam em `storage/` e precisam ser copiados junto, senão o sistema volta sem as imagens.

Este procedimento foi testado em 18/09/2026 (dump → banco vazio → conferência de tabelas e login admin). Relatório em `docs/evidencias/restore-teste.txt` no pacote de entrega.

---

## 1. Gerar o backup (enquanto o sistema está de pé)

### Pelo sistema (recomendado no dia a dia)

1. Entre como **Administrador**.
2. Abra **Configurações**.
3. Baixe o backup SQL (botão de backup).
4. Guarde o arquivo em local seguro (não no mesmo servidor, se possível).
5. No mesmo dia, copie a pasta `storage/` (FTP/SFTP): pelo menos `storage/uploads`, `storage/signatures`, `storage/pdf`.

### Pelo agendamento (automático)

Cron diário (já descrito no Manual de Instalação):

```
10 2 * * * php /caminho/do/projeto/cron/backup.php
```

Os arquivos ficam em `storage/backups/backup-AAAAMMDD-HHMMSS.sql`. O sistema guarda os **14** mais recentes.

### Pelo servidor (mysqldump)

```
mysqldump --single-transaction --routines --default-character-set=utf8mb4 -u USUARIO -p NOME_DO_BANCO > backup-20260918.sql
```

---

## 2. Restaurar depois de uma perda

Há dois casos. O passo a passo abaixo assume **produção MySQL/MariaDB** (servidor da empresa).

### A. O servidor e os arquivos PHP ainda existem (só o banco corrompeu)

1. Avise o time: ninguém movimenta estoque.
2. Se o site ainda abre, baixe **um último backup** (Configurações) — pode estar mais novo que o arquivo da véspera.
3. No painel (phpMyAdmin) **ou** via SSH, importe o `.sql` no banco que o `.env` aponta (`DB_NAME`).
4. Pela linha de comando (mesmo servidor do PHP):

```
php bin/restore-backup.php /caminho/storage/backups/backup-AAAAMMDD-HHMMSS.sql
```

O script usa o `.env` atual. Ele **substitui as tabelas** (o dump da aplicação já vem com `DROP TABLE`).

5. Confira:
   - login do administrador
   - um brinde com foto
   - um comprovante PDF
   - uma entrada recente no livro de movimentações
6. Recoloque o cron se tiver sido parado.

### B. Servidor novo / pasta apagada (perda total)

1. Instale PHP 8.2 + MySQL como no **Manual de Instalação**.
2. Extraia o zip da entrega (código + `vendor/`).
3. Copie `.env.example` → `.env` e preencha banco, URL, `APP_KEY` **o mesmo de antes** (senão sessões e hashes de protocolo mudam de comportamento).
4. Crie o banco vazio no painel.
5. Restaure o `.sql` (phpMyAdmin ou `php bin/restore-backup.php backup.sql`).
   - **Não** rode `schema.sql` + `seed.sql` depois do dump: o dump já traz estrutura **e** dados.
6. Copie de volta `storage/uploads`, `storage/signatures`, `storage/pdf` (e `storage/backups` se quiser o histórico).
7. `chmod -R 775 storage` (Linux).
8. Aponte o document root para `public/`, SSL, cron.
9. Teste login, uma entrada e um PDF.

Se **não houver backup SQL** e só o zip da entrega: instale do zero (`schema.sql` + `seed.sql`) e recadastre. Os movimentos antigos não voltam.

### phpMyAdmin (sem SSH)

1. Abra o banco.
2. Aba **Importar**.
3. Escolha o `.sql`.
4. Execute.
5. Se der timeout em arquivo grande: suba via SSH ou aumente `max_execution_time` / importe pelo cliente MySQL.

---

## 3. O que o restore **não** traz sozinho

| Item | Onde está | Como recuperar |
|---|---|---|
| Tabelas e dados | arquivo `.sql` | `restore-backup.php` ou phpMyAdmin |
| Fotos, NF, PDF, assinatura em disco | `storage/` | copiar a pasta |
| Assinatura no comprovante | também em `deliveries.signature_png` | volta com o SQL; o PDF é gerado de novo na abertura |
| E-mail SMTP | `.env` | preencher de novo se o `.env` foi perdido |
| DNS / SSL / cron | painel da hospedagem | refazer no painel |

---

## 4. Teste de sanidade após restaurar

```
php -r "require 'app/bootstrap.php'; echo count(App\Core\Db::query('SHOW TABLES')->fetchAll()) . ' tabelas' . PHP_EOL;"
```

Ou abra `/api/health` (sem login) e em seguida faça login no navegador.

Checklist mínimo:

- [ ] Login admin
- [ ] Lista de brindes
- [ ] Livro de movimentações com histórico
- [ ] Abrir um protocolo PDF
- [ ] Configurações (nome da empresa / logo)

---

## 5. Boas práticas

- Backup automático **e** uma cópia fora do servidor (OneDrive da empresa, por exemplo — OneDrive não hospeda o site, mas guarda o `.sql`).
- Trate o `.sql` como dado pessoal (LGPD): nomes, e-mails, assinatura.
- Depois de restaurar, não rode `php bin/install.php --fresh` — isso apaga o banco e põe o seed inicial.
