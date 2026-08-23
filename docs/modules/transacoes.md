# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| cartao_numero_id | FK nullable → `cartao_numeros` | final do cartão da compra; obrigatório no create manual (auto se só houver 1) |
| estabelecimento_id | FK | obrigatório |
| data | date nullable | data da compra (igual em todas as parcelas do grupo) |
| valor | decimal | valor da parcela (o que cai na fatura do mês) |
| parcelas_total | int nullable | 1..36 |
| parcela_atual | int nullable | 1..N |
| valor_parcela | decimal nullable | em geral = `valor` |
| compra_grupo_id | uuid nullable | liga as N parcelas da mesma compra; null se à vista |
| tipo | enum | purchase, payment, refund, advance, fee, **carryover** (`fee` = encargos; `carryover` = saldo restante da fatura anterior — operação, não compra) |
| origem_compra | enum nullable | COMPRAS_ONLINE, COMPRAS_PRESENCIAL, PAGAMENTO_SERVICOS, PAGAMENTO_FATURA — origem/canal da compra; **obrigatório no create** |
| categoria_id | FK nullable | categoria **da compra** |
| subcategoria_id | FK nullable | exige categoria + vínculo N:N |
| responsavel_id | FK | obrigatório; default = responsável `Eu` |
| observacoes | text nullable | |

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export + estabelecimentos do filtro:

```http
GET /api/v1/transacoes/exportar
GET /api/v1/transacoes/estabelecimentos-do-filtro
GET /api/v1/transacoes/visualizar/{identificador}
DELETE /api/v1/transacoes/excluir/{id}?excluir_grupo=1
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

### Estabelecimentos do filtro

```http
GET /api/v1/transacoes/estabelecimentos-do-filtro?palavra_chave=atacad&apenas_sem_loja=1
```

Mesmos filtros de `/listar`. Retorna **uma linha por estabelecimento** distinto (não por transação), com:

| Campo | Obs |
|-------|-----|
| `id` | estabelecimento_id |
| `nome` | nome da maquininha |
| `loja_id` / `loja_nome` | vínculo atual (se houver) |
| `transacoes_count` | qtd de transações desse estabelecimento **no filtro** |

Query extra: `apenas_sem_loja=true` — só estabelecimentos sem loja.

Usado no botão **Vincular com loja** da listagem de compras. Ver [`frontend-prompt-loja-estabelecimento.md`](../frontend-prompt-loja-estabelecimento.md).

### Visualização da compra

```http
GET /api/v1/transacoes/visualizar/{identificador}?mes=8&ano=2026
```

`identificador` = `compra_grupo_id` (UUID, ranking) **ou** `id` da transação. Se a transação pertence a um grupo, devolve o **grupo inteiro**.

- `mes` / `ano`: competência de referência (default: atual) — mesmo critério do ranking (pago = fatura ≤ referência)
- Concentra metadados da compra: data, cartão/bandeira/final, categoria/sub, estabelecimento/loja, responsável, origem
- `parcelas[]` com `status_parcela` (`paga` | `atual` | `aberta`), fatura e repasse
- À vista: `avista: true`, `compra_grupo_id: null`, 1 item em `parcelas`

Prompt: [`frontend-prompt-visualizacao-compra.md`](../frontend-prompt-visualizacao-compra.md)

Lookups: `tipos`, `origens_compra`, `categorias`, `subcategorias`, `responsaveis`, `default_responsavel_id`, `cartoes`, `faturas`.

Estabelecimentos **não** vêm no lookups — usar busca async:

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

## Create — compra à vista e parcelada

### Payload (parcelado)

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "data": "2026-03-15",
  "estabelecimento_id": 104,
  "valor_compra": "1000,00",
  "parcelas_total": 10,
  "parcelas": [
    { "parcela": 1, "valor": "100,00" },
    { "parcela": 2, "valor": "100,00" }
  ],
  "tipo": "purchase",
  "origem_compra": "COMPRAS_PRESENCIAL",
  "categoria_id": 1,
  "subcategoria_id": 1,
  "responsavel_id": 1,
  "observacoes": "..."
}
```

### Regras

- `valor_compra` = total da venda. Aceita BR (`125,50`) ou decimal. Alternativa: `valor` (ver legado abaixo).
- `parcelas_total` ∈ 1..36 (default 1).
- `parcelas[]` opcional: se omitido, backend divide `valor_compra` igualmente (centavos na última).
- Se `parcelas[]` vier: tamanho = `parcelas_total`, números 1..N sem buracos; soma deve bater com `valor_compra` (tol. R$ 0,01) → senão 422.
- Sempre materializa **1..N** transações (ignora `parcela_atual` no create).
- `cartao_numero_id`: final do cartão da compra. No create manual é obrigatório se houver 2+ números elegíveis; com exatamente 1, o backend auto-seleciona. A bandeira da fatura é derivada do número (ou de `cartao_bandeira_id` / fatura).
- Ciclo do cartão (`dia_limite_fatura`) define a fatura da parcela 1; demais = +1 mês na mesma bandeira (`findOrCreateByCartaoPeriodo`).
  - `data.day <= dia_limite` → fatura do mês da compra; após o limite → fatura do mês seguinte.
- `data` da compra é gravada igual em todas as linhas; cada uma tem seu `fatura_id` e o mesmo `cartao_numero_id`.
- `parcelas_total > 1` → todas compartilham o mesmo `compra_grupo_id` (UUID).
- À vista (`parcelas_total = 1`): uma linha, `compra_grupo_id = null`.
- `fatura_id` explícito ainda é aceito (tela da fatura); o cartão/bandeira vêm da fatura. Sem `data`, usa mês/ano da fatura como base.
- Estabelecimento: `estabelecimento_id` **ou** `estabelecimento` (texto; find-or-create).
- Categoria/subcategoria: opcionais; create usa padrões do estabelecimento se omitidas.
- Subcategoria sem categoria → 422.
- Responsável omitido → `Eu`.
- `origem_compra` **obrigatório** no create. Valores:
  - `COMPRAS_ONLINE` — compra em e-commerce / internet
  - `COMPRAS_PRESENCIAL` — compra no estabelecimento físico
  - `PAGAMENTO_SERVICOS` — assinatura / cartão cadastrado com desconto automático
  - `PAGAMENTO_FATURA` — pagamento de fatura
- Em compras parceladas, a mesma `origem_compra` é gravada em todas as parcelas.

### Resposta do create

```json
{
  "transacao": {
    "data": {
      "compra_grupo_id": "uuid-ou-null",
      "valor_compra": 1000,
      "parcelas_total": 10,
      "transacoes": [ { "id": 1, "parcela_atual": 1, "...": "..." } ]
    },
    "status": true,
    "message": "Compra parcelada cadastrada com sucesso!"
  }
}
```

### Legado (breaking)

Payload antigo com `valor` + `parcelas_total > 1` **sem** `valor_compra`/`parcelas`:
- `valor` = valor **de cada** parcela
- Backend cria N linhas com esse valor (ex.: `valor=100`, `parcelas_total=10` → 10× R$ 100, total R$ 1000)

Com `valor_compra`, o total é dividido em N.

### À vista (exemplo)

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "data": "2026-07-31",
  "estabelecimento_id": 104,
  "valor_compra": "125,50",
  "parcelas_total": 1,
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE"
}
```

Também aceita `valor` no lugar de `valor_compra` quando `parcelas_total` é 1.

## Edit

- Por linha (ajuste fino de valor/parcela/fatura/`cartao_numero_id`).
- `observacoes` e `responsavel_id`: ao editar, sincronizam automaticamente em todas as parcelas do mesmo `compra_grupo_id` (sem precisar de flag). Toda a compra parcelada fica com o mesmo responsável.
- Flag `propagar_grupo: true`: propaga estabelecimento, categoria, subcategoria, `origem_compra` e `cartao_numero_id` para as irmãs do mesmo `compra_grupo_id` (não propaga valor/fatura/parcela_*).
- Ao definir `categoria_id` numa transação cujo estabelecimento ainda **não** tem `categoria_padrao_id`:
  1. grava categoria/subcategoria como padrão do estabelecimento;
  2. aplica nas demais transações do mesmo estabelecimento com `categoria_id` nulo;
  3. próximas imports/compras sem categoria herdam o padrão.
  Transações já categorizadas (editadas de propósito) não são alteradas. Se o estabelecimento já tem padrão, só a linha editada muda.

## Delete

- Default: exclui só a linha.
- `?excluir_grupo=1` (ou body `excluir_grupo`): soft-delete de todas as parcelas do `compra_grupo_id`.

## Import PDF/CSV/XML

- Resolve estabelecimento pelo nome (cria se necessário).
- Aplica padrões do estabelecimento.
- Sempre define responsável `Eu`.
- `origem_compra` fica `null` (não é possível inferir do PDF).
- Compras parceladas (`parcelas_total > 1`): após gravar a parcela do mês, materializa as parcelas restantes via `TransacaoService::materializarParcelasFuturas`:
  - gera/reusa `compra_grupo_id` na linha-fonte e nas irmãs;
  - materializa parcelas anteriores (`1..parcela_atual-1`) e futuras (`parcela_atual+1..N`);
  - cria/reusa faturas do cartão nas competências correspondentes com `findOrCreateByCartaoPeriodo` (`status=pendente`, **sem** `arquivo_pdf`);
  - se a fatura da competência já existir e a parcela ainda não estiver nela, a transação é incluída;
  - cria uma transação por competência faltante (`importada_pdf=false`), com o mesmo estabelecimento/valor/categoria/responsável;
  - idempotente (não duplica parcela já existente no grupo ou na fatura-alvo).
- Quando a fatura do mês seguinte for processada, a parcela materializada é mesclada (passa a `importada_pdf=true`).

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `subcategoria_id`, `estabelecimento_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `cartao_numero_id`, `ultimos_digitos`
- `tipo`, `origem_compra`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`

Respostas expõem `estabelecimento` (nome), `categoria_*`, `subcategoria_*`, `responsavel_*`, `origem_compra`, `compra_grupo_id`, `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_tipo`, `cartao_numero_apelido`, `cartao_numero_nome_no_cartao`, `cartao_bandeira_id`, `cartao_bandeira`.

Com `fatura_id`, a ordenação é: final do cartão asc → data asc (para agrupar na view da fatura).
