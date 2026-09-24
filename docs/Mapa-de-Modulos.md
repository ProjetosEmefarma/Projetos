# Mapa dos módulos — Controle de Brindes

Cada módulo tem **tela** (HTML), **API** (controller) e **regra** (service). As tabelas estão no `schema.sql`.

---

## Visão rápida

| Módulo | Telas (menu) | Controller | Service / classe | Tabelas |
|---|---|---|---|---|
| Login / sessão | `/login`, `/esqueci-senha`, `/redefinir-senha`, `/perfil` | `AuthController` | `LoginThrottle`, `Mailer` | `users`, `login_attempts`, `password_resets` |
| Dashboard | `/dashboard` | `DashboardController` | — | lê estoque, solicitações, alertas |
| Usuários | `/usuarios` | `UsersController` | `Trash` | `users` |
| Perfis e permissões | `/perfis` | `RolesController` | `Auth` | `roles`, `permissions`, `role_permissions` |
| Brindes | `/brindes` | `ItemsController` | `ItemService`, `ImageService` | `items` |
| Estoque | `/estoque`, entrada, saída, ajuste | `StockController` | `StockService`, `ExitOrderService`, `DocumentService` | `stock`, `stock_movements`, `stock_positions`, `stock_exit_orders` |
| Transferência | `/estoque/transferencia` | `TradeController` | `StockService` | `stock_positions`, `stock_movements` |
| Cadastros | `/cadastros/...` | `LookupController` | `LookupRegistry` | `categories`, `departamentos`, `industries`, `locations`, `suppliers` |
| Solicitações internas | `/solicitacoes` | `RequestsController` | `RequestService`, `RequestWorkflow` | `requests`, `request_items`, `request_status_history` |
| Aprovações | `/aprovacoes` | `RequestsController` | `ApprovalEngine` | `approvals` |
| Regras de aprovação | `/regras-aprovacao` | `RulesController` | `ApprovalEngine` | `approval_rules` |
| Separação e entrega interna | `/operacao` | `RequestsController` | `RequestService`, `DeliveryService` | `deliveries` |
| TRADE | `/solicitacoes-trade` | `TradeController` | `TradeService`, `RequestService` | `requests` (`flow=trade`) |
| Recebimento no CD | `/recebimento` | `TradeController` | `TradeService`, `StockService` | `stock_movements` + NF da solicitação |
| Retirada / QR | `/retirada` | `TradeController` | `TradeService`, `QrService`, `ImageService` | `deliveries.signature_png` |
| Eventos / Feirão | `/eventos` | `EventsController` | `EventService` | `events`, `event_allocations` |
| Comprovantes / protocolos | `/protocolos` | `DeliveriesController` | `DeliveryService`, `PdfService` | `deliveries`, `delivery_items` |
| Relatórios | `/relatorios` | `ReportsController` | `ReportService` | leitura de várias tabelas |
| Importar planilha | `/importar` | `ImportController` | `ImportService` | conforme o tipo |
| Notificações | `/notificacoes` | `NotificationsController` | `NotificationService`, `Mailer` | `notifications` |
| Auditoria | `/auditoria` | `AuditController` | `Audit` | `audit_log` |
| Configurações / backup | `/configuracoes` | `SettingsController` | `SettingsService`, `BackupService` | `settings` |
| Saúde | — | `HealthController` | — | — |

O menu lateral está em `app/Support/Menu.php`. O perfil **CD / Estoque** não vê Cadastros, “Nova solicitação”, “Registrar saída” nem “Separação e entregas”. O CD vê **Confirmar saída**.

---

## Detalhe por módulo

### Autenticação
- Classes: `app/Core/Auth.php`, `app/Core/Session.php`, `app/Core/Csrf.php`, `app/Controllers/AuthController.php`
- Senha com `password_hash` (bcrypt). Admin tem todas as permissões no código, além da tabela.
- `session_version`: se o admin troca a senha ou desativa o usuário, a sessão cai.

### Usuários e perfis
- `UsersController` + views `resources/views/pages/users/`
- `RolesController` + `resources/views/pages/roles/index.php`
- Permissões editáveis em **Perfis e permissões** (exceto o efeito “admin vê tudo”).

### Brindes
- CRUD, foto, código `BRD-00001` (`Sequence`)
- `ItemService`, `ImageService` (redimensiona e grava em `storage/uploads/`)

### Estoque
- Saldo atual em `stock` (1 linha por brinde)
- Posição física em `stock_positions` (brinde × local)
- Livro imutável em `stock_movements` (`entrada`, `saida`, `ajuste`, `transferencia`, `estorno`)
- Entrada e **registro de saída (gestor)** aceitam **anexo de NF** (PDF/JPG/PNG) + número da nota
- Gestor: tela `/estoque/saida` (`stock.exit`, `deny_cd`) — formulário **Registrar saída**
- CD: tela `/estoque/confirmar-saida` (`stock.exit_confirm`) — lista + QR, sem formulário de registro

### Solicitações internas e aprovações
- Fluxo: rascunho → solicitada → aguardando aprovação → aprovada → em separação → pronta → finalizada
- Máquina de status: `RequestWorkflow`
- Quem precisa aprovar: `ApprovalEngine` + tabela `approval_rules`

### TRADE (compra → CD → retirada)
- TRADE cria o chamado (`TradeService::store`)
- Gestor aprova / marca compra
- CD dá entrada quando o brinde chega (NF na solicitação)
- Retirada com QR + assinatura (`/retirada`)
- Códigos: `EME-AAAA-NNNNNN` (solicitação) e `PROT-AAAA-NNNN` (comprovante)

### Protocolos / comprovantes
- `DeliveryService` lista com filtro de data
- `PdfService` gera o PDF (título, QR, assinatura)
- Assinatura fica em arquivo **e** em `deliveries.signature_png` (base64), para o PDF não perder a rubrica

### Relatórios
Tipos em `ReportService::TYPES`: estoque atual, entradas, saídas, movimentação, distribuição (brinde / departamento / solicitante / indústria), solicitações, aprovações, estoque mínimo, protocolos, saldo de evento, estoque por indústria, retiradas, relatório do feirão. Exportação Excel/CSV (PhpSpreadsheet).

### Cadastros auxiliares
Um controller só (`LookupController`) para categorias, departamentos, indústrias, locais e fornecedores. Definição dos campos: `LookupRegistry`.

### Infra
- E-mail: `Mailer` (PHPMailer SMTP ou log em `storage/logs/mail.log`)
- Backup SQL: `BackupService` + tela Configurações + `cron/backup.php`
- Restauração: `bin/restore-backup.php` (ver `docs/Backup-e-Restauracao.md`)
- QR: `QrService` + `QrEncoder` (PNG RGB, sem GD obrigatório no PDF)

---

## Arquivos de tela (views)

| URL | Arquivo |
|---|---|
| `/login` | `resources/views/pages/auth/login.php` |
| `/dashboard` | `resources/views/pages/dashboard.php` |
| `/brindes` | `resources/views/pages/items/index.php` |
| `/estoque` | `resources/views/pages/stock/index.php` |
| `/estoque/entrada` `/saida` `/ajuste` | `resources/views/pages/stock/entry.php` etc. + `_form.php` |
| `/estoque/confirmar-saida` | `resources/views/pages/stock/queue.php` |
| `/solicitacoes-trade` | `resources/views/pages/trade/index.php` |
| `/retirada` | `resources/views/pages/trade/scan.php` |
| `/recebimento` | `resources/views/pages/trade/receive.php` |
| `/protocolos` | `resources/views/pages/deliveries/index.php` |
| `/relatorios` | `resources/views/pages/reports/index.php` |
| `/usuarios` | `resources/views/pages/users/index.php` |
| `/configuracoes` | `resources/views/pages/settings/index.php` |

Lista completa: `app/pages.php`.
