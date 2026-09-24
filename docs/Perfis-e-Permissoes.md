# Usuários, perfis e permissões

## Como a pessoa entra

1. Tela `/login` envia e-mail e senha para `POST /api/auth/login`.
2. O sistema compara com `users.password_hash` (`password_verify`).
3. Grava na sessão o id do usuário (`BRINDES_SID`) e um `session_version`.
4. **Em todo request** o `Auth` relê o usuário no banco. Se estiver inativo, excluído, ou se a versão da sessão mudou (senha redefinida), a sessão cai.
5. Limite de tentativas: 5 por e-mail e 30 por IP em 15 minutos (`LoginThrottle` + tabela `login_attempts`).
6. Esqueci senha: token hash em `password_resets`, vale 60 minutos, e-mail se o SMTP estiver configurado.

Admin no primeiro acesso (instalação sem `--admin-email`): `admin@brindes.local` / `Trocar@123`, com troca obrigatória (`must_change_password`).

---

## Perfis de fábrica (`database/seed.sql`)

| Id | Slug | Nome na tela | Papel na operação |
|---|---|---|---|
| 1 | `admin` | Administrador | Tudo: usuários, perfis, configurações, backup, auditoria, cadastros. |
| 2 | `approver` | TRADE / Gestor | Cria e aprova TRADE, consulta estoque, eventos, relatórios, cadastros. |
| 3 | `operations` | CD / Estoque | Recebimento, entrada/saída, transferência, retirada, comprovantes. **Não** cria solicitação e **não** vê Cadastros nem “Separação e entregas”. |
| 4 | `requester` | TRADE | Abre e acompanha as próprias solicitações de compra. |
| 5 | `industry` | Indústria | Só a indústria vinculada em `users.industry_id`. |

O administrador **sempre** passa em `Auth::can()` — não depende de marcar cada permissão na tela, embora o seed já ligue todas.

O recorte extra do CD (esconder menu) está em `Auth::isCdOperations()` + `Menu.php` (`hide_cd`) + páginas com `deny_cd` em `pages.php`. Não basta tirar a permissão: o código barra a URL.

O recorte da indústria é no backend (`Auth::industryId()`): a API só devolve dados daquela indústria.

---

## Catálogo de permissões

Definido em `permissions` (seed). A tela **Perfis e permissões** (`/perfis`) altera `role_permissions`.

| Permissão | O que libera |
|---|---|
| `dashboard.view` | Dashboard |
| `items.view` / `items.manage` / `items.delete` / `items.purge` | Ver / editar / lixeira / apagar de vez brindes |
| `stock.view` | Livro de movimentações e saldos |
| `stock.entry` | Registrar entrada (+ anexo de NF) |
| `stock.exit` | **Registrar saída** (somente gestor/admin) + QR |
| `stock.adjust` | Ajuste / estorno |
| `stock.transfer` | Transferência entre locais |
| `stock.receive` | Recebimento no CD (fluxo TRADE) |
| `stock.exit_confirm` | CD confirma a saída autorizada (QR) |
| `lookups.view` / `manage` / `delete` / `purge` | Cadastros auxiliares |
| `users.view` / `users.manage` | Usuários |
| `roles.manage` | Perfis e permissões |
| `requests.create` | Nova solicitação (interna ou TRADE) |
| `requests.view_own` | Ver as próprias |
| `requests.view_department` | Ver as do departamento |
| `requests.view_all` | Ver todas |
| `requests.approve` | Aprovar / reprovar / marcar compra |
| `requests.process` | Separar / entregar (fluxo interno) |
| `requests.cancel_any` | Cancelar qualquer uma |
| `events.view` / `events.manage` / `events.withdraw` | Eventos e retirada no feirão |
| `deliveries.view` | Comprovantes / PDF / reenvio |
| `rules.manage` | Regras de aprovação |
| `reports.view` / `reports.export` | Relatórios e Excel/CSV |
| `import.run` | Importar planilha |
| `alerts.stock` | E-mail de estoque mínimo |
| `audit.view` | Auditoria |
| `settings.manage` | Configurações, logo, teste de e-mail, download de backup |

### Quem nasce com o quê

- **Gestor:** dashboard, brindes (ver/editar), estoque ver/entrada/**registrar saída**/transferência, cadastros ver/editar, criar e ver todas as solicitações, aprovar, eventos, comprovantes, relatórios.
- **CD / Estoque:** dashboard, brindes ver/editar, estoque ver/entrada/transferência/recebimento, **confirmar saída** (`stock.exit_confirm`), lookups só ver, ver solicitações, eventos ver + retirar, comprovantes, relatórios. **Sem** `requests.create`, **sem** `requests.process` e **sem** registrar saída (`stock.exit`).
- **TRADE (solicitante):** dashboard, ver brindes, ver cadastros, criar e ver as próprias solicitações.
- **Indústria:** dashboard, ver brindes/estoque, ver as próprias solicitações, eventos, comprovantes, relatórios — filtrado pela indústria do usuário.

---

## Onde está no código

| Peça | Arquivo |
|---|---|
| Login, CSRF, “quem sou eu” | `app/Controllers/AuthController.php` |
| Checagem em todo request | `app/Core/Auth.php` (`can`, `isAdmin`, `isCdOperations`) |
| Rotas da API + `perm` | `app/routes.php` |
| Telas + `deny_cd` | `app/pages.php` |
| Itens do menu | `app/Support/Menu.php` |
| Ajuste automático do CD no demo | `app/Support/Installer::restrictCdOperations()` |
| Seed | `database/seed.sql` |

Para mudar o que um perfil vê: Administrador → **Perfis e permissões**. Esconder um item de menu para o CD, na prática, é ajuste de perfil/menu — não é módulo novo.
