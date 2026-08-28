# Prompt — Frontend: Gastos por categoria (duas pizzas reativas)

Use este prompt no repositório do frontend para criar a tela **Gastos por categoria**.

Backend já implementado. **Ponto-chave do produto:** dois gráficos de **pizza** na primeira dobra, ligados como no Power BI.

1. **Pizza de categorias** (mestre)
2. **Pizza de subcategorias** (escrava — **segue o clique** da primeira)

Esses dois gráficos **não são opcionais**. Sem eles a tela está incompleta. Não substituir por barras, ranking, lista de cards nem pelo pizza do dashboard resumo.

Clicar numa fatia **não navega** e **não chama a API de novo**. A outra pizza, os KPIs e os tipos de compra se ajustam no cliente. Um GET só quando muda período / cartão / responsável / origem.

Spec: [`docs/modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md).

---

## Objetivo

A tela responde, no período:

1. **Como o gasto se reparte entre categorias?** — pizza mestre
2. **E entre subcategorias?** — pizza escrava (todas, ou só da categoria clicada)
3. Os demais blocos **acompanham a fatia selecionada**

Não confundir com:

| Tela | Papel |
|------|-------|
| Dashboard / resumo | **Uma** pizza plana por competência da fatura — **não reusar esse componente como esta tela** |
| Gastos críticos | Lugar, frequência, alertas |
| Esta tela | **Duas pizzas** lado a lado: categoria → subcategoria, reativas |

---

## Menu / rota

**Gastos por categoria**  
Rota: `/gastos-por-categoria`

Menu visível. Deep-link: `/gastos-por-categoria?meses=3` · `/gastos-por-categoria?ano=2026&mes=8` · `/gastos-por-categoria?data_inicio=2026-01-01&data_fim=2026-12-31`.

Query **da página** (estado de seleção, não filtro duro da API):

| Param da rota | Efeito |
|---------------|--------|
| `selecao_categoria` | Pré-seleciona uma fatia da pizza mestre **depois** do GET completo |
| `selecao_subcategoria` | Destaca uma fatia da pizza escrava (opcional) |

**Proibido** mandar `categoria_id` no GET da API só porque o usuário clicou numa fatia. Esse param **corta os dados no servidor** e a pizza mestre ficaria com 1 fatia. Clique = estado local.

---

## API (Bearer Sanctum)

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

Envelope: `{ data, status, message }`. **Um fetch por mudança de período/filtro de faixa.** Clique na pizza = zero requests.

### Query (só faixa / recorte global)

| Param | Default | Front |
|-------|---------|-------|
| `meses` | `3` | Atalho **Últimos N meses** (janela até hoje). **Não** é ano calendário |
| `data_inicio` / `data_fim` | — | Ano todo ou intervalo De/Até no mesmo ano |
| `mes` + `ano` | — | Um mês calendário (De = Até). **Não** enviar `meses` junto |
| `cartao_id` | — | Select opcional |
| `responsavel_id` | — | Select opcional |
| `origem_compra` | — | Chip de tipo (filtro **global**, aí sim refetch) |

`categoria_id` na API existe, mas **não usar no clique das pizzas**.

### O que cada pizza usa

| Visual | Fonte | Recorte |
|--------|--------|---------|
| Pizza **categorias** (mestre) | `data.categorias` | `slice(0, data.dashboards.limite)` → **10** fatias + “Outros” se sobrar |
| Pizza **subcategorias** (escrava) | `data.subcategorias` | ver regra do escravo abaixo |
| Snapshot inicial (opcional) | `data.dashboards.categorias` / `data.dashboards.subcategorias` | já vêm com 10; usar no estado **sem seleção** |

`dashboards.limite` = `10`. Não hardcodar se o campo existir.

Fatia = `valor_total`. Percentual no label/tooltip = `percentual_gasto` (não recalcular, salvo o slice “Outros”).

### Shape dos dashboards

```json
{
  "dashboards": {
    "limite": 10,
    "categorias": [
      {
        "chave": "categoria-2",
        "categoria_id": 2,
        "nome": "Alimentação",
        "cor": "#f59e0b",
        "valor_total": 3200.0,
        "compras": 42,
        "percentual_gasto": 25.6,
        "atalho": { "rota": "transacoes", "query": { "categoria_id": "2", "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
      }
    ],
    "subcategorias": [
      {
        "chave": "subcategoria-10",
        "subcategoria_id": 10,
        "nome": "Delivery",
        "categoria_id": 2,
        "categoria_nome": "Alimentação",
        "cor": "#fcd34d",
        "categoria_cor": "#f59e0b",
        "valor_total": 1800.0,
        "compras": 20,
        "percentual_gasto": 14.4,
        "percentual_da_categoria": 56.3,
        "atalho": { "rota": "transacoes", "query": { "subcategoria_id": "10", "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
      }
    ]
  },
  "subcategorias": []
}
```

`data.subcategorias` = **todas** as subcategorias nomeadas do período (não só 10), cada uma com `categoria_id`. Tabela fato da pizza escrava.

`data.categorias[]` traz o detalhe (`por_origem`, `subcategorias` completas, `top_subcategorias`). As pizzas usam `nome`, `cor`, `valor_total`, `percentual_gasto`, ids e `atalho`.

---

## Layout (obrigatório) — primeira dobra

Os **dois gráficos de pizza** são o centro da tela. Sem eles, a implementação está errada.

```
Filtros de período / cartão / responsável

KPIs (total, compras, ticket)              ← reagem à fatia

┌─────────────────────────────┐  ┌─────────────────────────────┐
│  Categorias                 │  │  Subcategorias              │
│  [  PIZZA  ]   legenda      │→ │  [  PIZZA  ]   legenda      │
│  MESTRE                     │  │  ESCRAVA                    │
└─────────────────────────────┘  └─────────────────────────────┘

Tipos de compra                            ← reagem à fatia
Evolução                                   ← reage à fatia
```

Desktop: **duas colunas iguais**, mesma altura, pizzas lado a lado.  
Mobile: pizza de categorias em cima, pizza de subcategorias **logo abaixo** (nunca esconder a escrava numa aba).

Hero (`destaque.frase`) pode ser uma linha acima. **Não substitui** as pizzas.

---

## Gráfico: pizza (os dois iguais)

Lib: a mesma já usada no app (Chart.js `pie`/`doughnut`, Recharts `PieChart`, Apex). Se o resumo já tem pizza, **replicar o visual** — mas são **dois** gráficos nesta página, com dados e clique diferentes.

Tipo: **doughnut** (rosca) preferível à pizza cheia — o centro mostra o total da seleção (`valor_total` formatado em BRL). Aceitável pizza cheia se o design system não tiver rosca.

### Fatias

- Até **10** fatias nomeadas (`dashboards.limite`).
- Se houver mais itens na fonte, **uma fatia “Outros”** (cinza) com a soma do restante, para a pizza fechar 100%. “Outros” **não é clicável** (não vira seleção).
- Se houver 10 ou menos, **não** criar “Outros”.
- Ângulo da fatia = `valor_total`.
- Cor da pizza de **categoria** = `cor` salva no cadastro. Cadastrada sem cor → **preto** `#000000`. Bucket “Sem categoria” → `#9ca3af`. **Não** usar paleta default da lib.
- Cor da pizza de **subcategoria** = `cor` da própria sub (tonalidade **mais clara** que o tema da pai). Enquanto a API não mandar `cor` (antes da etapa 2 de [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md)), fallback `categoria_cor`. **Não** inventar rainbow.
- Fatia “Outros” → `#d1d5db`.
- Ordem das fatias = ordem da API (maior gasto primeiro).

### Labels / legenda

- Legenda ao lado (desktop) ou abaixo (mobile): bolinha + `nome` + `percentual_gasto` + valor.
- Tooltip na fatia: `nome`, BRL de `valor_total`, `percentual_gasto`, `compras`.
- Tooltip da sub: também `categoria_nome` e `percentual_da_categoria` (“56,3% de Alimentação”).
- Centro da rosca:
  - sem seleção → `totais.valor_total`
  - categoria selecionada → gasto dessa categoria
  - sub selecionada → gasto dessa sub
- Clique na fatia **e** na linha da legenda = a mesma seleção. Cursor pointer.

### Títulos

| Visual | Sem seleção | Com categoria selecionada |
|--------|-------------|---------------------------|
| Mestre | **Categorias** | **Categorias** (inalterado) |
| Escrava | **Subcategorias** | **Subcategorias de {nome}** |

Chip **Limpar filtro** visível só com seleção ativa.

**Proibido:** barras horizontais, barras verticais, treemap, uma pizza só, tabela no lugar do gráfico.

---

## Interação reativa (não negociar)

Estado local (não é query da API):

```ts
selecao = {
  categoria_id: number | null,       // pizza mestre
  subcategoria_id: number | null,    // destaque na pizza escrava
}
```

Bucket “Sem categoria”: `categoria_id == null` e `chave === "categoria-0"`.

### Clique na pizza de categorias (mestre)

1. Clicou a **mesma** fatia já selecionada → **limpa** tudo (toggle).
2. Senão → `selecao.categoria_id = fatia.categoria_id`, `selecao.subcategoria_id = null`.
3. **Não refetch.**
4. Pizza mestre: fatia selecionada em destaque (explode 6–8px **ou** as outras em ~40% de opacidade). **Todas as fatias continuam no gráfico** — não sumir categoria.
5. Pizza escrava **recalcula as fatias**:

```
base = data.subcategorias.filter(s => s.categoria_id === selecao.categoria_id)
fatias = top N (dashboards.limite) de base
         + “Outros” se sobrar
```

Os percentuais **visuais** da pizza escrava passam a ser vs o total **da categoria** (`percentual_da_categoria`). O tooltip usa esse % (“fatia de Alimentação”). Não usar `percentual_gasto` global depois do filtro.

6. KPIs → números da categoria (`categorias.find`).
7. Tipos de compra → `categoria.por_origem`.
8. Evolução → `evolucao.por_categoria` daquela categoria se existir.
9. Título da escrava: “Subcategorias de Alimentação”. 3 subs → 3 fatias (sem vazios).

### Clique na pizza de subcategorias (escrava)

A escrava **não recorta** a pizza mestre: as fatias de categoria **permanecem**.

1. Toggle da fatia de sub (`selecao.subcategoria_id`).
2. Se ainda não houver categoria selecionada: setar `selecao.categoria_id` da pai **e** destacar a sub. A pizza mestre só **ilumina** a categoria pai.
3. KPIs → números da **subcategoria**.
4. Tipos → `por_origem` da categoria pai (a API não quebra origem por sub).
5. Duplo clique **ou** “Ver compras” no tooltip → `atalho`. Clique simples **não** sai da tela.

### Clique no vazio / Limpar filtro

`selecao = { categoria_id: null, subcategoria_id: null }`

- Escrava volta ao top 10 global (`data.dashboards.subcategorias` ou `data.subcategorias.slice(0, limite)` + Outros)
- KPIs → `data.totais`
- Tipos → `data.por_origem`
- Nenhuma fatia destacada

### Clique em tipo de compra

Pode refetch (`?origem_compra=`): é filtro de faixa, não cross-filter das pizzas. Ao refetch, zerar `selecao`.

---

## KPIs

Três números da **seleção atual**:

| KPI | Sem seleção | Categoria | Subcategoria |
|-----|-------------|-----------|--------------|
| Gasto | `totais.valor_total` | `categoria.valor_total` | `sub.valor_total` |
| Compras | `totais.compras` | `categoria.compras` | `sub.compras` |
| Ticket | `totais.ticket_medio` | `categoria.ticket_medio` | `sub.ticket_medio` |

Label: “No período” / “Em Alimentação” / “Em Delivery”.  
`variacao_valor_percentual === null` → chip “Novo”, nunca 0%.

---

## Tipos de compra e evolução

**Abaixo** das duas pizzas — não no lugar delas.

- Tipos: chips/barras com `label` + `percentual_gasto`. Seguem a seleção (global vs `categoria.por_origem`).
- Evolução: `evolucao.por_mes`. Mês `parcial: true` → “mês em andamento”. Com categoria selecionada, usar `evolucao.por_categoria[]`.

---

## Filtros de faixa (topo)

**Não** usar chips `1 / 3 / 6 / 12` sem texto. UX obrigatória: [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md) item 4.

- Selects **Ano / De / Até** (igual Dashboard). De “Ano todo (jan – dez)” → `data_inicio`/`data_fim` daquele ano. De = Até → `mes`+`ano`. Intervalo no mesmo ano → `data_inicio`/`data_fim`.
- Atalhos **Último mês / Últimos 3 / 6 / 12 meses** → `?meses=` (janela até hoje). “12 meses” ≠ jan–dez.
- Subtítulo: `periodo.label` **e** `periodo.inicio` → `periodo.fim`.
- Cartão / responsável → refetch e zera `selecao`.
- Não misturar `meses` com `mes`+`ano` nem com datas.

`localStorage`: modo rolling vs calendário. Não persistir a fatia clicada.

---

## Navegação (`atalho`)

Só no **duplo clique**, ícone do tooltip ou “Ver compras”.

| `atalho.rota` | Destino |
|---------------|---------|
| `transacoes` | Listagem com a `query` (datas + `categoria_id` ou `subcategoria_id`) |

Clique simples na fatia = filtro cruzado. Não abrir a listagem.

---

## Empty / loading / erro

- `totais.compras === 0`: empty da página
- Pizza escrava sem fatias após filtrar: “Nenhuma subcategoria nesta categoria” (a pizza mestre continua)
- Loading: **dois** skeletons de pizza lado a lado
- 422/500: `message`

---

## Regras de UI (não negociar)

- **Duas pizzas** (doughnut) na primeira dobra: categorias | subcategorias
- Pizza de subcategorias é **escrava** da de categorias
- Clique **filtra os outros visuais no cliente** — sem GET
- **Não** mandar `categoria_id` na API por causa do clique
- **Não** barras no lugar das pizzas
- **Não** uma pizza só (isso é o resumo)
- **Não** esconder a pizza escrava
- Moeda BRL; % com 1 casa como veio
- Mobile: empilhar as duas pizzas, mesma interação

---

## Critérios de aceite

- [ ] Duas pizzas visíveis na carga (categoria e subcategoria), lado a lado no desktop
- [ ] Clique numa fatia de categoria: a pizza de subs passa a mostrar as subs **daquela** categoria; KPIs e tipos acompanham; a pizza mestre só destaca a fatia
- [ ] Clique de novo na mesma fatia: limpa (toggle)
- [ ] Clique numa fatia de sub: destaca; não recorta a pizza de categorias
- [ ] Limpar filtro restaura a pizza de subs global
- [ ] Zero request no clique das pizzas
- [ ] Duplo clique / “Ver compras” usa `atalho`
- [ ] Chips de período refetch e zeram seleção
- [ ] Empty / loading (2 pizzas) / responsivo

---

## Fora de escopo

- Editar categoria/sub nesta tela
- Alertas de gastos críticos
- Gráfico de loja/estabelecimento
- Recalcular o total somando fatias (usar os campos da API)

---

Spec: [`docs/modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md)  
Cores tema (quadrados + sub + default preto): [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md)  
Gastos críticos (não copiar): [`frontend-prompt-gastos-criticos.md`](frontend-prompt-gastos-criticos.md)  
Compras (só no atalho): [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
