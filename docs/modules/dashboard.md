# Especificação — Dashboard

## Rotas

### Resumo

```http
GET /api/v1/dashboard/resumo?ano=2026
GET /api/v1/dashboard/resumo?ano=2026&mes=7
GET /api/v1/dashboard/resumo?ano=2026&mes_inicio=3&mes_fim=6
```

Recorte **sempre dentro do mesmo ano** (competência da fatura). Três modos:

| Query | Recorte | `periodo.tipo` |
|-------|---------|----------------|
| `ano` | Ano todo | `ano` |
| `ano` + `mes` | Um mês | `mes` |
| `ano` + `mes_inicio` + `mes_fim` | Intervalo inclusivo | `intervalo` |

- `ano` (default: ano atual)
- `mes` (opcional; 1–12). Se omitido (e sem intervalo), consolida o ano
- `mes_inicio` / `mes_fim` (opcional; 1–12). Intervalo no mesmo ano. Se só um vier, o outro assume 1 ou 12. `mes_fim` ≥ `mes_inicio`. Tem precedência sobre `mes`
- `mes_inicio=mes_fim` vira `tipo: mes`. `1`–`12` vira `tipo: ano`
- 422 se mês fora de 1–12, ano inválido ou `mes_fim` < `mes_inicio`

Prompt do front: [`docs/frontend-prompt-dashboard.md`](../frontend-prompt-dashboard.md)

### Projeção de faturas

```http
GET /api/v1/dashboard/projecao-faturas?mes=7&ano=2026
```

- `mes` / `ano`: mês de referência (default: atual)
- Retorna matriz de **13 meses** (mês anterior + 12 à frente)
- Três visões: **por cartão**, **por responsável** e **por cartão × responsável**
- Parcelas futuras projetadas só quando ainda **não** há linha materializada (legado / casos sem `compra_grupo_id`). Compras manuais e import PDF parcelado materializam N transações (`compra_grupo_id`) e entram na projeção como **realizado**.
- Evita duplicidade: parcelas já registradas (mesmo com valor da última parcela diferente por centavos) não são projetadas de novo
- Mês com fatura `processada`: cartão usa `valor_total`; responsável / cruzamento usam soma de compras

### Ranking de compras parceladas

```http
GET /api/v1/dashboard/ranking-parceladas?mes=8&ano=2026
```

- `mes` / `ano`: competência de referência (default: atual) — **centro** da janela de 13 meses
- Lista compras com `compra_grupo_id` e `parcelas_total > 1`, agrupadas por compra
- Default: só compras **ativas na referência** (`apenas_abertas=1`): competência da última parcela **≥** mês/ano da query (última parcela no mês atual ainda aparece; no mês anterior some)
- Ordenação fixa: **menor `percentual_pago` no topo**; `quitada` (100%) no final. Campo `ordenar_aplicada`. Outros `ordenar` (ex. `restantes_desc`) são ignorados; só `percentual_desc` é aceito como alternativa
- Filtros opcionais: `cartao_id`, `responsavel_id`, `categoria_id`, `palavra_chave`, `ordenar`, `apenas_abertas`
- Título: `observacoes` se preenchida; senão nome do estabelecimento
- Pago / aberto / % calculados pela competência da fatura de cada parcela vs referência
- `colunas[]`: 13 competências (6 antes + centro + 6 depois) para visão timeline no front
- Por item: `primeira_parcela`, `ultima_parcela`, `competencia_atual`, `estimativa_termino`, `quitada`, `timeline` (índices na janela para barra cinza início→fim e azul progresso)
- Clique no item (front) abre `GET /api/v1/transacoes/visualizar/{compra_grupo_id}` — ver [`docs/frontend-prompt-visualizacao-compra.md`](../frontend-prompt-visualizacao-compra.md)

### Gastos críticos

```http
GET /api/v1/dashboard/gastos-criticos?meses=3
```

- Responde **“Onde estou gastando demais?”** — loja/estabelecimento, frequência, evolução, categoria **e** subcategoria
- `meses`: `1` \| `3` (default) \| `6` \| `12` (janela pela **data da compra**). Alternativa: `data_inicio`/`data_fim` ou `mes`+`ano`
- `destaques.maior_gasto` / `destaques.mais_comprado` — frases prontas (ex. “Você comprou 18 vezes neste estabelecimento nos últimos 3 meses.”)
- `alertas[]` — pontos críticos (frequência, gasto, concentração, evolução)
- `maiores_gastos` / `mais_comprados` — top 8 em lojas, estabelecimentos, categorias, subcategorias
- `evolucao.por_mes[]` — série da janela (`parcial` no mês corrente)
- Spec: [`docs/modules/gastos-criticos.md`](gastos-criticos.md) · Prompt: [`docs/frontend-prompt-gastos-criticos.md`](../frontend-prompt-gastos-criticos.md)

### Gastos por categoria

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

- Página **dedicada**: duas pizzas (top 10) — **categoria mestre** e **subcategoria escrava**, filtro cruzado no cliente (estilo Power BI)
- Recorte pela **data da compra** — mesmos filtros de período de gastos críticos (`meses`, `data_inicio`/`data_fim`, `mes`+`ano`)
- `dashboards` + `subcategorias[]` (lista plana com `categoria_id`) para o clique filtrar sem novo GET
- Filtros opcionais de faixa: `cartao_id`, `responsavel_id`, `origem_compra`
- Spec: [`docs/modules/gastos-por-categoria.md`](gastos-por-categoria.md) · Prompt: [`docs/frontend-prompt-gastos-por-categoria.md`](../frontend-prompt-gastos-por-categoria.md)

### Raio-X Financeiro

```http
GET /api/v1/dashboard/raio-x?mes=8&ano=2026
```

- `mes` / `ano`: competência de referência (default: atual)
- Leitura interpretada: 3 sinais 🟢🟡🔴 + 1 problema principal + frase de projeção
- Frases prontas — o front não recalcula % nem BRL
- Sem `renda_mensal` no perfil, o sinal de comprometimento vem `incompleto`
- Spec: [`docs/modules/raio-x.md`](raio-x.md) · Prompt: [`docs/frontend-prompt-raio-x.md`](../frontend-prompt-raio-x.md)

## Resposta resumo (`data`)

- `periodo` — recorte aplicado
  - `ano`, `mes` (null se não for um mês só), `mes_inicio` / `mes_fim` (null no ano todo)
  - `tipo`: `ano` \| `mes` \| `intervalo`
  - `label`: `2026` · `Julho 2026` · `Março – Junho 2026`
  - `meses[]`: números do recorte (1–12 no ano todo)
- `totais` — compras, pagamentos, estornos, antecipações, encargos (`fee`), líquido, qtd **do recorte**
  - totais por tipo vêm das `transacoes`
  - `total_liquido` = soma de `faturas.valor_total` do período (mesmo saldo rolante das faturas cadastradas)
- `por_mes` — série mensal do **ano inteiro** (`SUM(faturas.valor_total)` por mês), mesmo com filtro de mês/intervalo — o front destaca `periodo.meses`
- `por_categoria` / `por_responsavel` — apenas compras do recorte
  - `por_categoria` usa `transacoes.categoria_id` (categoria da compra)
- `por_cartao` — `SUM(faturas.valor_total)` por cartão no recorte
- `por_tipo` — soma por tipo de transação no recorte

## Resposta projeção (`data`)

- `referencia` — mês/ano base
- `colunas` — 13 períodos com `label`, `chave`, `referencia`
- `responsavel_eu_id` — id do responsável `"Eu"` (nullable)
- `por_cartao[]` — linha por cartão ativo; inclui `limite_credito`, `uso_limite` (snapshot do mês referência), `resumo_eu_outros[]`, `valores[]`
- `por_responsavel[]` — linha por responsável ativo; `eh_eu`; células com `percentual_participacao`
- `resumo_eu_outros[]` — Eu vs Outros alinhado às colunas (visão global por responsável)
- `por_cartao_responsavel[]` — cartão com `por_responsavel[]` aninhado + `uso_limite` + `resumo_eu_outros[]`
- `totais_por_coluna[]` — soma por mês (cartões e responsáveis)
- Célula base: `{ realizado, projetado, total, fonte }`
- Células por cartão / cartão×responsável acrescentam: `{ em_uso, livre, percentual_utilizado, percentual_livre, disponivel, meu, outros }` (`null` nos campos de limite se sem `limite_credito`)
- `uso_limite`: `{ limite, em_uso, percentual_em_uso, livre, percentual_livre, meu, outros }` — mês de referência
- `meu` / `outros`: `{ realizado, projetado, total, percentual, percentual_do_limite }` — `percentual` = fatia do gasto; `percentual_do_limite` só quando há limite no contexto do cartão

## Resposta ranking parceladas (`data`)

- `referencia` — mês/ano base (centro da janela)
- `colunas[]` — 13 competências `{ mes, ano, chave, label, centro, indice }`
- `totais` — `{ compras, valor_total, valor_pago, valor_aberto, percentual_pago }`
- `itens[]` — uma entrada por `compra_grupo_id` com:
  - `titulo` / `titulo_origem` (`observacoes` | `estabelecimento`)
  - `parcela_atual`, `parcelas_total`, `parcelas_pagas`, `parcelas_restantes`
  - `valor_pago`, `valor_aberto`, `valor_total`, `percentual_pago`, `valor_parcela`
  - `quitada` — `true` se 100% na referência (**sempre ordenada no final**)
  - `estimativa_termino` — label da última parcela (ex.: `Jul/2027`)
  - `primeira_parcela` / `ultima_parcela` / `competencia_atual` / `proxima_parcela`
  - `timeline` — `{ inicio_chave, fim_chave, progresso_chave, indice_inicio, indice_fim, indice_progresso, fora_da_janela }`
  - metadados: estabelecimento, categoria, responsável, cartão/bandeira, `origem_compra`

Todas as agregações filtradas pelo `user_id` autenticado.

Ver também:
- [`docs/frontend-prompt-dashboard.md`](../frontend-prompt-dashboard.md) — resumo: selects de ano/mês + intervalo
- [`docs/frontend-prompt-projecao-faturas.md`](../frontend-prompt-projecao-faturas.md)
- [`docs/frontend-prompt-simulador-compra.md`](../frontend-prompt-simulador-compra.md) — overlay da Projeção (“e se eu comprar X em Nx?”); endpoint `POST /dashboard/simular-compra` ainda não existe
- [`docs/frontend-prompt-posso-comprar.md`](../frontend-prompt-posso-comprar.md) — veredito 🟢 baixo / 🟡 moderado / 🔴 compromete demais, **na mesma tela** `/simulador` (cálculo no cliente após o overlay)
- [`docs/frontend-prompt-ranking-parceladas.md`](../frontend-prompt-ranking-parceladas.md)
- [`docs/frontend-prompt-gastos-criticos.md`](../frontend-prompt-gastos-criticos.md)
- [`docs/frontend-prompt-gastos-por-categoria.md`](../frontend-prompt-gastos-por-categoria.md)
- [`docs/frontend-prompt-raio-x.md`](../frontend-prompt-raio-x.md)
- [`docs/frontend-prompt-visualizacao-compra.md`](../frontend-prompt-visualizacao-compra.md)
