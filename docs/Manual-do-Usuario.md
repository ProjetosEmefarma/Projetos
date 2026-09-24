# Manual do Usuário — Controle de Brindes

Sistema web para cadastro, estoque, solicitações TRADE, recebimento no CD, retiradas com QR Code, comprovantes digitais e eventos.

Acesse pelo navegador (computador ou celular). No primeiro login com a senha provisória, o sistema pede uma senha nova (mínimo 8 caracteres, com letras e números).

---

## Perfis

| Perfil | O que faz |
|---|---|
| **Administrador** | Tudo: usuários, cadastros, ajustes, regras, configurações, auditoria |
| **TRADE / Gestor** | Cria solicitações TRADE, cadastros, aprovações, estoque, retiradas, relatórios |
| **CD / Estoque** | Recebimento no CD, entrada, **confirmar saída** (QR), transferência, retirada por QR e comprovantes. **Não** cria solicitação TRADE, **não** registra saída, **não** acessa Cadastros e **não** usa Separação e entregas |
| **TRADE** | Cria e acompanha solicitações de compra de brindes; vê cadastros |
| **Indústria** | Vê somente as informações da própria indústria parceira |

Cada pessoa vê só o menu do seu perfil.

---

## Como atualizar ou excluir informações

Você tem autonomia total no perfil Administrador.

- **Editar:** abra o registro e altere os campos. A alteração fica no histórico.
- **Inativar:** o item/cadastro some das listas de uso, mas o histórico permanece.
- **Excluir:** envia para a **lixeira**. Continua nos relatórios e na auditoria.
- **Restaurar:** na lixeira, recupera o registro.
- **Excluir definitivamente:** só na lixeira, só se o registro **nunca** foi usado. O sistema pede para **digitar o código/nome/e-mail** para confirmar.

Movimentações de estoque, protocolos e auditoria **não podem ser apagados**. Erro de estoque se corrige com um **ajuste** (motivo obrigatório).

---

## Dia a dia

### 1. Cadastrar brinde
**Brindes → Novo brinde.** Código automático (`BRD-00001`) ou o seu. Informe categoria, estoque mínimo, local (ex.: CD), valor e, se quiser, quantidade inicial. Cadastros (indústrias, categorias, etc.) ficam com **gestor e TRADE**.

### 2. Entrada / saída / ajuste
**Registrar entrada:** quantidade, indústria, nº do chamado, **número da NF** e **anexo da nota** (PDF, JPG ou PNG).

**Registrar saída (somente o gestor):** no menu aparece **Registrar saída** (também no grupo TRADE, no dashboard e em Solicitações TRADE).  
1. Digite o código ou o nome do brinde e toque nele na lista.  
2. Informe **quantidade** e **finalidade** (obrigatórios). Destinatário e NF são opcionais.  
3. Clique em **Registrar saída**. O sistema gera o QR (`SAI-2026-0001`) e reserva o estoque.  
O CD **não** vê este formulário.

**Confirmar saída (somente o CD):** menu **Confirmar saída**. Já entram as saídas que o gestor registrou, com QR. O CD escaneia ou clica **Confirmar saída** — aí o estoque baixa.

**Ajuste** (administrador): use **Contagem** na visita mensal ao CD — informe a quantidade contada; o sistema calcula a diferença.

O sistema **não deixa estoque negativo** e **não duplica** um clique duplo no Salvar.

A tela **Separação e entregas** não aparece para o perfil CD (evita função duplicada).

### 3. Solicitar (TRADE e gestor)
**Solicitações TRADE → Nova solicitação.** O perfil CD **não** vê esse botão.  
Na mesma tela o gestor também tem **Registrar saída de brindes** (saída de estoque, diferente da solicitação de compra).  
Nas solicitações internas: **Solicitações → Nova.**

### 4. Aprovar
Se a regra exigir (valor, quantidade etc.), a solicitação vai para **Aguardando aprovação**. O gestor informa o **número do chamado** ao aprovar, ou reprova com justificativa.

### 5. Comprovante de retirada
Quem retira **assina na tela**. O sistema gera o **PDF** (com QR, quantidade, saldo e assinatura) e envia e-mail.  
Em **Comprovantes**, filtre por texto e por **data (De / Até)**.

---

## Eventos (volume alto)

1. **Eventos →** criar o evento (gestor).  
2. Definir **cotas por indústria × brinde**.  
3. **Abrir evento** (reserva o estoque).  
4. No CD/evento, abrir **Modo Evento** no celular: indústria → quantidades → assinatura → Confirmar.  
5. **Encerrar evento** libera o que não foi retirado.

Se a cota da indústria acabar, a retirada é bloqueada. Falha de e-mail **não trava** a retirada.

---

## Fluxo TRADE (compra → CD → retirada)

O saldo **nunca se edita**. Ele é a soma das movimentações (entradas − saídas). Transferência para evento **não é retirada**.

1. **TRADE ou gestor** abre **Solicitações TRADE → Nova solicitação** com indústria, brinde, quantidade e finalidade. O sistema gera o número `EME-AAAA-000001` e o **QR Code** (visível na ficha da solicitação). **Não** preenche chamado nem NF nesta etapa.
2. **Gestor** abre a solicitação. Se aprovar, informa o **número do chamado**. Se não aprovar, reprova com o motivo. O CD só recebe depois da aprovação.
3. Quando o brinde **chegar no CD**, operação anexa a **NF** (PDF ou imagem) e confirma as quantidades. Isso **entra no estoque** automaticamente.
4. **Estoque por indústria** mostra Recebido / Retirado / Saldo.
5. **Retirada / QR Code:** aponte a câmera, envie uma foto do QR ou digite o código e clique em **Identificar**. O QR da solicitação aparece na tela. Informe quem retira, a quantidade e **assine**. O estoque baixa na hora e o comprovante (PDF + e-mail) é gerado.
6. **Transferência** (CD → Feirão ou escritório): o total não muda; só muda o local.
7. No evento, use **Modo Evento** no tablet/celular.
8. Correção de erro: **Ajuste / estorno** — a movimentação original **não é apagada**.

Não é possível retirar mais do que o saldo nem finalizar sem identificar quem retirou.

---

## Dashboard e relatórios

A tela inicial mostra estoque, pendências, itens abaixo do mínimo, consumo e movimentações.  
**Relatórios** exportam Excel/CSV (abre certo no Excel em português), inclusive coluna de indústria.  
**Importar planilha** usa os modelos oficiais (itens e solicitações).

---

## Configurações (marca da empresa)

Em **Configurações**: nome da empresa, **cor**, logo. Isso altera login, menu e PDF.

---

## Dicas no celular (CD)

Use Chrome ou Safari. Menu pelo ícone ☰. Telas de entrada/saída, retirada e Modo Evento foram feitas para uma mão. Se a internet oscilar, não clique de novo no Confirmar — o sistema reconhece o mesmo envio.
