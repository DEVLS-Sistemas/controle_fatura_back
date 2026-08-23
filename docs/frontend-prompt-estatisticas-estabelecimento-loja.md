# Prompt — Frontend: Estatísticas de estabelecimento e loja

Use este prompt no repositório do frontend para mostrar **quanto e com que frequência** o usuário compra em cada estabelecimento (maquininha) e na **loja** (nome fantasia) que agrupa várias máquinas.

Backend já implementado.

---

## Objetivo

O mesmo estabelecimento aparece em várias faturas (e várias vezes na mesma). A tela responde:

- Quantas **compras** já foram feitas ali (visitas / pedidos — parcelado conta **1**)
- Quantos **lançamentos** nas faturas (`ocorrencias`)
- Qual o **total gasto**
- Qual a **frequência**: “no período você comprou 20 vezes → **1 vez a cada 3 dias**”
- Taxas equivalentes por dia / semana / mês / ano
- Na **loja**: os mesmos números **por estabelecimento** + **total geral**

---

## Conceitos

| Termo | Significado |
|-------|-------------|
| Estabelecimento | Identificador da maquininha (`atacadao152145`) |
| Loja | Nome fantasia (`Atacadão`) — 1 loja : N estabelecimentos |
| `compras` | Eventos de compra. Parcelado (`compra_grupo_id`) = **1**. À vista = 1 linha |
| `ocorrencias` | Linhas `purchase` nas faturas (12 parcelas = 12 ocorrências, 1 compra) |
| `valor_total` | Soma do valor das parcelas/compras no período (pelo `data` da compra) |
| `ticket_medio` | `valor_total / compras` |
| Frequência | `intervalo_medio_dias = dias_do_período / compras` → label “1 vez a cada X dias” |

Não misturar com cores do cartão/bandeira. Filtro de período usa a **data da compra**, não o mês da fatura.

---

## APIs (Bearer Sanctum)

### Período (query, todos os endpoints abaixo)

| Param | Efeito |
|-------|--------|
| *(omitido)* | Histórico: primeira compra → **hoje** |
| `data_inicio` / `data_fim` | Janela explícita (`Y-m-d`) |
| `mes` + `ano` | Aquele mês calendário (ex.: `mes=8&ano=2026`) |

`mes`/`ano` prevalece sobre as datas se os quatro vierem juntos? **Não** — se `mes` **e** `ano` existirem, o back usa o mês. Senão, usa as datas.

### Estabelecimento

```http
GET /api/v1/estabelecimentos/listar?data_inicio=2026-01-01&data_fim=2026-08-22
GET /api/v1/estabelecimentos/listar/{id}?mes=8&ano=2026
GET /api/v1/estabelecimentos/estatisticas/{id}?mes=8&ano=2026
```

`listar` e `listar/{id}` já vêm com `estatisticas`. O endpoint `/estatisticas/{id}` é o mesmo bloco, envelopado em `{ data, status, message }` — usar no detalhe / drawer.

### Loja

```http
GET /api/v1/lojas/listar
GET /api/v1/lojas/listar/{id}?data_inicio=&data_fim=
GET /api/v1/lojas/estatisticas/{id}
```

`listar/{id}` e `/estatisticas/{id}` trazem **totais da loja** + `estabelecimentos[]` cada um com as mesmas métricas.

---

## Shape de `estatisticas`

```json
{
  "periodo": {
    "inicio": "2026-01-01",
    "fim": "2026-08-22",
    "origem": "filtro",
    "dias": 234
  },
  "compras": 20,
  "ocorrencias": 24,
  "valor_total": 3450.5,
  "ticket_medio": 172.53,
  "primeira_compra": "2026-01-05",
  "ultima_compra": "2026-08-10",
  "dias_desde_ultima": 12,
  "frequencia": {
    "periodo_dias": 234,
    "compras": 20,
    "intervalo_medio_dias": 11.7,
    "label": "1 vez a cada 12 dias",
    "por_dia": 0.0855,
    "por_semana": 0.6,
    "por_mes": 2.6,
    "por_ano": 31.21
  }
}
```

Na **listagem**, `estatisticas` pode vir **sem** `periodo` (só o bloco acima a partir de `compras`). `frequencia.periodo_dias` e `frequencia.label` bastam.

`origem` do período: `historico` | `filtro` | `mes`.

Labels prontos (usar `frequencia.label`, não recalcular):

- `Nenhuma compra no período`
- `1 compra no período`
- `1 vez por dia` / `1 vez por semana` / `1 vez por mês`
- `1 vez a cada 2 semanas` / `1 vez a cada 2 meses`
- `1 vez a cada 3 dias` (genérico)

---

## Resposta loja (`/estatisticas/{id}` ou `listar/{id}`)

```json
{
  "loja_id": 3,
  "nome": "Atacadão",
  "estabelecimentos_count": 2,
  "compras": 35,
  "ocorrencias": 40,
  "valor_total": 8900.0,
  "ticket_medio": 254.29,
  "frequencia": { "label": "1 vez a cada 6 dias", "por_mes": 5.0 },
  "estabelecimentos": [
    {
      "id": 12,
      "nome": "atacadao152145",
      "ativo": true,
      "compras": 20,
      "valor_total": 5000.0,
      "frequencia": { "label": "1 vez a cada 12 dias" }
    },
    {
      "id": 45,
      "nome": "atacadai4555",
      "compras": 15,
      "valor_total": 3900.0,
      "frequencia": { "label": "1 vez a cada 16 dias" }
    }
  ]
}
```

Totais da loja **não** são média das frequências — são a soma das compras de todas as máquinas no mesmo período.

---

## UX

### Filtro de período (comum)

Controles no topo das telas Estabelecimento / Loja:

1. **Todo o histórico** (default)
2. **Mês** (mês + ano)
3. **Intervalo** (de / até)

Ao mudar, refetch com a query correspondente. Mostrar o período aplicado (`estatisticas.periodo` ou `frequencia.periodo_dias` dias).

### Listagem de estabelecimentos

Em cada card/linha, além do nome/loja/categoria:

| Bloco | Campo |
|-------|--------|
| Compras | `estatisticas.compras` (“20 compras”) |
| Gasto | `valor_total` (BRL) |
| Frequência | `frequencia.label` em destaque |
| Extra discreto | `ocorrencias` lançamentos · ticket médio |

Clique no card → detalhe / drawer com o bloco completo + taxas dia/semana/mês/ano.

Empty: `compras === 0` → “Sem compras neste período” (não esconder o cadastro).

### Detalhe do estabelecimento

Cards:

1. **Compras** + ocorrências
2. **Total gasto** + ticket médio
3. **Frequência** — título = `frequencia.label`  
   Sub: “20 compras em 234 dias”
4. Grade: por dia / semana / mês / ano (`por_*`)
5. Primeira / última compra · “há X dias” (`dias_desde_ultima`)

Frase modelo:

> No período você comprou **20 vezes**. Isso equivale a **1 vez a cada 12 dias** (~2,6× por mês).

### Tela / detalhe da loja (obrigatório)

Cabeçalho com totais da loja (mesmo layout do estabelecimento).

Tabela/lista dos estabelecimentos vinculados:

| Estabelecimento | Compras | Gasto | Frequência |
|-----------------|---------|-------|------------|
| atacadao152145 | 20 | R$ 5.000 | 1 vez a cada 12 dias |
| atacadai4555 | 15 | R$ 3.900 | 1 vez a cada 16 dias |
| **Total Atacadão** | **35** | **R$ 8.900** | **1 vez a cada 6 dias** |

Clique na linha → detalhe daquele estabelecimento (mesmo período na query).

### Listagem de lojas

Mostrar `estabelecimentos_count` + `estatisticas.compras` + `valor_total` + `frequencia.label`.

---

## Critérios de aceite

- [ ] Listagem de estabelecimento mostra qtd de compras, total gasto e label de frequência
- [ ] Detalhe explica “N compras no período = 1 vez a cada X dias” + taxas dia/semana/mês/ano
- [ ] Filtro histórico / mês / intervalo refetch
- [ ] Parcelado conta 1 compra; ocorrências mostram os lançamentos
- [ ] Loja lista cada estabelecimento + total geral (soma, não média das labels)
- [ ] Zero compras no período não quebra o layout
- [ ] Moeda BRL; não recalcular o `label` no front

---

## Fora de escopo

- Editar compra a partir desta tela
- Frequência por fatura (competência) — o back usa data da compra
- Ranking global de estabelecimentos (pode vir depois com os mesmos campos da listagem)

---

## Backend

```http
GET /api/v1/estabelecimentos/estatisticas/{id}
GET /api/v1/lojas/estatisticas/{id}
```

Service: `App\Services\Estabelecimento\EstabelecimentoEstatisticasService`  
Specs: [`docs/modules/estabelecimentos.md`](modules/estabelecimentos.md) · [`docs/modules/lojas.md`](modules/lojas.md)
