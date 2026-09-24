# Versão final entregue — Controle de Brindes

Este arquivo diz **qual pacote é o sistema desta entrega**, para não misturar zip antigo com o que está na demonstração.

| Campo | Valor |
|---|---|
| Produto | Controle de Brindes |
| Versão | **1.1.3** |
| Data | **21/09/2026** |
| Arquivo do pacote | `CONTROLE-BRINDES-v1.1.3-ENTREGA-FINAL.zip` |
| Identidade no código | arquivo `VERSION` na raiz do projeto |
| Demonstração (temporária) | https://controle-brindes-three.vercel.app |
| Produção da empresa | ainda não — sobe no painel de vocês (PHP + MySQL) |

A demo e este zip são a **mesma linha de código** da versão 1.1.3.

**Registrar saída de brindes** aparece **somente no painel do gestor** (menu TRADE, menu Estoque, dashboard e Solicitações TRADE). O CD **não** registra: só confirma no menu **Confirmar saída** (QR).

Não instalem zips anteriores (`v1.0`, `v1.1.0`, `v1.1.1`, `v1.1.2`). Usem **só o v1.1.3**.

---

## O que vai no zip

- Código-fonte completo, **incluindo `vendor/`**
- `database/schema.sql`, `seed.sql`, `migrate_trade.sql` (inclui `stock_exit_orders`)
- `docs/` — manuais, arquitetura, módulos, permissões, dependências, backup/restauração, contas, diagrama do banco
- `git/controle-brindes.bundle` — histórico Git desta entrega
- Este arquivo e o `LEIA-ME`

Não vai no zip (de propósito):

- `.env` (segredos)
- `storage/` com arquivos de uso (uploads reais)
- conta Vercel / tokens de demo

---

## Conferência depois de abrir o zip

1. Abrir `VERSION` — deve dizer `1.1.3` e data `2026-09-21`.
2. Conferir SHA-256 do zip com o arquivo `SHA256.txt` ao lado do zip.
3. No servidor: copiar `.env.example` → `.env`, importar banco, apontar `public/`.

---

## Git

```
git log -1 --oneline
```

A mensagem de entrega cita `v1.1.3 2026-09-21`.
