# Controle de Brindes

Sistema web para controle de brindes: cadastro, estoque (entrada, saída, ajuste), solicitações, aprovações,
entregas com protocolo digital, eventos, dashboard, relatórios e auditoria.

**Requisitos:** PHP 8.2+ (extensões `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl`, `zip`, `xml`, `dom`),
MySQL 8.0.16+ ou MariaDB 10.4+, Apache (mod_rewrite) ou Nginx. Nenhuma licença paga.

## Run locally (development)
1. Start the database (this machine: MariaDB 10.11 on port 3307):
   `Start-Process C:\Tools\mariadb-10.11.19-winx64\bin\mariadbd.exe -ArgumentList '--defaults-file=C:\Tools\mysqldata-brindes\my.ini' -WindowStyle Hidden`
2. `.env` is already configured for it (copy `.env.example` on other machines).
3. First time / reset with demo data: `C:\Tools\php82\php.exe bin/install.php --fresh --demo`
4. Start the web server: `set PHP_BIN=C:\Tools\php82\php.exe` then `bin\serve.bat` → http://127.0.0.1:8000
5. Demo logins (password `Demo@123`): `admin@brindes.local`, `gestor@brindes.local`, `operacao@brindes.local`, `solicitante@brindes.local`, `industria@brindes.local` (portal da indústria)

## Tests
`php tests/scenarios.php` — business-rule scenarios on a separate database (`brindes_test`). Must end with "0 reprovados".

## Documentation
Índice: `docs/INDICE.md`

- `docs/Manual-do-Usuario.md`
- `docs/Manual-de-Instalacao-e-Migracao.md`
- `docs/Backup-e-Restauracao.md`
- `docs/Arquitetura-e-Pastas.md`
- `docs/Mapa-de-Modulos.md`
- `docs/Perfis-e-Permissoes.md`
- `docs/Dependencias.md`
- `docs/Contas-e-Credenciais.md`
- `docs/Versao-Final.md`
- `docs/Apresentacao-Treinamento.html` (editável: troque `{{NOME_DA_EMPRESA}}`)
- `docs/api-contract.md` — API
- `docs/database.md` e `docs/diagrama-banco.html` — modelo de dados
- `docs/modelos/` — planilhas modelo para importação
- `VERSION` — número desta entrega (1.1.0)

Garantia de correção de erros nas funcionalidades entregues: **30 dias** após a entrega (novas funcionalidades à parte).
