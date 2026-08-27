# Prompt — Frontend: Cadastro Manual de Compra

Use este prompt no repositório do frontend para implementar a tela/modal **Nova Compra**, a visualização depois de salvar, **anexos**, **conciliação manual** e **histórico**.

Copie o arquivo inteiro para o chat do front. A API já existe. Não invente módulo `compras` nem entidade `Compra`.

Prompts relacionados (não substituir; complementar):

- Formulário, lookups, parcelamento, final do cartão, origem, responsável: [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
- Detalhe somente leitura (base): [`frontend-prompt-visualizacao-compra.md`](frontend-prompt-visualizacao-compra.md)
- Cadastro rápido categoria/subcategoria: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md)
- Cadastro rápido de cartão: [`frontend-prompt-cadastro-rapido-cartao.md`](frontend-prompt-cadastro-rapido-cartao.md)
- Assinatura (`eh_assinatura`): [`frontend-prompt-assinaturas.md`](frontend-prompt-assinaturas.md)
- Cartão (grupo → bandeira → número): [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md)
- Fatura (ciclo, competência): [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md)

---

## Objetivo

Permitir que o usuário registre uma compra **sem saber o nome do estabelecimento** que a maquininha/banco vai gravar na fatura, e depois **vincular** essa compra ao lançamento real importado do PDF.

**Descrição da compra ≠ estabelecimento.**  
O campo *Descrição da compra* é **o que foi comprado**. O backend grava em `observacoes` (e espelha em `descricao`). Esse texto é o que aparece na fatura e na transação.

O estabelecimento **não é informado** no cadastro manual: fica `null` e a UI mostra **—** até a conciliação com o lançamento da fatura.

Exemplo:

**Compra (usuário)**  
Mouse Logitech · R$ 249,90 · 23/08/2026  
Estabelecimento: —

**Lançamento da fatura (depois da conciliação)**  
PAG*LOJA XYZ · R$ 249,90 · 23/08/2026  
Observação: Mouse Logitech

Os dois textos existem em paralelo: `observacoes` / `texto_compra` (o que foi comprado) e `descricao_fatura` / estabelecimento do lançamento (nome da maquininha).

---

## Compra manual × compra automática (obrigatório)

O back grava o boolean persistido **`compra_manual`**. O front **não infere** origem pelo `importada_pdf` nem pelo `status` da fatura.

| Origem | Como nasce | `compra_manual` | `precisa_conciliar` |
|--------|------------|-----------------|---------------------|
| Usuário cadastrou (Nova compra **ou** Registrar no Posso comprar), mesmo parcelada | `POST /transacoes/cadastrar` | `true` | `true` enquanto `nao_conciliada` ou `pendente` |
| Linha do PDF/CSV da fatura anexada | import do arquivo | `false` | `false` |
| Parcela copiada para faturas vizinhas (sem anexo) | materialização automática ao processar um PDF com `3/10`, `5/10`, etc. | `false` | `false` |

**Faturas `pendente` criadas sozinhas** (competências anteriores/seguintes, esperando o PDF): as parcelas que já aparecem ali são **automáticas**. Não usar badge âmbar, não pedir conciliação, não tratar como cadastro do usuário.

**Exceção (já existe hoje):** se o usuário **também** cadastrar uma compra manual que corresponda àquela parcela, **só a linha manual** fica em evidência pedindo conciliar. O lançamento automático/PDF pode trazer `tem_sugestao_conciliacao` + botão Confirmar. A parcela automática em si **não** pede conciliar.

---

## Como se encaixa no sistema

Não criar tela/rota/model chamado `Compra`. No backend:

| Conceito | O que usar |
|----------|------------|
| Compra | `transacoes` com `tipo = purchase` |
| À vista | 1 linha, `compra_grupo_id = null` |
| Parcelada | N linhas com o mesmo `compra_grupo_id` |
| Descrição da compra | **`observacoes`** (obrigatório no form). Pode enviar também `descricao` com o mesmo texto — o back espelha os dois |
| Estabelecimento | **omitir** no cadastro manual. Não enviar `estabelecimento` / `estabelecimento_id`. A API **não** cria estabelecimento a partir da descrição |
| Valor total | `valor_compra` |
| Data | `data` (`Y-m-d`) |
| Cartão | `cartao_id` + `cartao_numero_id` |
| Fatura | auto pela data + `dia_limite_fatura`. **Pode enviar `fatura_id`** para forçar a 1ª competência |
| Conciliação | `status_conciliacao` + `lancamento_id` |
| Anexos | tabela da **compra** (não da fatura) |

Título (`GET /transacoes/visualizar`, ranking e listagens):

1. `observacoes` / `texto_compra` → o que foi comprado
2. senão `descricao`
3. senão estabelecimento (só depois da conciliação, se existir)

**Nunca** gravar o nome do PDF em `observacoes` / `descricao`. O nome da fatura vai em `descricao_fatura` na conciliação.

---

## Autenticação e convenções

```http
Authorization: Bearer {token}
```

Base: `/api/v1`

- Datas: `Y-m-d`
- Dinheiro: `"1.200,00"` / `"249,90"` ou número. Exibir `R$ 1.200,00`
- Erro: `{ "error": true, "message": "..." }`
- Sucesso create: `{ "transacao": { "data": { ... }, "status": true, "message": "..." } }`
- Nunca enviar `user_id`

Lookups (`GET /transacoes/lookups`) agora incluem `status_conciliacao[]` (`value` / `label`).

---

## APIs

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/transacoes/lookups` | enums, cartões, `status_conciliacao` |
| POST | `/transacoes/cadastrar` | nova compra |
| PUT | `/transacoes/editar` | editar (`descricao` sincroniza o grupo) |
| DELETE | `/transacoes/excluir/{id}` | `?excluir_grupo=1` apaga todas as parcelas |
| GET | `/transacoes/visualizar/{identificador}` | detalhe: `conciliacao` + `anexos` |
| GET | `/transacoes/candidatos-conciliacao/{identificador}` | lançamentos da mesma fatura para vincular |
| POST | `/transacoes/conciliar` | `{ compra_id, lancamento_id }` |
| POST | `/transacoes/desvincular` | `{ compra_id }` |
| POST | `/transacoes/rejeitar-conciliacao` | `{ compra_id }` — sugestão automática rejeitada |
| GET | `/transacoes/anexos?transacao_id=` ou `identificador=` | listar anexos da compra |
| POST | `/transacoes/anexos` | multipart: `transacao_id`/`identificador` + `arquivo` ou `arquivos[]` + `tipo?` |
| GET | `/transacoes/anexos/{id}` | download/visualizar |
| DELETE | `/transacoes/anexos/{id}` | excluir anexo |
| GET | `/transacoes/historico/{identificador}` | histórico da compra |

Listagem: filtro extra `status_conciliacao`.

**Na tela da fatura, depois do PDF:**

1. A compra manual continua visível (badge âmbar) **e** o lançamento real do PDF também aparece, com sugestão se o valor/data baterem.
2. O usuário **escolhe** qual lançamento real é aquela compra (`POST /conciliar`).
3. A compra manual **some da fatura**. O lançamento real permanece com badge **Conciliada com compra manual** e atalho para abrir a compra manual (editar / desvincular).
4. No total da fatura, só o lançamento real conta depois de confirmar (`conta_no_total`). Não somar as linhas no front.

**Na listagem global:** a compra amigável permanece; o lançamento do PDF some enquanto estiver vinculado.

---

## Formulário — Nova Compra

Modal (preferir) ou página. Título: **Nova compra**.

### Campos

| UI | Obrigatório | API | Notas |
|----|-------------|-----|-------|
| Descrição da compra | sim | `observacoes` | Placeholder: `Ex.: Mouse Logitech`. É **o que foi comprado**, não o estabelecimento. O back grava em `observacoes` (e espelha em `descricao`). **Não** pedir um segundo campo “Observações” neste form |
| Valor total | sim | `valor_compra` | Total da venda |
| Data da compra | sim | `data` | Default: hoje |
| Cartão | sim | `cartao_id` | Chip `cor_fundo` / `cor_texto`. Botão **+** → cadastro rápido ([prompt](frontend-prompt-cadastro-rapido-cartao.md)) |
| Final do cartão | condicional | `cartao_numero_id` | 0 finais = bloquear; 1 = ocultar; 2+ = obrigatório |
| Fatura | preview + override | `fatura_id` opcional | Preview pelo ciclo. Select permite trocar a **primeira** fatura |
| Origem da compra | sim | `origem_compra` | `lookups.origens_compra` |
| É assinatura | não | `eh_assinatura` | Pré-marcar se origem = `PAGAMENTO_SERVICOS` |
| Estabelecimento | **não mostrar** | — | Fica em branco até conciliar com a fatura. **Não** enviar |
| Categoria / sub | não | `categoria_id` / `subcategoria_id` | Opcional; botão **+** |
| Responsável | default Eu | `responsavel_id` | Texto + modal |
| Parcelamento | sim | `parcelas_total` + `parcelas[]` | À vista ou 2..36 |

`tipo`: sempre `"purchase"`.

Ao salvar, a compra nasce com `status_conciliacao = nao_conciliada`, `estabelecimento_id = null`, `compra_manual = true`, `precisa_conciliar = true`. Parcelas 2..N da mesma compra manual também nascem `compra_manual = true` — todas pedem conciliação até casar com o lançamento da fatura daquela competência.

### Payload à vista

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "observacoes": "Mouse Logitech",
  "valor_compra": "249,90",
  "data": "2026-08-23",
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE",
  "eh_assinatura": false,
  "parcelas_total": 1,
  "categoria_id": 2
}
```

Aceito também `descricao` no lugar de (ou junto com) `observacoes` — o back copia para os dois campos. **Não** enviar `estabelecimento` nem `estabelecimento_id`.

Estabelecimento omitido → permanece `null`. A listagem devolve `"estabelecimento": null` e a UI mostra **—**.

### Payload parcelado (6x)

Igual ao prompt de compras: `valor_compra` + `parcelas_total` + `parcelas[]`. Incluir `observacoes`.

Se o usuário escolher outra primeira fatura no select, enviar `fatura_id`. As demais parcelas avançam +1 mês a partir dessa competência.

### Fatura — preview

Regra do ciclo (`dia_limite_fatura`):

- `dia da compra <= limite` → fatura do mês da compra
- depois do limite → mês seguinte

Atualizar o preview ao mudar cartão, data ou o select de fatura. Parcelada: mostrar primeira e última competência.

### Parcelamento

1. Valor total + N (1 = à vista, 2..36)
2. Split igual (centavos na última); usuário pode ajustar
3. Total das parcelas deve bater com `valor_compra` (± R$ 0,01)
4. Não enviar `parcela_atual` no create

Após POST: redirect `/compras/{compra_grupo_id || transacoes[0].id}`.

---

## Tela da compra

`GET /api/v1/transacoes/visualizar/{identificador}`

Campos novos no `data`:

```json
{
  "descricao": "Mouse Logitech",
  "observacoes": "Mouse Logitech",
  "texto_compra": "Mouse Logitech",
  "descricao_fatura": "PAG*LOJA XYZ",
  "titulo": "Mouse Logitech",
  "titulo_origem": "observacoes",
  "estabelecimento": null,
  "compra_manual": true,
  "precisa_conciliar": true,
  "precisa_conciliar_label": "Compra manual · conciliar com a fatura",
  "conciliacao": {
    "status": "nao_conciliada",
    "status_label": "Não conciliada",
    "mensagem": "O lançamento real desta compra ainda não foi localizado na fatura.",
    "descricao_compra": "Mouse Logitech",
    "descricao_fatura": null,
    "lancamento_id": null,
    "lancamento": null
  },
  "anexos": []
}
```

Layout:

```
Mouse Logitech                    ← titulo / texto_compra / observacoes
R$ 249,90
23/08/2026 · Nubank · •••• 1234
Eletrônicos
Estabelecimento  —

[badge âmbar] Compra manual · conciliar com a fatura

Fatura
Setembro/2026

Conciliação
🟡 Não conciliada
O lançamento real desta compra ainda não foi localizado na fatura.

(quando pendente / conciliada, mostrar também)
Lançamento da fatura
PAG*LOJA XYZ · R$ 249,90 · 23/08/2026

Anexos
[arquivos]

Histórico
(opcional colapsável — GET /historico/{id})
```

Quando `estabelecimento` for `null`, mostrar **—** (não “Desconhecido”, não o texto da descrição).

Se `precisa_conciliar === true`, a linha/tela **precisa de destaque visual** (fundo âmbar leve, borda ou ícone de alerta + badge `precisa_conciliar_label`). É uma compra lançada à mão e ainda não casada com o PDF da fatura.

Cores do status:

| status | badge |
|--------|--------|
| `nao_conciliada` | 🟡 âmbar |
| `pendente` | 🟠 laranja |
| `conciliada` | 🟢 verde |
| `rejeitada` | ⚪ cinza |

**Nunca** trocar o título da compra pelo nome do lançamento. Mostrar os dois.

### Ações

| Ação | Como |
|------|------|
| Editar compra | mesmo form → `PUT /transacoes/editar` (`observacoes` ou `descricao`; `propagar_grupo` se parcelada) |
| Adicionar anexo | `POST /transacoes/anexos` multipart |
| Conciliar com lançamento | modal com `GET /candidatos-conciliacao/{id}` → `POST /conciliar` |
| Desvincular lançamento | `POST /desvincular` se `conciliada` ou `pendente` |
| Rejeitar sugestão | `POST /rejeitar-conciliacao` se `pendente` |
| Excluir compra | `DELETE /excluir/{id}` (`?excluir_grupo=1`) |
| Ver fatura | `/faturas/{fatura_id}` |

Candidatos vêm com `score` e `sugestao` (boolean). Destacar os com `sugestao: true`. A conciliação é **manual** — o usuário escolhe o lançamento. Não auto-vincular no front.

```json
POST /api/v1/transacoes/conciliar
{
  "compra_id": 101,
  "lancamento_id": 555
}
```

`compra_id` aceita id da transação ou `compra_grupo_id`.

---

## Anexos

Multipart:

```http
POST /api/v1/transacoes/anexos
Content-Type: multipart/form-data
```

- `transacao_id` ou `identificador` (id ou UUID do grupo)
- `arquivo` (um) **ou** `arquivos[]` (vários)
- `tipo` opcional: `nota_fiscal` \| `comprovante` \| `recibo` \| `print` \| `pdf` \| `imagem` \| `outro`

Permitidos: PDF e imagens (jpg, png, webp, gif). Máx. 10MB cada.

Os anexos ficam na **compra**, não no lançamento da fatura. Permitir adicionar depois de salvar.

Listagem na tela: nome, tipo, tamanho, data, abrir (`GET /anexos/{id}`), excluir.

---

## Histórico

```http
GET /api/v1/transacoes/historico/{identificador}
```

Ações: `criada`, `editada`, `conciliada`, `desvinculada`, `conciliacao_pendente`, `conciliacao_rejeitada`, `anexo_adicionado`, `anexo_removido`, `excluida`.

Exibir em lista cronológica (mais recente primeiro) com `descricao` + `created_at`.

---

## Listagem (fatura e transações)

`GET /transacoes/listar` (e `GET /transacoes/listar?fatura_id=`) devolve em cada linha:

| Campo | Uso |
|-------|-----|
| `texto_compra` / `observacoes` | **Título da linha** — o que foi comprado (compra manual). No lançamento do PDF, usar `estabelecimento` se `texto_compra` estiver vazio |
| `estabelecimento` | Nome da maquininha. Se `null` → **—** |
| `compra_manual` | `true` **somente** se o usuário cadastrou (Nova compra / Posso comprar). `false` no PDF **e** nas parcelas materializadas em faturas sem anexo |
| `precisa_conciliar` | `true` **somente** na compra `compra_manual` ainda `nao_conciliada` ou `pendente`. Parcelas automáticas nunca vêm `true` |
| `precisa_conciliar_label` | `Compra manual · conciliar com a fatura` |
| `tem_sugestao_conciliacao` | `true` no **lançamento do PDF** que o back achou que é aquela compra |
| `sugestao_conciliacao_label` | `Pode ser a compra manual «Mouse Logitech»` |
| `compra_manual_vinculada` | objeto da compra manual (`id`, `texto_compra`, `observacoes`, `status_conciliacao`, `compra_grupo_id`) ou `null` |
| `conciliada_com_manual` | `true` no lançamento real **já confirmado** |
| `conciliada_com_manual_label` | `Conciliada com compra manual` |
| `conta_no_total` | se `false`, a linha aparece só para conferência — **não** somar no subtotal |
| `status_conciliacao` | enum |

### Depois de anexar o PDF da fatura

O back compara valor, fatura, data (±5 dias), cartão e parcela e **sugere** o casamento 1:1. Não confirma sozinho.

```
23/08  Mouse Logitech                         R$ 249,90
       Estabelecimento —   [Compra manual · conciliar com a fatura]

23/08  PAG*LOJA XYZ                           R$ 249,90
       [Pode ser a compra manual «Mouse Logitech»]  [Confirmar]
```

- Clique em **Confirmar** no lançamento **ou** escolha o candidato na compra manual.
- `POST /transacoes/conciliar` `{ "compra_id": 101, "lancamento_id": 555 }` — a ordem dos ids pode vir invertida (o back entende).
- `GET /transacoes/candidatos-conciliacao/{id}` funciona dos **dois lados**: id da compra manual lista lançamentos do PDF; id do lançamento lista compras manuais.

Depois de confirmar:

```
23/08  PAG*LOJA XYZ                           R$ 249,90
       Mouse Logitech   [Conciliada com compra manual]
```

A compra manual **não** aparece mais na fatura. O badge do lançamento real abre a visualização da compra manual (`GET /transacoes/visualizar/{compra_manual_vinculada.id}`) para editar, ver anexos/histórico ou **desvincular**.

`POST /transacoes/desvincular` aceita o id da compra **ou** o id do lançamento (`compra_id` / `id` / `lancamento_id`).

Não somar linhas com `conta_no_total: false` (sugestão ainda não confirmada).

---

## Import PDF (comportamento — só UX)

- Ao processar o anexo da fatura, o back **emparelha** compras **manuais** abertas (`compra_manual: true`) com lançamentos novos (valor + competência + data próxima + parcela). Cada compra casa com no máximo um lançamento.
- Parcelas que o PDF **copiou** para faturas anteriores/seguintes (`compra_manual: false`) não entram nesse emparelhamento como “compra esperando conciliar”. Só voltam a ser o lado “lançamento” se o usuário tiver cadastrado uma manual correspondente.
- Match vira `pendente`: os **dois** ficam visíveis na fatura até o usuário confirmar. O lançamento do PDF **não** entra no total (`conta_no_total: false`) para não duplicar.
- O usuário confirma (`POST /conciliar`) ou rejeita (`POST /rejeitar-conciliacao`).
- Match **exato** (mesmo estabelecimento já cadastrado + valor + parcela) ainda pode conciliar na mesma linha no import — caso raro no cadastro manual, que nasce sem estabelecimento.
- Cadastro manual **não** mescla sozinho com um lançamento já existente na fatura (não duplicar no create).

---

## Checklist de aceite

- [ ] Modal **Nova compra** com `observacoes` obrigatória (rótulo **Descrição da compra**), valor, data, cartão, origem; **sem** campo estabelecimento; categoria/sub opcional; responsável Eu
- [ ] Botão **+** de cadastro rápido de cartão (ver [`frontend-prompt-cadastro-rapido-cartao.md`](frontend-prompt-cadastro-rapido-cartao.md))
- [ ] Preview da fatura; select permite enviar `fatura_id` da 1ª competência
- [ ] Parcelas 1..36, split editável, validação do total
- [ ] POST `/transacoes/cadastrar` e redirect para `/compras/{identificador}`
- [ ] Visualização: título = o que foi comprado (`observacoes` / `texto_compra`); estabelecimento **—** até conciliar
- [ ] Badge `precisa_conciliar_label` **somente** quando `precisa_conciliar === true` (compra cadastrada pelo usuário). Parcelas automáticas em faturas `pendente` **sem** esse badge
- [ ] Fatura vizinha criada pela materialização de parcelas: linhas com `compra_manual === false` parecem lançamento normal, não “esperando conciliar”
- [ ] Após importar o PDF: lançamento real com `tem_sugestao_conciliacao` + botão Confirmar; os dois visíveis
- [ ] Após confirmar: compra manual some da fatura; lançamento real com `conciliada_com_manual`; clique abre a compra manual
- [ ] Desvincular a partir da compra **ou** do lançamento real
- [ ] Não somar linhas com `conta_no_total: false`
- [ ] Badge de conciliação com as 4 cores/status e a `mensagem` da API
- [ ] Conciliar / desvincular / rejeitar com os POSTs acima
- [ ] Anexos: upload múltiplo, listar, abrir, excluir
- [ ] Histórico na tela da compra
- [ ] Editar `observacoes` não apaga `descricao_fatura`
- [ ] 422 mostra `message` da API

---

## Fora de escopo

- Conciliação 100% automática sem confirmação do usuário (o score já ordena candidatos; o clique é do usuário)
- Anexos ligados ao lançamento da fatura (PDF da fatura continua em `/faturas/upload-pdf`)
