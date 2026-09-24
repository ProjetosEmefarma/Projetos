# Diagrama e tabelas do banco

MySQL 8.0.16+ / MariaDB 10.4+ · InnoDB · utf8mb4 · tabelas definidas em `database/schema.sql`.

Arquivos:

1. `database/schema.sql` — cria as tabelas (banco **vazio**)
2. `database/seed.sql` — perfis, permissões, admin, categoria/local, configurações
3. `database/migrate_trade.sql` — **só** se o banco já existia antes do módulo TRADE. Instalação nova ignora este arquivo (`schema.sql` já está completo)

Diagrama visual (abrir no navegador): `docs/diagrama-banco.html`

---

## Princípios

- **Estoque é livro.** `stock` tem o saldo atual; `stock_movements` nunca é editado nem apagado (`qty` com sinal, `balance_after`). Invariante: `stock.qty_on_hand = SUM(stock_movements.qty)`.
- **Nunca negativo.** O próprio banco recusa saldo inválido (`CHECK`).
- **available = qty_on_hand − qty_reserved.**
- Cadastros usam `deleted_at` (lixeira). Apagar de vez só se nada referencia o registro.
- `audit_log` e `stock_movements` são somente inclusão.
- `idempotency_keys` evita duplicar entrada/saída no duplo clique.
- Horário: `DATETIME` em América/São_Paulo.

---

## Relacionamentos (visão)

```
roles ──< role_permissions >── permissions
roles ──< users >── departments
users ──< users.industry_id >── industries

categories / locations / suppliers ──< items >── stock          (1:1 saldo)
items ──< stock_positions >── locations                         (saldo por local)
items ──< stock_movements                                       (livro)
users ──< stock_movements (quem movimentou)
industries / departments / requests / deliveries / events ── stock_movements (origem)

users ──< requests (solicitante)
requests ──< request_items >── items
requests ──< request_status_history
approval_rules ──< approvals >── requests

events ──< event_allocations >── industries, items
requests / events ──< deliveries ──< delivery_items >── items
deliveries ── stock_movements (baixa)

users ──< notifications
```

---

## Tabelas

| Grupo | Tabela | Função |
|---|---|---|
| Acesso | `roles`, `permissions`, `role_permissions` | 5 perfis, 36 permissões |
| | `users` | login, perfil, departamento, indústria, `must_change_password`, `session_version` |
| | `login_attempts`, `password_resets` | bloqueio de força bruta, token de senha |
| Cadastros | `categories`, `departments`, `locations`, `suppliers`, `industries` | auxiliares (`locations.kind`: cd / evento / outro) |
| Brindes | `items` | código único, categoria, local, fornecedor, foto, `kind` (físico/voucher/cartão) |
| Estoque | `stock` | 1 saldo por brinde |
| | `stock_positions` | quantidade por local |
| | `stock_movements` | livro: entrada/saída/ajuste/transferência/estorno + `attachment_path` (NF) |
| Solicitações | `requests` | interna (`flow=interna`) ou TRADE (`flow=trade`); códigos SOL / EME |
| | `request_items`, `request_status_history` | linhas e histórico |
| | `approval_rules`, `approvals` | quem precisa assinar |
| Eventos | `events`, `event_allocations` | cota por indústria × brinde |
| Comprovantes | `deliveries`, `delivery_items` | protocolo PROT, `signature_png`, PDF, hash |
| Comunicação | `notifications` | inbox + fila de e-mail |
| Técnico | `audit_log`, `settings`, `idempotency_keys`, `sequences` | auditoria, branding, anti-duplicidade, numeração |

---

## Instalação do banco

- Com SSH: `php bin/install.php` (teste: `--demo`; produção: **sem** `--fresh`).
- Sem SSH: phpMyAdmin → `schema.sql` e em seguida `seed.sql`.
- Primeiro login se não passou `--admin-email`: `admin@brindes.local` / `Trocar@123`.
