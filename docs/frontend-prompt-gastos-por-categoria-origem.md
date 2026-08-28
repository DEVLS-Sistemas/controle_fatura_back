# Prompt — Frontend: doughnut de Origem em Gastos por categoria

Use este prompt no repositório do **frontend**. Back **já implementado** — não precisa de endpoint novo.

Tela: **Gastos por categoria** (`/gastos-por-categoria`).  
O gráfico é uma **rosca** (doughnut: círculo com **buraco no meio**). Não é pizza cheia, não é barra, não é só chip.

Spec da tela: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md)  
API: [`modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md)

---

## Objetivo

Na mesma tela das duas pizzas (categoria → subcategoria), acrescentar um **terceiro** gráfico:

**Origem da compra** — como o valor se reparte entre online, presencial, pagamento de serviços e pagamento de fatura, **respeitando os filtros da tela** (período, cartão, responsável) e a **fatia de categoria** já selecionada.

Responde: *“Desse recorte, quanto foi online vs presencial vs serviços vs fatura?”*

---

## Nome e tipo

| Termo | Usar |
|-------|------|
| Tipo Chart.js / Recharts / Apex | **doughnut** / `innerRadius` > 0 |
| Nome na UI | **Origem** (título do card). Subtítulo opcional: “Tipo de compra” |
| Não chamar | pizza, pie cheia, donut em inglês na label |

O **buraco é obrigatório**. Centro = total em BRL do recorte atual.

A lib e o visual devem ser **os mesmos** das pizzas de categoria/sub (mesma altura, mesma legenda). Só mudam os dados.

---

## API (já existe)

O GET é o mesmo da tela:

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

(+ `data_inicio`/`data_fim`, `mes`+`ano`, `cartao_id`, `responsavel_id` — os filtros do topo).

**Não** criar fetch extra. Um GET por mudança de filtro de faixa. Clique na pizza de categoria **não** refetch — a rosca de origem troca de fonte no cliente.

### Fontes

| Estado da tela | Array | % da fatia |
|----------------|-------|------------|
| Nenhuma categoria selecionada | `data.por_origem[]` | `percentual_gasto` (vs total do período) |
| Fatia de **categoria** clicada | `categorias[].por_origem` daquela categoria | `percentual_gasto` / `percentual_da_categoria` (vs total **da categoria**) |
| Fatia de **subcategoria** clicada | Continuar o `por_origem` da **categoria pai** (a API **não** quebra origem por sub) | igual categoria |

Se a query global já tiver `origem_compra`, o array pode vir com **uma** fatia — ainda assim desenhar a rosca.

### Shape de cada item (`por_origem[]`)

```json
{
  "chave": "COMPRAS_ONLINE",
  "origem_compra": "COMPRAS_ONLINE",
  "label": "Compras online",
  "compras": 20,
  "ocorrencias": 22,
  "valor_total": 1800.0,
  "ticket_medio": 90.0,
  "percentual_gasto": 52.9,
  "percentual_compras": 48.0,
  "percentual_da_categoria": 52.9,
  "atalho": {
    "rota": "transacoes",
    "query": {
      "origem_compra": "COMPRAS_ONLINE",
      "data_inicio": "2026-05-24",
      "data_fim": "2026-08-24"
    }
  }
}
```

- `origem_compra: null` + `chave: "sem-origem"` + `label: "Sem origem"` → bucket sem canal.
- Ângulo da fatia = `valor_total`.
- Label visível = `label` da API (**não** o enum cru).
- Tooltip: `label`, BRL, `percentual_gasto` (1 casa), `compras`.
- Ordem = ordem da API (maior valor primeiro). **Não** reordenar.
- Itens com `valor_total === 0` **não** entram na rosca (a API em geral já omite).

Não existe `cor` nesse array. Paleta **fixa por `chave`** (abaixo). **Proibido** `hsl(i * 37, …)` ou default da lib.

---

## Cores (estáveis)

| `chave` / `origem_compra` | Cor |
|---------------------------|-----|
| `COMPRAS_ONLINE` | `#3b82f6` |
| `COMPRAS_PRESENCIAL` | `#22c55e` |
| `PAGAMENTO_SERVICOS` | `#f59e0b` |
| `PAGAMENTO_FATURA` | `#8b5cf6` |
| `sem-origem` / `origem_compra === null` | `#9ca3af` |

Constantes no front. Legenda usa a mesma cor.

---

## Layout (obrigatório)

As **duas pizzas** de categoria/sub **permanecem** na primeira dobra. A rosca de Origem entra **junto** com a de **Plataforma** (2×2). Não no lugar delas e não escondida numa aba.

```
Filtros (período / cartão / responsável)

KPIs

┌──────────────┐  ┌──────────────┐
│ Categorias   │  │ Subcategorias│
│  (rosca)     │→ │  (rosca)     │
│  MESTRE      │  │  ESCRAVA     │
└──────────────┘  └──────────────┘
┌──────────────┐  ┌──────────────┐
│ Origem       │  │ Plataforma   │
│  (rosca)     │  │  (rosca)     │
└──────────────┘  └──────────────┘

Evolução
```

- **Desktop (≥ lg):** 2×2, mesma altura.
- **Tablet:** 2 + 2.
- **Mobile:** empilhar na ordem Categorias → Subcategorias → Origem → Plataforma.

Plataforma: [`frontend-prompt-gastos-por-categoria-plataforma.md`](frontend-prompt-gastos-por-categoria-plataforma.md).

Título do terceiro card:

| Seleção | Título |
|---------|--------|
| Nenhuma | **Origem** |
| Categoria “Alimentação” | **Origem em Alimentação** |
| Sub “Delivery” | **Origem em Alimentação** (pai; não inventar “em Delivery”) |

Chip **Limpar filtro** (já existe para categoria) também restaura a origem para `data.por_origem`.

**Substituir** os chips/barras de “Tipos de compra” **por esta rosca**. Não manter os dois visuais iguais. Uma linha de legenda ao lado/abaixo da rosca basta.

---

## Centro do buraco

Mesma regra das outras roscas, com o total **deste** recorte:

| Seleção | Centro |
|---------|--------|
| Nenhuma | soma das fatias = `totais.valor_total` (ou soma de `data.por_origem[].valor_total`) |
| Categoria | `categoria.valor_total` |
| Sub | ainda o total da **categoria** neste gráfico (origem não é da sub) |

Formatar BRL. Não recalcular % no cliente (usar o campo da API), salvo arredondamento visual.

---

## Filtros da tela (o que a rosca “obedece”)

A rosca **sempre** reflete o último GET:

- período (Ano/De/Até ou últimos N meses)
- `cartao_id`
- `responsavel_id`
- `origem_compra` na query, se o usuário filtrou por tipo no topo

Mais o estado **local** da pizza mestre (categoria), sem novo GET.

Mudar período/cartão/responsável → refetch → zera `selecao` → origem volta ao global `data.por_origem`.

---

## Clique na fatia de Origem

Dois níveis (não misturar):

1. **Clique simples** — destaca a fatia (opacidade nas outras). **Não navega.**
2. **Segundo clique na mesma fatia** — se quiser **filtrar a tela inteira** por aquele tipo: refetch `?origem_compra={enum}`. `origem_compra === null` (“Sem origem”) **não** manda o param (a API não filtra “só nulos” neste query). Ao refetch, zerar seleção de categoria/sub.
3. **Limpar** o filtro de origem (chip no topo ou clique no vazio): tirar `origem_compra` da query e refetch.
4. **Duplo clique** / “Ver compras” → `atalho` (listagem de transações com datas + origem). Clique simples **não** sai da tela.

Não mandar `categoria_id` no GET só porque a pizza de categoria está destacada.

Se o time preferir **zero refetch** no clique da origem (só highlight local), vale — mas aí o filtro global de origem continua só no chip/select do topo, se existir. O mínimo deste prompt é: **desenhar a rosca com os dados certos**. O refetch no clique é desejável, não bloqueante.

---

## Empty / loading

- `por_origem.length === 0`: “Sem origem neste recorte” no card; **não** esconder o card.
- Loading inicial: **quatro** skeletons de rosca (não só dois).
- Uma origem só: uma fatia 100% — ok.

---

## Critérios de aceite

- [ ] Rosca (buraco no meio) **Origem** visível na mesma tela das duas pizzas
- [ ] Fatias = `por_origem` do GET (filtros do topo)
- [ ] Clicar numa categoria: a rosca passa a `categoria.por_origem` **sem** novo GET
- [ ] Centro mostra o total em BRL do recorte
- [ ] Cores fixas por tipo (tabela acima), não rainbow
- [ ] Labels da API (`Compras online`, …), não o enum
- [ ] Desktop: 2×2 (categoria/sub | origem/plataforma); mobile empilhado
- [ ] Chips/barras de “tipos de compra” **não** competem com a rosca (a rosca é o visual)

---

## Fora deste prompt

- Quebrar origem por subcategoria (API não tem)
- Endpoint novo / campo `cor` em `por_origem`
- Trocar as pizzas de categoria/sub
- Gastos críticos / dashboard resumo

---

Checklist da tela completa: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md)  
Período Ano/De/Até: [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md)
