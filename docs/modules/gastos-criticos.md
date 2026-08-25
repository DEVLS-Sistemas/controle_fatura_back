# Especificação — Gastos críticos

Responde **“Onde estou gastando demais?”** com análise de **lugar** (loja / estabelecimento), **categoria / subcategoria**, **frequência** e **evolução** — não só um gráfico de categorias.

## Rota

```http
GET /api/v1/dashboard/gastos-criticos?meses=3
```

Autenticado (Sanctum). Escopo pelo `user_id` do token.

### Query

| Param | Default | Descrição |
|-------|---------|-----------|
| `meses` | `3` | Janela móvel até hoje. Só `1`, `3`, `6` ou `12` |
| `data_inicio` / `data_fim` | — | Janela explícita (`Y-m-d`). Se vier, ignora `meses` |
| `mes` + `ano` | — | Um mês calendário (só se `meses` **não** vier) |
| `cartao_id` | — | Filtra pela fatura do cartão |
| `responsavel_id` | — | Filtra compras do responsável |
| `categoria_id` | — | Recorte por categoria da compra |

Prioridade: datas explícitas → `mes`/`ano` (sem `meses`) → janela `meses`.

## Conceitos

| Termo | Significado |
|-------|-------------|
| Compra | Evento. Parcelado (`compra_grupo_id`) = **1**. À vista = 1 linha |
| Ocorrência | Linha `purchase` na fatura |
| Período | Filtro pela **data da compra** (`transacoes.data`), igual às estatísticas de estabelecimento |
| Valor | Soma das parcelas/compras cuja data cai na janela (comprometido no período) |
| Loja | Nome fantasia que agrupa maquininhas |
| Estabelecimento | Identificador da maquininha |
| Pontos críticos | Alertas gerados pelo back (frequência, gasto, concentração, evolução) com **frase pronta** |

O dashboard `resumo` continua sendo o consolidado por competência de fatura. Esta tela é **comportamento de gasto**: onde, quantas vezes, e se acelerou.

## Resposta (`data`)

- `periodo` / `periodo_anterior` — `inicio`, `fim`, `meses`, `dias`, `origem` (`janela` \| `mes` \| `filtro` \| `anterior`), labels prontos (`label`, `label_frase`)
- `totais` — valor, compras, ticket, variação vs período anterior, `frequencia` (mesmo bloco das estatísticas de estabelecimento)
- `destaques`
  - `maior_gasto` — o que **mais custa** (preferência: loja → estabelecimento → categoria)
  - `mais_comprado` — o que **mais vezes** se compra (mesma preferência)
  - cada um traz `frase` para o hero (ex.: *“Você comprou 18 vezes neste estabelecimento nos últimos 3 meses.”*)
- `alertas[]` — até 10 pontos críticos, ordenados por `severidade` + `score`
- `maiores_gastos` — top 8 por **valor** em `estabelecimentos`, `lojas`, `categorias`, `subcategorias`
- `mais_comprados` — top 8 por **quantidade de compras** nas mesmas 4 dimensões
- `evolucao.por_mes[]` — série da janela (`parcial: true` no mês corrente)

### Item de ranking / entidade

Campos comuns: `chave` (`loja-3`, `estabelecimento-45`, `categoria-2`, `subcategoria-8`), `tipo`, `id`, `nome`, `nome_exibicao` (loja se houver), `compras`, `ocorrencias`, `valor_total`, `ticket_medio`, `percentual_gasto`, `percentual_compras`, `variacao_*`, `frequencia`, `frase_frequencia`, `frase_gasto`, `frase_evolucao`, `atalho`.

`atalho`: `{ rota, id, query }` — `lojas` / `estabelecimentos` / `transacoes` com o mesmo `data_inicio`/`data_fim` (+ `categoria_id` ou `subcategoria_id` quando couber).

### Alerta

| Campo | Obs |
|-------|-----|
| `tipo` | Motivo principal: `frequencia` \| `gasto` \| `concentracao` \| `evolucao` |
| `motivos[]` | Todos os que dispararam |
| `severidade` | `alta` \| `media` \| `baixa` |
| `frase` | Texto para o card — **não reescrever no front** |
| `contexto` | Complemento (equivalente de frequência · valor · variação) |
| `entidade` | Loja / estabelecimento / categoria / subcategoria |
| `metricas` | Números do período |

Regras (resumo):

- Frequência: ≥ 2 compras/mês (mín. 4 no mês, 6 em 3 meses)
- Gasto / concentração: ≥ 12% do total e ≥ R$ 80
- Evolução: +25% vs período anterior e +R$ 80
- Loja com alerta **esconde** as maquininhas daquela loja
- Subcategoria ≥ 70% da categoria **esconde** o alerta da categoria

## Fora de escopo

- Projeção de parcelas futuras (já é `projecao-faturas`)
- Ranking de parceladas em aberto (já é `ranking-parceladas`)
- Confirmar assinatura (já é `assinaturas`)

Service: `App\Services\Dashboard\GastosCriticosService`  
Prompt: [`docs/frontend-prompt-gastos-criticos.md`](../frontend-prompt-gastos-criticos.md)
