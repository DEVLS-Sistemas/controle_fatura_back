# Prompt — Frontend: Onde estou gastando demais?

Use este prompt no repositório do frontend para criar a tela **Gastos críticos** / **Onde estou gastando demais?** alinhada à API do `controle_fatura_back`.

Backend já implementado. **Ponto-chave do produto:** não é um pizza de categoria. É diagnóstico de **lugar, frequência, evolução e categoria/subcategoria**, com frases prontas.

---

## Objetivo

A tela responde, no período escolhido:

1. **Onde** estou gastando demais? (loja / estabelecimento — não só categoria)
2. **O que** mais custa? (valor)
3. **O que** eu mais compro? (vezes)
4. **Com que frequência?** (“1 vez a cada 5 dias”)
5. **Está piorando?** (vs período anterior + série mensal)

Frase modelo (já vem da API — **renderizar como está**):

> Você comprou **18 vezes** neste estabelecimento nos últimos 3 meses.

---

## Conceito de produto

O `dashboard/resumo` (`por_categoria`) **não substitui** esta tela. Aqui o recorte é a **data da compra**, e o ranking tem **4 dimensões × 2 critérios**:

| Dimensão | O que é |
|----------|---------|
| Loja | Nome fantasia (`Atacadão`, `iFood`) — agrupa maquininhas |
| Estabelecimento | Maquininha (`IFOOD *BK`) |
| Categoria | Categoria **da compra** |
| Subcategoria | Ex.: Delivery dentro de Alimentação |

| Critério | Array na API |
|----------|----------------|
| Mais gasta (R$) | `data.maiores_gastos.*` |
| Mais compra (vezes) | `data.mais_comprados.*` |

Parcelado conta **1 compra**; `ocorrencias` são as linhas na fatura. Não recalcular `frequencia.label` nem as `frase_*`.

---

## Menu / rota

**Gastos críticos** ou **Onde estou gastando demais?**  
Rota: `/gastos-criticos` (ou `/dashboard/gastos-criticos`)

Item de menu visível (não enterrar só no dashboard resumo). Esta é tela principal, não um widget opcional.

---

## API (Bearer Sanctum)

```http
GET /api/v1/dashboard/gastos-criticos?meses=3
```

### Query

| Param | Default | Uso no front |
|-------|---------|--------------|
| `meses` | `3` | Segmented control: 1 / 3 / 6 / 12 |
| `data_inicio` / `data_fim` | — | Intervalo custom (opcional, avançado) |
| `mes` + `ano` | — | Um mês calendário — **não** enviar `meses` junto |
| `cartao_id` | — | Filtro opcional |
| `responsavel_id` | — | Filtro opcional |
| `categoria_id` | — | Recorte opcional |

Ao mudar o período, refetch. Persistência sugerida: `localStorage` `gastos_criticos_meses = 3`.

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
    "valor_anterior": 10500.0,
    "compras_anterior": 70,
    "variacao_valor_percentual": 19.0,
    "variacao_compras_percentual": 24.3,
    "frequencia": { "label": "1 vez a cada 1 dia", "por_mes": 28.0 }
  },
  "destaques": {
    "maior_gasto": {
      "tipo": "gasto",
      "entidade_tipo": "loja",
      "nome": "Atacadão",
      "frase": "O maior gasto nos últimos 3 meses foi em Atacadão: R$ 3.200,00 (25,6% do total).",
      "contexto": "Isso equivale a 1 vez a cada 8 dias · R$ 3.200,00 no período · +18% vs período anterior.",
      "valor_total": 3200.0,
      "compras": 12,
      "percentual_gasto": 25.6,
      "atalho": { "rota": "lojas", "id": 1, "query": { "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
    },
    "mais_comprado": {
      "tipo": "frequencia",
      "entidade_tipo": "estabelecimento",
      "nome": "iFood",
      "frase": "Você comprou 18 vezes neste estabelecimento nos últimos 3 meses.",
      "contexto": "Isso equivale a 1 vez a cada 5 dias · R$ 890,00 no período · +40% vs período anterior.",
      "compras": 18,
      "atalho": { "rota": "estabelecimentos", "id": 45, "query": { "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
    }
  },
  "alertas": [
    {
      "id": "frequencia:loja-3",
      "tipo": "frequencia",
      "motivos": ["frequencia", "gasto", "evolucao"],
      "severidade": "alta",
      "titulo": "iFood",
      "frase": "Você comprou 18 vezes em iFood nos últimos 3 meses.",
      "contexto": "Isso equivale a 1 vez a cada 5 dias · R$ 890,00 no período · +40% vs período anterior.",
      "entidade": { "tipo": "loja", "chave": "loja-3", "id": 3, "nome_exibicao": "iFood" },
      "metricas": {
        "compras": 18,
        "valor_total": 890.0,
        "percentual_gasto": 7.1,
        "frequencia": { "label": "1 vez a cada 5 dias" }
      },
      "atalho": { "rota": "lojas", "id": 3, "query": { "data_inicio": "2026-05-24", "data_fim": "2026-08-24" } }
    }
  ],
  "maiores_gastos": {
    "estabelecimentos": [],
    "lojas": [],
    "categorias": [],
    "subcategorias": []
  },
  "mais_comprados": {
    "estabelecimentos": [],
    "lojas": [],
    "categorias": [],
    "subcategorias": []
  },
  "evolucao": {
    "por_mes": [
      { "chave": "2026-06", "label": "Jun/2026", "valor_total": 3800.0, "compras": 28, "variacao_percentual": 12.5, "parcial": false },
      { "chave": "2026-08", "label": "Ago/2026", "valor_total": 2100.0, "compras": 18, "variacao_percentual": -10.0, "parcial": true }
    ]
  }
}
```

`destaques.*` e itens de ranking podem ser `null` / `[]` se não houver compras.

### Item de ranking (todas as listas)

Usar `nome_exibicao` no título (loja se existir). Mostrar `nome` (maquininha) só como subtítulo quando `tipo === estabelecimento` e os dois forem diferentes.

Campos úteis na linha:

- `posicao`, `compras`, `valor_total`, `ticket_medio`
- `percentual_gasto` / `percentual_compras`
- `frequencia.label`
- `variacao_valor_percentual` (`null` = não havia gasto no período anterior → chip “novo”)
- `frase_frequencia`, `frase_gasto`, `frase_evolucao`
- `categoria_cor` nas listas de categoria/subcategoria
- `atalho` para o clique

---

## UX da tela (obrigatório)

Layout nesta ordem:

### 0) Controles

1. Título: **Onde estou gastando demais?**
2. Período: chips **1 mês · 3 meses · 6 meses · 1 ano** (query `meses`)
3. Filtros opcionais (avançado / drawer): cartão, responsável
4. Subtítulo com `periodo.label` + intervalo (`inicio` → `fim`)

### 1) Hero — dois destaques (lado a lado no desktop)

Não misturar os dois conceitos.

| Card | Fonte | Ênfase |
|------|--------|--------|
| **O que mais gasta** | `destaques.maior_gasto` | Valor + % do total. Frase = `frase` |
| **O que mais compra** | `destaques.mais_comprado` | Vezes + `frequencia.label`. Frase = `frase` |

Abaixo da frase, texto secundário = `contexto`. Clique no card → navegar pelo `atalho`.

Empty de um lado: se `maior_gasto === null`, esconder o card (não mostrar “R$ 0”).

Variação global (faixa pequena em cima ou no header): `totais.variacao_valor_percentual` vs `periodo_anterior.label`. `null` = primeiro período com dados.

### 2) Pontos críticos (`alertas`)

Título: **Pontos críticos**  
Subtítulo: “O sistema cruzou valor, frequência e evolução. Não é só categoria.”

Só renderiza se `alertas.length > 0`.

Card:

- Badge de `severidade` (`alta` vermelho, `media` âmbar, `baixa` cinza)
- Chips dos `motivos` (Frequência / Gasto / Concentração / Evolução)
- **Título** = `titulo`
- **Frase** = `frase` (corpo principal, sem reescrever)
- **Contexto** = `contexto`
- Métricas em linha: compras, valor, `metricas.frequencia.label`
- Clique → `atalho`

Ordem da API (já vem por severidade). **Não reordenar.**

### 3) Evolução

Título: **Evolução**  
Gráfico de barras ou linha com `evolucao.por_mes[]`:

- Eixo X: `label`
- Valor: `valor_total` (e, se houver espaço, `compras` como série secundária)
- Mês com `parcial: true`: legenda “mês em andamento” (não tratar queda do mês atual como alarme)
- Tooltip: valor, compras, `variacao_percentual` (ou “novo” se `null`)

### 4) Rankings — duas visões

Toggle **Mais gasta | Mais compra** (não esconder uma delas).

Persistir: `gastos_criticos_ranking = gasto | compras`.

Dentro de cada visão, **4 abas/segmentos**:

1. Lojas
2. Estabelecimentos
3. Categorias
4. Subcategorias

Mapa:

| Toggle | Aba | Array |
|--------|-----|--------|
| Mais gasta | Lojas | `maiores_gastos.lojas` |
| Mais gasta | Estabelecimentos | `maiores_gastos.estabelecimentos` |
| Mais gasta | Categorias | `maiores_gastos.categorias` |
| Mais gasta | Subcategorias | `maiores_gastos.subcategorias` |
| Mais compra | (idem) | `mais_comprados.*` |

Linha/card:

- `#posicao` · `nome_exibicao`
- Barra de % (`percentual_gasto` ou `percentual_compras` conforme o toggle)
- R$ `valor_total` · `{compras} compras` · `frequencia.label`
- Variação: `+40%` / `-12%` / chip **Novo**
- Categoria: bolinha `categoria_cor`
- Subcategoria: nome + `categoria_nome` entre parênteses

Clique:

| `atalho.rota` | Destino |
|---------------|---------|
| `lojas` | Detalhe/estatísticas da loja + `query` de datas |
| `estabelecimentos` | Detalhe/estatísticas do estabelecimento + datas |
| `transacoes` | Listagem de compras com `categoria_id` ou `subcategoria_id` + datas |

Reusar as telas já existentes (estatísticas loja/estabelecimento, listagem de compras). Não inventar um terceiro detalhe.

Empty da aba: “Nada neste recorte no período.”

### 5) Empty geral

`totais.compras === 0`: ilustração + “Sem compras neste período. Importe uma fatura ou altere o filtro.”

---

## Regras de UI (não negociar)

- **Não** reduzir a tela a um único gráfico `por_categoria`
- **Não** concatenar loja + estabelecimento na mesma lista (são abas)
- **Não** recalcular frases, BRL ou `frequencia.label`
- **Não** tratar `variacao_* === null` como 0% (é “não havia base”)
- Moeda BRL; % com 1 casa como veio
- Respeitar a ordem de `alertas` e `posicao` dos rankings
- Mobile: hero empilhado; rankings em lista; gráfico com scroll horizontal se preciso

---

## Critérios de aceite

- [ ] Menu próprio, não só widget no resumo
- [ ] Hero com **dois** cards: mais gasta × mais compra, frases da API
- [ ] Alertas com frase tipo “Você comprou 18 vezes…” + frequência + evolução
- [ ] Rankings nas 4 dimensões, nos 2 critérios
- [ ] Categorias **e** subcategorias visíveis
- [ ] Gráfico de evolução; mês `parcial` identificado
- [ ] Chips 1 / 3 / 6 / 12 meses refetch `?meses=`
- [ ] Clique usa `atalho` (loja / estabelecimento / compras filtradas)
- [ ] Empty / loading / erro / responsivo

---

## Fora de escopo

- Simular compra / projetar parcela
- Confirmar assinatura
- Editar transação a partir desta tela (só navegação)

---

Spec: [`docs/modules/gastos-criticos.md`](modules/gastos-criticos.md)  
Estatísticas de lugar (tela de destino do clique): [`frontend-prompt-estatisticas-estabelecimento-loja.md`](frontend-prompt-estatisticas-estabelecimento-loja.md)  
Compras (filtro categoria/sub): [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
