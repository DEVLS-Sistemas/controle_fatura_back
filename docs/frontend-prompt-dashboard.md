# Prompt — Frontend: Dashboard (resumo)

Use este prompt no repositório do frontend para **ajustar o filtro de período** do Dashboard (`GET /dashboard/resumo`) e alinhar a tela à API.

Backend **já implementado**. Spec: [`modules/dashboard.md`](modules/dashboard.md).

---

## O que mudar (obrigatório)

Hoje o filtro de **ano** e **mês** está como **input text**. Trocar pelos **mesmos selects** usados nas outras telas (Faturas, Projeção, Raio-X, cadastro de fatura).

Além disso, o mês deixa de ser só “um valor ou vazio”:

| Modo | O que o usuário escolhe | Query |
|------|-------------------------|-------|
| **Ano todo** | só o ano | `?ano=2026` |
| **Mês específico** | ano + um mês | `?ano=2026&mes=7` |
| **Intervalo** | ano + mês inicial + mês final | `?ano=2026&mes_inicio=3&mes_fim=6` |

O recorte é **sempre no mesmo ano** (competência da fatura). Não existe intervalo que cruza dezembro → janeiro.

---

## Objetivo da tela

O Dashboard / resumo mostra **totais e gráficos** do período:

1. KPIs (`totais`)
2. Série do ano (`por_mes`)
3. Pizza / barras de categoria, responsável, cartão e tipo

Não confundir com:

| Tela | Recorte | Papel |
|------|---------|-------|
| Raio-X | um mês | Frases 🟢🟡🔴 |
| Projeção | 13 meses | Matriz futura |
| Gastos críticos | janela pela **data da compra** | Onde gasta demais |

Aqui o recorte é **competência da fatura** (`faturas.mes` / `faturas.ano`).

Rota: `/` ou `/dashboard`. Deep-link: `/dashboard?ano=2026&mes=8` · `/dashboard?ano=2026&mes_inicio=3&mes_fim=6` · `/dashboard?ano=2026`.

---

## API (Bearer Sanctum)

```http
GET /api/v1/dashboard/resumo?ano=2026
GET /api/v1/dashboard/resumo?ano=2026&mes=7
GET /api/v1/dashboard/resumo?ano=2026&mes_inicio=3&mes_fim=6
```

Envelope: `{ data, status, message }`.

### Query

| Param | Default | Enviar quando |
|-------|---------|---------------|
| `ano` | ano atual | Sempre |
| `mes` | — | Mês específico. **Não** enviar junto com `mes_inicio` / `mes_fim` |
| `mes_inicio` | — | Intervalo (1–12) |
| `mes_fim` | — | Intervalo (1–12), ≥ `mes_inicio` |

Regras:

- Sem `mes` e sem intervalo → ano todo.
- `mes_inicio` **tem precedência** sobre `mes` se os dois forem mandados — o front **não** deve mandar os dois.
- `mes_inicio === mes_fim` → a API responde `tipo: "mes"` (tratar como mês específico).
- `mes_inicio=1` e `mes_fim=12` → `tipo: "ano"`. Preferir omitir os dois e mandar só `ano`.
- 422: mês fora de 1–12, `mes_fim` < `mes_inicio`, ano inválido. Exibir `message`.

Ao mudar qualquer select, **refetch**. Não debounce longo: é select, não digitação.

### `data.periodo` (usar no subtítulo)

```json
{
  "ano": 2026,
  "mes": null,
  "mes_inicio": 3,
  "mes_fim": 6,
  "tipo": "intervalo",
  "label": "Março – Junho 2026",
  "meses": [3, 4, 5, 6]
}
```

| `tipo` | `mes` | `mes_inicio` / `mes_fim` | `label` |
|--------|-------|--------------------------|---------|
| `ano` | `null` | `null` | `2026` |
| `mes` | `7` | `7` / `7` | `Julho 2026` |
| `intervalo` | `null` | `3` / `6` | `Março – Junho 2026` |

Subtítulo da tela = `periodo.label`. Não montar a frase no front.

`periodo.meses` = competências incluídas nos KPIs / pizzas. Use para **destacar** no gráfico `por_mes`.

### Shape resumido (`data`)

```json
{
  "periodo": { "ano": 2026, "mes": 7, "tipo": "mes", "label": "Julho 2026", "meses": [7] },
  "totais": {
    "total_compras": 4200.5,
    "total_pagamentos": 800,
    "total_estornos": 50,
    "total_antecipacoes": 0,
    "total_encargos": 12.9,
    "total_liquido": 3362.6,
    "total_transacoes": 84
  },
  "por_mes": [
    { "mes": 1, "total": 2100.0 },
    { "mes": 7, "total": 3362.6 }
  ],
  "por_categoria": [
    { "categoria_id": 1, "nome": "Alimentação", "cor": "#f97316", "total": 1200.0, "quantidade": 18 }
  ],
  "por_responsavel": [
    { "responsavel_id": 1, "nome": "Eu", "tipo": "proprio", "total": 2800.0, "quantidade": 40 }
  ],
  "por_cartao": [
    { "cartao_id": 1, "nome": "Nubank", "cor_fundo": "#8b5cf6", "cor_texto": "#ffffff", "total": 3362.6, "quantidade": 2 }
  ],
  "por_tipo": [
    { "tipo": "purchase", "total": 4200.5, "quantidade": 70 }
  ]
}
```

- `totais`, `por_categoria`, `por_responsavel`, `por_cartao`, `por_tipo` → **só o recorte**
- `por_mes` → **ano inteiro** (meses que têm fatura). Mesmo filtrando julho, a série traz jan–dez. Destacar `periodo.meses` (barra/coluna mais forte; o resto opaco)
- `total_liquido` = soma de `faturas.valor_total` (o que as faturas pedem para pagar), não `compras − pagamentos`
- Moeda BRL. Não recalcular totais no cliente

---

## UX do filtro (obrigatório)

**Não** usar `<input type="text">` / number / date para ano ou mês.

Três controles no topo, **selects nativos ou o mesmo componente de select das outras telas**:

```
[ Ano ▼ ]   [ De ▼ ]   [ Até ▼ ]
  2026       Julho       Julho
```

### 1) Select **Ano**

Igual aos demais campos de ano do sistema (Faturas, cadastro de fatura, Projeção, Raio-X):

- Componente: **select**, não text
- Opções geradas no cliente: `anoAtual - 5` … `anoAtual + 1` (se já existir helper compartilhado, **reutilizar**)
- Default: ano atual
- Valor = número (`2026`)

### 2) Select **De** (mês inicial)

| value | Label |
|-------|--------|
| `""` / `all` | **Ano todo** |
| `1` … `12` | Janeiro … Dezembro |

Default na primeira visita: **mês atual** (não “Ano todo”). Assim o dashboard abre no mês corrente, como Raio-X / Projeção.

### 3) Select **Até** (mês final)

- Visível e habilitado só quando **De** não é “Ano todo”
- Opções: meses **≥ De** (Janeiro some se De = Março)
- Default: igual a **De** (mês específico)
- Se o usuário mudar **De** para um mês > **Até**, ajustar **Até** = **De**

Atalho opcional no select De ou ao lado: chip **Ano todo** — zera De/Até.

### Mapeamento select → query

| De | Até | Query |
|----|-----|-------|
| Ano todo | (oculto) | `?ano=2026` |
| Julho | Julho | `?ano=2026&mes=7` |
| Março | Junho | `?ano=2026&mes_inicio=3&mes_fim=6` |

Não enviar `mes=0`, `mes=` vazio, nem `mes` + intervalo juntos.

### Persistência

`localStorage`:

- `dashboard_ano`
- `dashboard_mes_inicio` (`all` \| `1`–`12`)
- `dashboard_mes_fim` (`1`–`12`, ignorar se inicio = `all`)

Prioridade: **query string** > localStorage > mês/ano atuais.

---

## Layout do restante (não regressar)

Ordem sugerida. Os blocos já existentes podem permanecer; o ajuste é o filtro.

### 0) Controles

1. H1: **Dashboard** (ou o título atual)
2. Os 3 selects acima
3. Subtítulo: `periodo.label`

### 1) KPIs (`totais`)

Cards: líquido, compras, pagamentos, estornos, antecipações, encargos, qtd de transações. Destaque em **líquido**.

### 2) Série do ano (`por_mes`)

Barras 1–12. Meses sem item na API = 0. Destacar índices em `periodo.meses`. Clique numa barra → setar De = Até = aquele mês e refetch (opcional, bom de ter).

### 3) Categorias / responsáveis / cartões / tipos

Gráficos ou listas a partir dos arrays. Cor do cartão: `cor_fundo` / `cor_texto`. Categoria: `cor`.

Empty: `totais.total_transacoes === 0` → “Sem lançamentos neste período. Importe uma fatura ou altere o filtro.”

---

## Regras de UI (não negociar)

- Ano e mês = **select**, nunca input text
- Intervalo só **dentro do ano** selecionado
- Recorte = competência da fatura, não data da compra (isso é Gastos críticos)
- Não somar `por_mes` no cliente para achar o líquido do intervalo — usar `totais`
- Não chamar Raio-X / Projeção / Gastos críticos para montar este resumo
- 401 → login; 422 mostra `message`

---

## Critérios de aceite

- [ ] Inputs text de ano/mês **removidos**
- [ ] Select de ano no **mesmo padrão** das outras telas (lista `atual-5` … `atual+1`, ou o helper já usado)
- [ ] Select De com **Ano todo** + 12 meses
- [ ] Select Até aparece no intervalo; opções ≥ De
- [ ] Ano todo → `?ano=` sem `mes`
- [ ] Mês específico → `?mes=`
- [ ] Intervalo Mar–Jun → `?mes_inicio=3&mes_fim=6`
- [ ] Default primeira visita = mês atual
- [ ] Query string e localStorage restauram o filtro
- [ ] Subtítulo usa `periodo.label`
- [ ] Gráfico `por_mes` destaca `periodo.meses`
- [ ] KPIs / pizzas mudam no refetch (são do recorte)
- [ ] Empty / loading / 422 / mobile (selects empilhados)

---

## Fora de escopo

- Intervalo que cruza anos
- Filtro por cartão / responsável neste endpoint (não existe na API de resumo)
- Recalcular Raio-X, ranking ou projeção nesta tela

---

Backend: `GET /api/v1/dashboard/resumo`  
Service: `App\Services\Dashboard\DashboardService`  
Spec: [`docs/modules/dashboard.md`](modules/dashboard.md)
