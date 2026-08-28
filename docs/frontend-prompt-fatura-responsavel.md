# Prompt — Frontend: Fatura do Responsável (por competência)

Use este prompt no repositório do frontend para criar a tela **Fatura do Responsável**, acessível a partir da **Projeção** (detalhe / clique no responsável) e do hub **[Visualizar responsável](frontend-prompt-visualizacao-responsavel.md)** (card “Neste mês”).

Espelho visual da [tela de fatura (view)](frontend-prompt-faturas.md), mas o escopo é outro:

| Fatura do cartão (hoje) | Fatura do responsável (nova) |
|-------------------------|------------------------------|
| 1 fatura = 1 **bandeira** × competência | 1 “fatura virtual” = 1 **responsável** × competência |
| Transações só daquela bandeira | Transações do responsável em **todos** os cartões/bandeiras |
| Agrupa por **final do cartão** (`ultimos_digitos`) | Agrupa por **cartão (grupo)** → opcionalmente bandeira/final |
| Quitação (`pago` / `valor_pago`) do extrato | **Não** usar quitação de bandeira. Quitação do responsável = **repasses** (ver [`frontend-prompt-repasses-responsavel.md`](frontend-prompt-repasses-responsavel.md)) |

Referências: [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md), [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md), [`frontend-prompt-compras.md`](frontend-prompt-compras.md).

---

## Objetivo

Na Projeção, ao abrir o detalhe de um responsável (ou clicar na célula do mês), mostrar uma tela semelhante à fatura view que:

1. Filtra por `responsavel_id` + competência (`mes` / `ano`)
2. Lista **todas** as compras desse responsável naquela competência, em qualquer cartão
3. Agrupa visualmente por cartão (chip com cores do grupo)
4. Exibe total do responsável na competência (soma das compras)
5. Reaproveita ações de edição de transação já existentes (categoria, responsável, final, etc.)

Não criar fatura nova no banco — é uma **visão agregada** das transações.

---

## Entrada a partir da Projeção

Fonte de dados da matriz: `GET /api/v1/dashboard/projecao-faturas?mes=&ano=`

### Deep-links sugeridos

| Origem na UI | Params da nova tela |
|--------------|---------------------|
| Clique na **linha** do responsável (Tabela 2 — `por_responsavel`) | `responsavel_id` + `mes`/`ano` da coluna de referência (ou coluna clicada) |
| Clique numa **célula** mês × responsável | `responsavel_id` + `mes`/`ano` daquela coluna (`colunas[].mes` / `colunas[].ano`) |
| Expandir cartão × responsável (Tabela 3) e clicar no responsável | Idem + opcional `cartao_id` para pré-filtrar |

Rota sugerida (exemplo):

```
/projecao/responsaveis/:responsavelId/fatura?mes=8&ano=2026
```

ou drawer/modal full-screen com os mesmos params.

Passar também o `nome` / `tipo` do responsável vindos da projeção evita um request extra no header (opcional).

---

## API — o que já existe (MVP sem endpoint novo)

Base autenticada: Bearer Sanctum em `/api/v1`.

### 1. Cabeçalho do responsável

```http
GET /api/v1/responsaveis/listar/{responsavel_id}
```

Campos úteis: `id`, `nome`, `tipo` (`pessoal` | `empresa`), `ativo`.

### 2. Linhas da “fatura” (obrigatório)

```http
GET /api/v1/transacoes/listar?responsavel_id={id}&mes={m}&ano={a}&tipo=purchase&perPage=100&page=1
```

| Query | Obrigatório | Notas |
|-------|-------------|-------|
| `responsavel_id` | sim | filtra dono da compra |
| `mes` | sim | competência da **fatura** (não o mês civil da data isolada) |
| `ano` | sim | idem |
| `tipo` | recomendado `purchase` | alinha com a Projeção (totais de responsável = só compras). Sem filtro, podem entrar `payment` / `refund` / `advance` |
| `cartao_id` | opcional | se veio do drill-down cartão × responsável |
| `perPage` / `page` | sim | paginar; subir `perPage` (ex. 100–200) se a UI agrupa tudo de uma vez |

**Competência:** `mes`/`ano` filtram `faturas.mes` / `faturas.ano` (ciclo do cartão), igual à Projeção.

Cada linha já traz metadados para agrupar e espelhar a fatura view:

| Campo | Uso na UI |
|-------|-----------|
| `cartao_id`, `cartao_nome`, `cartao_cor_fundo`, `cartao_cor_texto` | cabeçalho do grupo (cartão) |
| `cartao_bandeira_id`, `cartao_bandeira` | subgrupo / chip se houver > 1 bandeira |
| `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_*` | final dentro do cartão |
| `fatura_id`, `fatura_mes`, `fatura_ano` | link “abrir fatura do cartão” |
| `data`, `estabelecimento`, `valor`, `parcela_atual`/`parcelas_total` | linha |
| `categoria_*`, `subcategoria_*`, `origem_compra`, `plataforma_*`, `observacoes` | igual compras/fatura |

Ordenação sem `fatura_id`: `data` desc. No front, **reordene/agrupe** por `cartao_nome` → `ultimos_digitos` → `data` (a API não ordena por cartão nesse filtro).

### 3. Total (MVP)

Somar no front as linhas carregadas (`tipo=purchase`) **ou** usar o valor já conhecido da Projeção:

```
por_responsavel[].valores[i].total   // coluna mes/ano correspondente
por_responsavel[].valores[i].realizado
por_responsavel[].valores[i].projetado
```

Se `projetado > 0`, avisar na UI que a Projeção inclui parcelas futuras ainda não listáveis como realizado — nesta tela mostre só o que veio de `/transacoes/listar` (realizado). Opcional: badge “+ R$ X projetado” vindo da célula da Projeção.

### 4. Export CSV

```http
GET /api/v1/transacoes/exportar?responsavel_id={id}&mes={m}&ano={a}&tipo=purchase
```

### 5. Edição / exclusão (reaproveitar)

Mesmos fluxos da fatura view / compras:

- `PUT /api/v1/transacoes/editar`
- `DELETE /api/v1/transacoes/excluir/{id}`
- Lookups: `GET /api/v1/transacoes/lookups`

Após editar responsável/categoria/final: refetch da listagem filtrada.

### 6. Abrir fatura “de verdade” do cartão

Cada linha tem `fatura_id`. CTA por grupo ou por linha:

```http
GET /api/v1/faturas/listar/{fatura_id}
```

→ navega para a tela atual de detalhe da fatura (bandeira).

---

## API — endpoint recomendado (ainda não existe)

Para paridade com `GET /faturas/listar/{id}` (header + subtotais sem o front somar), o backend deveria expor algo como:

```http
GET /api/v1/responsaveis/{id}/fatura?mes=8&ano=2026
```

ou

```http
GET /api/v1/faturas/por-responsavel?responsavel_id=1&mes=8&ano=2026
```

### Shape sugerido (contrato alvo)

```json
{
  "responsavel_id": 1,
  "responsavel_nome": "Eu",
  "responsavel_tipo": "pessoal",
  "mes": 8,
  "ano": 2026,
  "competencia": "08/2026",
  "valor_total": 1250.40,
  "total_transacoes": 18,
  "transacoes_com_categoria": 16,
  "competencia_anterior": { "mes": 7, "ano": 2026 },
  "competencia_proxima": { "mes": 9, "ano": 2026 },
  "grupos_por_cartao": [
    {
      "cartao_id": 1,
      "cartao_nome": "Sofisa",
      "cartao_cor_fundo": "#8b5cf6",
      "cartao_cor_texto": "#ffffff",
      "bandeira": "Mastercard",
      "cartao_bandeira_id": 1,
      "fatura_id": 73,
      "total_transacoes": 10,
      "valor_total": 800.10
    },
    {
      "cartao_id": 2,
      "cartao_nome": "Nubank",
      "cartao_cor_fundo": "#820ad1",
      "cartao_cor_texto": "#ffffff",
      "bandeira": "Mastercard",
      "cartao_bandeira_id": 4,
      "fatura_id": 88,
      "total_transacoes": 8,
      "valor_total": 450.30
    }
  ]
}
```

**Regras do endpoint (quando existir):**

- Soma só `tipo = purchase` (igual Projeção por responsável)
- `grupos_por_cartao` = subtotal por cartão (e bandeira se necessário)
- `competencia_anterior` / `competencia_proxima` = navegação mês a mês (não por bandeira)
- **Não** incluir `pago` / `valor_pago` / `pagamentos_*` — quitação é da fatura da bandeira
- Linhas continuam em `GET /transacoes/listar?responsavel_id&mes&ano&tipo=purchase`

> **Status atual:** este endpoint **ainda não está implementado**. Implementar a tela em MVP com os endpoints da seção anterior; quando o resumo existir, só trocar o header/subtotais.

---

## UI sugerida (espelho da fatura view)

### Topo

1. Botões **Anterior / Próxima competência** (`mes-1` / `mes+1`, ou campos do endpoint futuro)
2. Título: `Fatura · {nome do responsável}` + chip `tipo`
3. Competência: `08/2026`
4. **Não** mostrar intervalo único de ciclo / vencimento global (cada cartão tem ciclo próprio). Opcional: no cabeçalho de cada grupo, “Fecha dia X · Vence dia Y” se tiver esses dados do cartão (lookups / projeção)

### Bloco financeiro

```
Total do responsável   R$ {soma purchases}
Lançamentos            {N}
```

Sem bloco Pago / Restante da **bandeira**. Para o que o responsável já te pagou, use badges `status_repasse` e o link **Controle de repasses** (matriz compra × mês) — ver [`frontend-prompt-repasses-responsavel.md`](frontend-prompt-repasses-responsavel.md).

Se veio da Projeção com `projetado > 0`:

```
Realizado (nesta tela)  R$ …
Projetado (projeção)    R$ …   ← informativo
```

### Lista agrupada

Agrupar no front por `cartao_id` (ordem alfabética do nome):

```
[chip Sofisa]                         subtotal R$ 800,10
  •••• 7025 · NOME
    10/07  ESTABELECIMENTO   1/3   R$ 100,00
    …
  •••• 7033 · NOME
    …

[chip Nubank]                         subtotal R$ 450,30
  •••• 1234
    …
```

Dentro do cartão, subagrupar por `ultimos_digitos` como na fatura view (incluindo “Sem cartão identificado” se `cartao_numero_id` null).

Por grupo: link **“Abrir fatura do cartão”** → `/faturas/{fatura_id}` (usar `fatura_id` de qualquer linha do grupo, ou do endpoint futuro).

### Ações por linha

Reaproveitar componentes da fatura view / compras: editar categoria, responsável, final, excluir, ver parcela.

### Empty state

“Nenhuma compra deste responsável em {competência}.” + CTA voltar à Projeção.

### Filtros opcionais na própria tela

- Cartão (`cartao_id`)
- Busca (`palavra_chave` no `/transacoes/listar`)
- Export CSV

---

## Diferenças importantes vs fatura view

1. **Não** chamar `GET /faturas/listar/{id}` como fonte principal — esse ID é de bandeira, não de responsável
2. **Não** usar `fatura_id` sozinho na listagem de linhas — usar `responsavel_id` + `mes` + `ano`
3. Preferir `tipo=purchase` para bater com os totais da Projeção
4. Quitação / PDF / processar anexo **não** entram nesta tela (continuam na fatura da bandeira)
5. Navegação anterior/próxima é por **competência**, não por `fatura_anterior_id`

---

## Fluxo resumido (MVP)

```
Projeção (célula responsável × mês)
  → tela Fatura do Responsável (responsavel_id, mes, ano)
      → GET /responsaveis/listar/{id}          (header)
      → GET /transacoes/listar?responsavel_id&mes&ano&tipo=purchase
      → agrupar por cartao_id → ultimos_digitos
      → total = soma dos valores (ou célula da projeção)
      → opcional: exportar / editar linha / abrir fatura_id do cartão
```

---

## Checklist de aceite

- [ ] Entrada a partir da Projeção (linha ou célula do responsável) com `responsavel_id` + `mes` + `ano`
- [ ] Layout semelhante à fatura view (header + total + lista agrupada)
- [ ] Lista só compras do responsável na competência, em **todos** os cartões
- [ ] Usa `GET /transacoes/listar?responsavel_id=&mes=&ano=&tipo=purchase`
- [ ] Agrupa por cartão (cores do chip) e, dentro, por final
- [ ] Não exibe quitação (`pago` / restante) como se fosse fatura de bandeira
- [ ] Navegação mês anterior / próximo
- [ ] Link para a fatura real do cartão via `fatura_id`
- [ ] Edição de transação reaproveita fluxos existentes e refetcha a lista
- [ ] Empty state quando não há compras
- [ ] (Opcional) Export CSV com os mesmos filtros
- [ ] (Futuro) Trocar header/subtotais para `GET .../fatura?mes&ano` quando o backend existir
