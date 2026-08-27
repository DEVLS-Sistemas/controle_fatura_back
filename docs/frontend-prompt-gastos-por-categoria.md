# Prompt — Frontend: Gastos por categoria

Use este prompt no repositório do frontend para criar a tela **Gastos por categoria** alinhada à API do `controle_fatura_back`.

Backend já implementado. **Ponto-chave do produto:** página **dedicada** — lista completa de categorias, cada uma com as **duas subcategorias** que mais pesam, mais um recorte por **tipo de compra** (online, presencial, serviços, fatura). Não é widget do resumo e não é gastos críticos.

Spec: [`docs/modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md).

---

## Objetivo

A tela responde, no período escolhido:

1. **Qual categoria mais come o orçamento?** (hero com frase pronta)
2. **Quais as 2 subcategorias** dessa categoria — e de cada uma na lista
3. **Como eu compro?** (online × presencial × serviços × pagamento de fatura)

Não confundir com:

| Tela | Recorte | Papel |
|------|---------|-------|
| Dashboard / resumo | Competência da fatura | Totais e pizza **plana** de categoria |
| Gastos críticos | Data da compra | Lugar, frequência, alertas — top 8, 4 dimensões |
| Esta tela | Data da compra | **Árvore** categoria → 2 subs + tipos de compra |

---

## Menu / rota

**Gastos por categoria** (ou **Categorias** no grupo Dashboard).

Rota: `/gastos-por-categoria` (ou `/dashboard/gastos-por-categoria`)

Item de menu **visível**. Deep-link: `/gastos-por-categoria?meses=3` · `/gastos-por-categoria?mes=8&ano=2026`.

Não enterrar só no dashboard resumo. Clique no gráfico `por_categoria` do resumo **pode** apontar para cá, mas a tela existe sozinha.

---

## API (Bearer Sanctum)

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

Envelope: `{ data, status, message }`.

### Query

| Param | Default | Uso no front |
|-------|---------|--------------|
| `meses` | `3` | Segmented control: 1 / 3 / 6 / 12 |
| `data_inicio` / `data_fim` | — | Intervalo custom (opcional, avançado) |
| `mes` + `ano` | — | Um mês calendário — **não** enviar `meses` junto |
| `cartao_id` | — | Filtro opcional |
| `responsavel_id` | — | Filtro opcional |
| `categoria_id` | — | Drill-down opcional (uma categoria) |
| `origem_compra` | — | Chip de tipo: `COMPRAS_ONLINE`, `COMPRAS_PRESENCIAL`, `PAGAMENTO_SERVICOS`, `PAGAMENTO_FATURA` |

Ao mudar período ou filtro, refetch. Persistência sugerida: `localStorage` `gastos_por_categoria_meses = 3`.

422: exibir `message`.

### Shape resumido (`data`)

```json
{
  "periodo": {
    "inicio": "2026-05-24",
    "fim": "2026-08-24",
    "meses": 3,
    "dias": 93,
    "origem": "janela",
    "label": "Últimos 3 meses",
    "label_frase": "nos últimos 3 meses"
  },
  "periodo_anterior": {
    "inicio": "2026-02-24",
    "fim": "2026-05-23",
    "label": "3 meses anteriores"
  },
  "totais": {
    "valor_total": 12500.0,
    "compras": 87,
    "ocorrencias": 94,
    "ticket_medio": 143.68,
    "categorias_com_gasto": 8,
    "variacao_valor_percentual": 19.0,
    "frequencia": { "label": "1 vez a cada 1 dia", "por_mes": 28.0 },
    "sem_categoria": { "valor_total": 200.0, "compras": 5, "percentual_gasto": 1.6 }
  },
  "destaque": {
    "categoria": {
      "categoria_id": 2,
      "nome": "Alimentação",
      "cor": "#f59e0b",
      "valor_total": 3200.0,
      "compras": 42,
      "percentual_gasto": 25.6,
      "variacao_valor_percentual": 12.3,
      "atalho": { "rota": "transacoes", "id": 2, "query": { "data_inicio": "2026-05-24", "data_fim": "2026-08-24", "categoria_id": "2" } }
    },
    "subcategorias": [
      { "subcategoria_id": 10, "nome": "Delivery", "valor_total": 1800.0, "percentual_da_categoria": 56.3 },
      { "subcategoria_id": 11, "nome": "Supermercado", "valor_total": 1000.0, "percentual_da_categoria": 31.3 }
    ],
    "frase": "Você mais gastou em Alimentação nos últimos 3 meses: R$ 3.200,00 (25,6% do total). As duas maiores fatias são Delivery e Supermercado."
  },
  "categorias": [
    {
      "chave": "categoria-2",
      "categoria_id": 2,
      "nome": "Alimentação",
      "cor": "#f59e0b",
      "valor_total": 3200.0,
      "compras": 42,
      "percentual_gasto": 25.6,
      "variacao_valor_percentual": 12.3,
      "frase": "Você gastou R$ 3.200,00 em Alimentação nos últimos 3 meses — 25,6% do total. Destaques: Delivery e Supermercado.",
      "subcategorias_total": 5,
      "top_subcategorias": [
        {
          "subcategoria_id": 10,
          "nome": "Delivery",
          "valor_total": 1800.0,
          "compras": 20,
          "percentual_da_categoria": 56.3,
          "atalho": { "rota": "transacoes", "query": { "subcategoria_id": "10", "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
        },
        {
          "subcategoria_id": 11,
          "nome": "Supermercado",
          "valor_total": 1000.0,
          "percentual_da_categoria": 31.3
        }
      ],
      "outras_subcategorias": { "quantidade": 3, "valor_total": 400.0, "compras": 8, "percentual_da_categoria": 12.5 },
      "sem_subcategoria": { "valor_total": 0.0, "compras": 0, "percentual_da_categoria": 0.0 },
      "por_origem": [
        { "origem_compra": "COMPRAS_ONLINE", "label": "Compras online", "valor_total": 2100.0, "percentual_gasto": 65.6 }
      ],
      "atalho": { "rota": "transacoes", "query": { "categoria_id": "2", "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
    }
  ],
  "por_origem": [
    { "origem_compra": "COMPRAS_PRESENCIAL", "label": "Compras presencial", "valor_total": 7000.0, "percentual_gasto": 56.0, "frase": "Você gastou R$ 7.000,00 em compras presencial nos últimos 3 meses — 56% do total." }
  ],
  "evolucao": {
    "por_mes": [{ "chave": "2026-06", "label": "Jun/2026", "valor_total": 4000.0, "compras": 30, "parcial": false }],
    "por_categoria": [
      { "categoria_id": 2, "nome": "Alimentação", "cor": "#f59e0b", "serie": [{ "chave": "2026-06", "valor_total": 900.0, "compras": 12 }] }
    ]
  }
}
```

Campos omitidos no JSON acima existem na API (ticket, frequência, atalho completo). Usar o payload real.

---

## Layout (obrigatório)

Três blocos, nesta ordem, com ar entre eles:

```
1. Hero          → frase da categoria nº 1 + 2 cards de subcategoria
2. Tipos         → barras/chips de origem_compra
3. Lista         → todas as categorias; cada linha mostra as 2 subs
4. Evolução      → gráfico (opcional abaixo da lista)
```

### 1) Hero

Subtítulo do header: `periodo.label` · `totais.valor_total` · variação vs `periodo_anterior.label`.

Card grande:

- Bolinha `destaque.categoria.cor`
- **Frase** = `destaque.frase` (corpo principal — **não reescrever**, não montar BRL no cliente)
- Dois cards lado a lado = `destaque.subcategorias` (0, 1 ou 2):
  - Nome
  - `valor_total`
  - `% da categoria` = `percentual_da_categoria`
- Clique no hero → `destaque.categoria.atalho`
- Clique numa sub → `sub.atalho`

Se `destaque === null`: não renderizar o hero (empty geral cobre).

Variação: `variacao_valor_percentual` — `+12%` / `-8%` / chip **Novo** se `null`.

### 2) Tipos de compra

Título: **Como você compra**

Usar `data.por_origem[]` (já ordenado por valor):

- Barra empilhada **ou** 4 cards (online, presencial, serviços, fatura)
- Label = `label` (não traduzir o enum)
- Valor + `percentual_gasto`
- Frase opcional no tooltip = `frase`
- Clique → `atalho` (listagem de compras com `origem_compra`)

`origem_compra === null` (**Sem origem**): card discreto / cinza. Não inventar tipo.

Filtro: clicar num tipo pode setar `?origem_compra=` e refetch (a lista de categorias passa a ser só daquele canal). Chip “Todos” limpa o param.

### 3) Lista de categorias

Título: **Categorias**  
Subtítulo: “Cada categoria mostra as duas subcategorias que mais pesam.”

**Todas** as linhas de `categorias[]` (não truncar no front). Ordenação da API.

Cada card/linha:

```
● Alimentação                         R$ 3.200   25,6%
  Delivery ████████ 56%     Supermercado ████ 31%
  +3 outras · R$ 400
```

- Bolinha `cor` (cinza se `cor === null` — “Sem categoria”)
- Nome · `valor_total` · `percentual_gasto` (barra vs total)
- **Sempre** as `top_subcategorias` (duas barras menores **dentro da categoria**, % = `percentual_da_categoria`)
- Se só 1 sub: mostrar 1. Se 0: texto “Sem subcategorias neste período”
- `outras_subcategorias.quantidade > 0`: linha discreta “+N outras · R$ …”
- `sem_subcategoria.valor_total > 0`: chip “Sem subcategoria R$ …”
- Variação vs período anterior
- Clique na categoria → `atalho` (compras filtradas)
- Clique na sub → `top_subcategorias[i].atalho`

Não expandir para listar todas as subs nesta tela: o contrato é **duas**. O resto cai no “+N outras” e no clique para a listagem.

“Sem categoria” (`categoria_id === null`) fica **no fim visualmente só se a API mandar** — **não reordenar**. A API já manda por valor; se “Sem categoria” for a maior, ela aparece no topo.

### 4) Evolução

Gráfico de barras/linha com `evolucao.por_mes[]` (eixo X = `label`, valor = `valor_total`).

Série extra (linhas coloridas): `evolucao.por_categoria[]` — usar `cor` e `nome`. Cada ponto = `serie[].valor_total` alinhado por `chave`.

Mês com `parcial: true`: legenda “mês em andamento”.

---

## Filtros (faixa no topo)

Mesmo padrão de gastos críticos:

- Chips **1 / 3 / 6 / 12 meses** → `?meses=`
- Selects opcionais: cartão, responsável (lookups já existentes)
- Select de categoria (lookups de categorias) → `categoria_id` (a tela vira drill-down de uma só)

Não misturar `meses` com `mes`+`ano`. Mês calendário: selects iguais às outras telas, **sem** `meses`.

---

## Navegação (`atalho`)

| `atalho.rota` | Destino |
|---------------|---------|
| `transacoes` | Listagem de compras com a `query` (datas + `categoria_id` / `subcategoria_id` / `origem_compra`) |

Reusar a tela de compras. Não inventar um terceiro detalhe.

Do resumo (`por_categoria`): CTA “Ver por categoria e subcategoria” → esta rota.

---

## Empty / loading / erro

- `totais.compras === 0`: ilustração + “Sem compras neste período. Importe uma fatura ou altere o filtro.”
- Loading: skeleton do hero + 4 linhas
- Erro 422/500: `message` da API
- `totais.sem_categoria.percentual_gasto` alto (> 20%): aviso discreto “Há compras sem categoria — classifique na listagem” + atalho com `categoria_id` omitido e datas. Não bloquear a tela.

---

## Regras de UI (não negociar)

- **Página dedicada.** Não é aba escondida no resumo
- **Sempre 2 subcategorias** por categoria (ou menos se não houver). Não listar 8
- **Não** recalcular frases, BRL ou `frequencia.label`
- **Não** tratar `variacao_* === null` como 0% (é “não havia base”)
- **Não** misturar competência da fatura com esta tela
- **Não** copiar o layout de gastos críticos (sem alertas, sem ranking de loja)
- Moeda BRL; % com 1 casa como veio
- Respeitar a ordem de `categorias` e `por_origem`
- Mobile: hero empilhado; as 2 subs uma embaixo da outra; lista em cards

---

## Critérios de aceite

- [ ] Menu próprio + rota dedicada
- [ ] Hero com frase da API + **dois** cards de subcategoria
- [ ] Lista de **todas** as categorias, cada uma com até 2 subs e “+N outras”
- [ ] Bloco de tipos de compra (`por_origem`) com labels da API
- [ ] Chips 1 / 3 / 6 / 12 meses refetch `?meses=`
- [ ] Clique usa `atalho` (compras filtradas por categoria / sub / origem)
- [ ] Gráfico de evolução; mês `parcial` identificado
- [ ] Empty / loading / erro / responsivo

---

## Fora de escopo

- Editar categoria/subcategoria nesta tela
- Confirmar assinatura
- Alertas “você está gastando demais” (usar `/gastos-criticos`)
- Pizza única sem subcategorias (isso é o resumo)

---

Spec: [`docs/modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md)  
Gastos críticos (não copiar): [`frontend-prompt-gastos-criticos.md`](frontend-prompt-gastos-criticos.md)  
Compras (destino do clique): [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
