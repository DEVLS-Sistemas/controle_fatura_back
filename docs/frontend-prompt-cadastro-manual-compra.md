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

Permitir que o usuário registre uma compra **sem saber o nome exato** que a maquininha/banco vai gravar na fatura, e depois **vincular** essa compra ao lançamento real importado do PDF — **sem substituir** a descrição amigável.

Exemplo:

**Compra (usuário)**  
Mouse Logitech · R$ 249,90 · 23/08/2026

**Lançamento da fatura**  
PAG*LOJA XYZ · R$ 249,90 · 23/08/2026

Os dois textos existem em paralelo: `descricao` (amigável) e `descricao_fatura` / estabelecimento do lançamento.

---

## Como se encaixa no sistema

Não criar tela/rota/model chamado `Compra`. No backend:

| Conceito | O que usar |
|----------|------------|
| Compra | `transacoes` com `tipo = purchase` |
| À vista | 1 linha, `compra_grupo_id = null` |
| Parcelada | N linhas com o mesmo `compra_grupo_id` |
| Descrição amigável | `descricao` (obrigatório no form). Fallback: `observacoes` se `descricao` vier vazio |
| Observações extras | `observacoes` (opcional, separado da descrição) |
| Estabelecimento | `estabelecimento_id` **ou** texto `estabelecimento`. Opcional se houver `descricao` (backend cria pelo texto da descrição) |
| Valor total | `valor_compra` |
| Data | `data` (`Y-m-d`) |
| Cartão | `cartao_id` + `cartao_numero_id` |
| Fatura | auto pela data + `dia_limite_fatura`. **Pode enviar `fatura_id`** para forçar a 1ª competência |
| Conciliação | `status_conciliacao` + `lancamento_id` |
| Anexos | tabela da **compra** (não da fatura) |

Título (`GET /transacoes/visualizar` e ranking):

1. `descricao` → `titulo_origem = descricao`
2. senão `observacoes` → `titulo_origem = observacoes`
3. senão estabelecimento

**Nunca** gravar o nome do PDF em `descricao`. O nome da fatura vai em `descricao_fatura` na conciliação.

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

Listagem: filtro extra `status_conciliacao`. Na tela da **fatura**, compras já conciliadas **não** aparecem (o lançamento do PDF é que conta). Na listagem **global**, o lançamento vinculado some e a compra amigável permanece.

---

## Formulário — Nova Compra

Modal (preferir) ou página. Título: **Nova compra**.

### Campos

| UI | Obrigatório | API | Notas |
|----|-------------|-----|-------|
| Descrição da compra | sim | `descricao` | Placeholder: `Ex.: Mouse Logitech`. **Não** precisa ser o texto da fatura |
| Valor total | sim | `valor_compra` | Total da venda |
| Data da compra | sim | `data` | Default: hoje |
| Cartão | sim | `cartao_id` | Chip `cor_fundo` / `cor_texto`. Botão **+** → cadastro rápido ([prompt](frontend-prompt-cadastro-rapido-cartao.md)) |
| Final do cartão | condicional | `cartao_numero_id` | 0 finais = bloquear; 1 = ocultar; 2+ = obrigatório |
| Fatura | preview + override | `fatura_id` opcional | Preview pelo ciclo. Select permite trocar a **primeira** fatura |
| Origem da compra | sim | `origem_compra` | `lookups.origens_compra` |
| É assinatura | não | `eh_assinatura` | Pré-marcar se origem = `PAGAMENTO_SERVICOS` |
| Estabelecimento | não | `estabelecimento_id` ou `estabelecimento` | Async + create pelo texto. Helper: *Não precisa ser o nome da fatura* |
| Categoria / sub | não | `categoria_id` / `subcategoria_id` | Padrões do estabelecimento + botão **+** |
| Responsável | default Eu | `responsavel_id` | Texto + modal |
| Observações | não | `observacoes` | Texto extra; **não** substitui a descrição |
| Parcelamento | sim | `parcelas_total` + `parcelas[]` | À vista ou 2..36 |

`tipo`: sempre `"purchase"`.

Ao salvar, a compra nasce com `status_conciliacao = nao_conciliada`.

### Payload à vista

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "descricao": "Mouse Logitech",
  "valor_compra": "249,90",
  "data": "2026-08-23",
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE",
  "eh_assinatura": false,
  "parcelas_total": 1,
  "categoria_id": 2,
  "observacoes": "Garantia de 12 meses"
}
```

Estabelecimento omitido → backend cria/reusa estabelecimento com o nome da descrição.

### Payload parcelado (6x)

Igual ao prompt de compras: `valor_compra` + `parcelas_total` + `parcelas[]`. Incluir `descricao`.

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
  "descricao_fatura": "PAG*LOJA XYZ",
  "titulo": "Mouse Logitech",
  "titulo_origem": "descricao",
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
Mouse Logitech                    ← titulo / descricao
R$ 249,90
23/08/2026 · Nubank · •••• 1234
Eletrônicos

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
| Editar compra | mesmo form → `PUT /transacoes/editar` (`descricao`, `propagar_grupo` se parcelada) |
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

## Import PDF (comportamento — só UX)

- Match **exato** (mesmo estabelecimento + valor + parcela): o backend concilia sozinho na mesma linha, preenchendo `descricao_fatura` **sem** alterar `descricao`.
- Match **provável** (valor + fatura + data próxima, estabelecimento diferente): status vira `pendente` e a tela da compra mostra a sugestão. O usuário concilia ou rejeita.
- Cadastro manual **não** mescla sozinho com um lançamento já existente na fatura (não duplicar no create).

---

## Checklist de aceite

- [ ] Modal **Nova compra** com `descricao` obrigatória, valor, data, cartão, origem, estabelecimento opcional, categoria/sub, responsável Eu, observações opcionais
- [ ] Botão **+** de cadastro rápido de cartão (ver [`frontend-prompt-cadastro-rapido-cartao.md`](frontend-prompt-cadastro-rapido-cartao.md))
- [ ] Preview da fatura; select permite enviar `fatura_id` da 1ª competência
- [ ] Parcelas 1..36, split editável, validação do total
- [ ] POST `/transacoes/cadastrar` e redirect para `/compras/{identificador}`
- [ ] Visualização: título amigável **e** lançamento da fatura separados
- [ ] Badge de conciliação com as 4 cores/status e a `mensagem` da API
- [ ] Conciliar / desvincular / rejeitar com os POSTs acima
- [ ] Anexos: upload múltiplo, listar, abrir, excluir
- [ ] Histórico na tela da compra
- [ ] Editar `descricao` não apaga `descricao_fatura`
- [ ] 422 mostra `message` da API

---

## Fora de escopo

- Conciliação 100% automática sem confirmação do usuário (o score já ordena candidatos; o clique é do usuário)
- Anexos ligados ao lançamento da fatura (PDF da fatura continua em `/faturas/upload-pdf`)
