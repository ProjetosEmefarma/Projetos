# Contas, credenciais e propriedade

Objetivo: a **empresa** administra o sistema sozinha. Nada crítico deve ficar só na conta pessoal do desenvolvedor.

---

## 1. O que já é de vocês neste pacote

| Item | Onde está | Quem controla |
|---|---|---|
| Código-fonte + `vendor/` | zip de entrega | empresa (pode hospedar, copiar, contratar outro TI) |
| Modelo do banco | `database/schema.sql`, `seed.sql`, `migrate_trade.sql` | empresa |
| Documentação | pasta `docs/` | empresa |
| Usuário admin da **instalação de vocês** | criado no servidor da empresa | empresa (trocar a senha no 1º acesso) |

O zip **não** traz `.env` de produção (não deve viajar por e-mail). Vocês preenchem no servidor.

---

## 2. Demonstração atual (temporária)

Enquanto a hospedagem da empresa não está no ar, o teste público é:

- Endereço: `https://controle-brindes-three.vercel.app`
- Senha dos logins de demo: `Demo@123`
  - `admin@brindes.local` — Administrador
  - `gestor@brindes.local` — TRADE / Gestor
  - `operacao@brindes.local` — CD / Estoque
  - `solicitante@brindes.local` — TRADE
  - `industria@brindes.local` — Indústria

Essa demo **não** é o servidor de produção. Roda em conta técnica do desenvolvimento, com SQLite, sem SMTP real. Quando o site da empresa estiver no Hostinger (ou equivalente) **no nome da empresa**, a demo pode ser desligada.

---

## 3. Contas que a empresa deve abrir (produção)

Tudo abaixo em **CNPJ / e-mail corporativo**, não no e-mail pessoal do freelancer.

| Serviço | Para quê | Quem cria | O que me enviar depois (se quiserem que eu configure) |
|---|---|---|---|
| Hospedagem PHP 8.2 + MySQL + SSL | site no ar | TI / compras da empresa | painel **ou** FTP + nome do banco |
| Domínio / DNS | `brindes.suaempresa.com.br` (exemplo) | quem já tem o domínio | apontar A/CNAME para a hospedagem |
| Banco MySQL | dados | criado no painel da hospedagem | host, nome, usuário, senha — só no `.env` do servidor |
| Caixa SMTP | comprovante por e-mail | e-mail da empresa (`brindes@...`) | host, porta, usuário, senha, TLS/SSL |
| Repositório Git da empresa | histórico do código | GitHub/GitLab **da empresa** | convite de escrita para eu enviar o histórico |

**Não usem** a conta Vercel da demo como produção. Produção = PHP + MySQL no painel de vocês (o Manual de Instalação já indica Hostinger ~ US$ 3–5/mês).

SMTP: o sistema já envia comprovante quando o SMTP está no `.env`. Sem credenciais da empresa, o e-mail só registra em log (como na demo).

---

## 4. Primeiro acesso no servidor de vocês

Se instalarem com `schema.sql` + `seed.sql` (sem `--admin-email`):

- E-mail: `admin@brindes.local`
- Senha: `Trocar@123`
- O sistema pede troca na hora.

Em seguida cadastrem os usuários reais (gestor, CD, TRADE) em **Usuários**. Desativem o admin genérico ou troquem o e-mail para um corporativo.

Se usarem `php bin/install.php --admin-email=ti@empresa --admin-password='...'`, esse já é o admin de vocês.

`APP_KEY` no `.env`: gerem uma vez e **guardem**. Restauração de backup deve reutilizar a mesma chave.

```
php -r "echo bin2hex(random_bytes(32));"
```

---

## 5. Git

Neste pacote vai um **bundle Git** (`git/controle-brindes.bundle`) com o histórico disponível nesta entrega.

Para a empresa ficar dona do repositório:

1. Criar um repositório **privado** na conta GitHub/GitLab da empresa.
2. Na máquina da TI:

```
git clone controle-brindes.bundle controle-brindes
cd controle-brindes
git remote add origin git@github.com:EMPRESA/controle-brindes.git
git push -u origin --all
```

3. Convidar quem for manter o sistema.

O `vendor/` pode não estar no Git (é grande e se reconstroi com `composer install` se um dia precisarem). **No zip de produção o `vendor/` já vai incluído**, como combinado.

---

## 6. Checklist de independência

- [ ] Hospedagem no nome da empresa
- [ ] Domínio apontando para essa hospedagem
- [ ] `.env` só no servidor, backup da `APP_KEY` com a TI
- [ ] SMTP da empresa preenchido
- [ ] Admin com e-mail corporativo e senha trocada
- [ ] Backup SQL saindo do cron + cópia fora do servidor
- [ ] Git privado da empresa com o bundle importado
- [ ] Demo Vercel pode ser encerrada

Quando tiverem o painel da hospedagem, é só enviar o acesso de TI (ou um usuário limitado) que eu subo a versão final **na conta de vocês**.
