# Especificação — Gastos por categoria

Responde **“Em quais categorias eu mais gasto?”** com dois gráficos ligados (categoria mestre → subcategoria escrava), mais recorte por **tipo de compra** (`origem_compra`).

Não substitui o `dashboard/resumo` (uma pizza plana por competência) nem o `gastos-criticos` (lugar, frequência, alertas). Esta tela é **dashboard interativo**: duas pizzas (categoria mestre → subcategoria escrava), filtro cruzado no cliente (estilo Power BI).

## Rota

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

Autenticado (Sanctum). Escopo pelo `user_id` do token.

Prompt do front: [`docs/frontend-prompt-gastos-por-categoria.md`](../frontend-prompt-gastos-por-categoria.md)

### Query

| Param | Default | Descrição |
|-------|---------|-----------|
| `meses` | `3` | Janela móvel até hoje. Só `1`, `3`, `6` ou `12` |
| `data_inicio` / `data_fim` | — | Janela explícita (`Y-m-d`). Se vier, ignora `meses` |
| `mes` + `ano` | — | Um mês calendário (só se `meses` **não** vier) |
| `cartao_id` | — | Filtra pela fatura do cartão |
| `responsavel_id` | — | Filtra compras do responsável |
| `categoria_id` | — | Recorte por uma categoria (drill-down) |
| `origem_compra` | — | Recorte por tipo: `COMPRAS_ONLINE`, `COMPRAS_PRESENCIAL`, `PAGAMENTO_SERVICOS`, `PAGAMENTO_FATURA` |

Prioridade do período: datas explícitas → `mes`/`ano` (sem `meses`) → janela `meses`. Igual a [`gastos-criticos.md`](gastos-criticos.md). 422 se `meses` inválido, intervalo invertido ou `origem_compra` desconhecida.

## Conceitos

| Termo | Significado |
|-------|-------------|
| Compra | Evento. Parcelado (`compra_grupo_id`) = **1**. À vista = 1 linha |
| Ocorrência | Linha `purchase` na fatura |
| Período | Filtro pela **data da compra** (`transacoes.data`) |
| Valor | Soma das parcelas/compras cuja data cai na janela |
| Categoria | `transacoes.categoria_id` — sem FK vira bucket **Sem categoria** |
| Subcategoria | `transacoes.subcategoria_id` — sem FK **não** entra no top 2; vai em `sem_subcategoria` |
| Top 2 | As duas subcategorias **nomeadas** de maior valor **dentro da categoria** (`top_subcategorias`) — cards/hero |
| Top 10 | `dashboards.limite`: fatias das **pizzas**. Lista completa em `categorias[]` e `subcategorias[]` para o clique filtrar no cliente |
| Tipo de compra | `origem_compra` (canal). `null` vira **Sem origem** |

O `resumo.por_categoria` continua sendo consolidado por competência de fatura, sem subcategorias. Aqui o recorte é comportamento de gasto.

## Resposta (`data`)

- `periodo` / `periodo_anterior` — mesmo shape de gastos críticos (`inicio`, `fim`, `meses`, `dias`, `origem`, labels)
- `totais` — valor, compras, ticket, `categorias_com_gasto`, variação vs período anterior, `frequencia`, `sem_categoria` `{ valor_total, compras, ocorrencias, percentual_gasto }`
- `destaque` — categoria nomeada de maior gasto + as 2 subcategorias + `frase` pronta. `null` se não houver compras
- `dashboards` — snapshots das duas pizzas: `{ limite: 10, categorias[], subcategorias[] }` (já cortados no top 10, shape enxuto de fatia)
- `categorias[]` — **todas** as categorias com gasto, ordenadas por `valor_total` desc; cada uma traz `subcategorias[]` **completas** (nomeadas) + `top_subcategorias` (2)
- `subcategorias[]` — lista **plana** de todas as subcategorias nomeadas, com `categoria_id` / `categoria_nome` / `categoria_cor` / `percentual_gasto` (vs total) / `percentual_da_categoria`. Fonte da pizza escrava: o front filtra por `categoria_id` e remontar as fatias **sem** novo GET
- `por_origem[]` — tipos de compra no período (global)
- `evolucao.por_mes[]` — série da janela (`parcial: true` no mês corrente)
- `evolucao.por_categoria[]` — até 5 categorias (as de maior gasto) com `serie[]` alinhada aos meses da janela

Clique na fatia **não** deve enviar `categoria_id` na query desta API (isso recorta o dataset no servidor). A seleção é estado do front.

### Item de categoria

| Campo | Obs |
|-------|-----|
| `chave` | `categoria-2` ou `categoria-0` |
| `categoria_id` | `null` no bucket sem categoria |
| `nome` / `cor` | Cor **salva no cadastro** (tema). Cadastrada sem cor → `#000000`. Bucket “Sem categoria” → `#9ca3af`. Ver [`cores-tema.md`](cores-tema.md) |
| `compras` / `ocorrencias` / `valor_total` / `ticket_medio` | Parcelado = 1 compra |
| `percentual_gasto` / `percentual_compras` | Vs totais do período |
| `variacao_*` | Vs período anterior. `null` = não havia base |
| `frequencia` | Mesmo bloco das estatísticas de estabelecimento |
| `frase` | Pronta para o card — **não reescrever** |
| `subcategorias_total` | Quantas subcategorias **nomeadas** existem |
| `subcategorias[]` | Todas as nomeadas da categoria, com pai (`categoria_id`, `categoria_nome`, `categoria_cor`) e `cor` própria (variação mais clara que o tema — etapa 2) |
| `top_subcategorias[]` | Até **2**, ordenadas por valor (hero / cards) |
| `outras_subcategorias` | `{ quantidade, valor_total, compras, percentual_da_categoria }` — o que sobrou além do top 2 |
| `sem_subcategoria` | Compras da categoria sem subcategoria |
| `por_origem[]` | Tipos de compra **dentro da categoria** |
| `atalho` | `{ rota: "transacoes", id, query }` com `data_inicio`/`data_fim` + `categoria_id` |

### Item de subcategoria (`top_subcategorias[]`)

`subcategoria_id`, `nome`, `compras`, `valor_total`, `ticket_medio`, `percentual_da_categoria`, `percentual_compras_da_categoria`, `frequencia`, `atalho` (`subcategoria_id` + datas).

### Item de origem (`por_origem[]`)

`origem_compra` (`null` = sem origem), `label` (`Compras online`, `Compras presencial`, `Pagamento de serviços`, `Pagamento fatura`, `Sem origem`), métricas, `percentual_gasto`, `frase`, `atalho` com `origem_compra` na query.

No `por_origem` **da categoria**, `percentual_gasto` / `percentual_da_categoria` são vs o total **da categoria**.

## Fora de escopo

- Alertas de frequência/concentração (isso é gastos críticos)
- Ranking de loja/estabelecimento
- Competência da fatura (usar `dashboard/resumo` para o consolidado mensal do cartão)

Ver também: [`dashboard.md`](dashboard.md) · [`gastos-criticos.md`](gastos-criticos.md) · [`categorias.md`](categorias.md) · [`subcategorias.md`](subcategorias.md) · [`transacoes.md`](transacoes.md) · cores: [`cores-tema.md`](cores-tema.md)
