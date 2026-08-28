# Prompt — Frontend: doughnut de Plataforma em Gastos por categoria

Use este prompt no repositório do **frontend**. Back **já implementado** — não precisa de endpoint novo.

Tela: **Gastos por categoria** (`/gastos-por-categoria`).  
Gráfico **rosca** (doughnut). Não é pizza cheia, não é barra, não é só chip.

Irmã da rosca de Origem: [`frontend-prompt-gastos-por-categoria-origem.md`](frontend-prompt-gastos-por-categoria-origem.md).  
Spec da tela: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md)

---

## Objetivo

Acrescentar a **quarta** rosca:

**Plataforma de compra** — como o valor se reparte entre iFood, Loja Física, Amazon, etc., **respeitando os filtros da tela** e a **fatia de categoria** já selecionada.

Responde: *“Desse recorte, quanto foi iFood vs loja física vs marketplace?”*

Não substitui a rosca de Origem (online/presencial/serviços/fatura). As duas ficam visíveis.

---

## Nome e tipo

| Termo | Usar |
|-------|------|
| Tipo | **doughnut** / `innerRadius` > 0 |
| Nome na UI | **Plataforma** |
| Não chamar | pizza, pie, donut em inglês na label |

Buraco obrigatório. Centro = total em BRL do recorte. Mesma lib/altura/legenda das outras roscas.

---

## API (já existe)

```http
GET /api/v1/dashboard/gastos-por-categoria?meses=3
```

**Não** criar fetch extra. Clique na pizza de categoria **não** refetch — a rosca de plataforma troca de fonte no cliente.

### Fontes

| Estado da tela | Array |
|----------------|-------|
| Nenhuma categoria selecionada | `data.por_plataforma[]` |
| Fatia de **categoria** clicada | `categorias[].por_plataforma` daquela categoria |
| Fatia de **subcategoria** clicada | Continuar o `por_plataforma` da **categoria pai** |

Filtro global `?plataforma_id=` (refetch) é opcional, no mesmo espírito do `?origem_compra=`.

### Shape de cada item (`por_plataforma[]`)

```json
{
  "chave": "plataforma-6",
  "plataforma_id": 6,
  "nome": "iFood",
  "cor": "#ea1d2c",
  "compras": 12,
  "ocorrencias": 12,
  "valor_total": 540.0,
  "ticket_medio": 45.0,
  "percentual_gasto": 18.0,
  "percentual_compras": 20.0,
  "percentual_da_categoria": 18.0,
  "atalho": {
    "rota": "transacoes",
    "query": {
      "plataforma_id": "6",
      "data_inicio": "2026-05-24",
      "data_fim": "2026-08-24"
    }
  }
}
```

- Sem plataforma: `plataforma_id: null`, `chave: "sem-plataforma"`, `nome: "Sem plataforma"`, `cor: "#9ca3af"`
- Ângulo = `valor_total`
- Label = `nome` da API
- Cor = `cor` da API (**não** paleta fixa; cada cadastro tem a sua)
- Ordem = API (maior valor primeiro)
- `valor_total === 0` não entra na rosca

---

## Layout (obrigatório)

As **duas pizzas** de categoria/sub **permanecem**. Origem e Plataforma entram **junto**:

```
Filtros

KPIs

┌──────────────┐  ┌──────────────┐
│ Categorias   │  │ Subcategorias│
│  MESTRE      │→ │  ESCRAVA     │
└──────────────┘  └──────────────┘
┌──────────────┐  ┌──────────────┐
│ Origem       │  │ Plataforma   │
│  (rosca)     │  │  (rosca)     │
└──────────────┘  └──────────────┘

Evolução
```

- **Desktop (≥ lg):** 2×2, mesma altura
- **Tablet:** 2 + 2
- **Mobile:** empilhar Categorias → Subcategorias → Origem → Plataforma

Título do card Plataforma:

| Seleção | Título |
|---------|--------|
| Nenhuma | **Plataforma** |
| Categoria “Alimentação” | **Plataforma em Alimentação** |
| Sub “Pizzaria” | **Plataforma em Alimentação** (pai) |

Chip **Limpar filtro** também restaura `data.por_plataforma`.

---

## Clique

1. Clique simples — destaca a fatia. **Não navega.**
2. Segundo clique na mesma fatia — refetch `?plataforma_id={id}` se quiser filtrar a tela. `plataforma_id === null` (“Sem plataforma”) **não** manda o param.
3. Duplo clique / “Ver compras” → `atalho`.

---

## Empty / loading

- `por_plataforma.length === 0`: “Sem plataforma neste recorte”; **não** esconder o card
- Loading: **quatro** skeletons de rosca
- Uma plataforma só: uma fatia 100% — ok

---

## Critérios de aceite

- [ ] Rosca **Plataforma** visível na mesma tela (2×2 com categoria/sub/origem)
- [ ] Fatias = `por_plataforma` do GET
- [ ] Clicar numa categoria: a rosca passa a `categoria.por_plataforma` **sem** novo GET
- [ ] Cores vêm da API (`cor` do cadastro), não rainbow
- [ ] Labels = `nome` (iFood, Loja Física, …)
- [ ] Desktop 2×2; mobile empilhado
- [ ] Origem **e** Plataforma coexistem (não substituir uma pela outra)
