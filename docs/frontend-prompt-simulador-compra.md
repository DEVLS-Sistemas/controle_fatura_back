# Prompt — Frontend: Simulador de compra

Use este prompt no repositório do frontend para implementar a tela **Simulador de compra** — um **menu novo**.

Pergunta que a tela responde:

> Se eu comprar R$ 3.000 em 10x neste cartão, neste responsável, como ficam minhas próximas faturas?

Mostra o impacto **mês a mês antes de registrar**. Não grava transação.

Backend: **não há endpoint dedicado ainda.** O MVP reusa `GET /dashboard/projecao-faturas` (a mesma matriz da tela de Projeção) e o front sobrepõe a compra simulada. Quando o endpoint de simulação existir, só trocar a fonte do overlay.

Specs: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md) · [`frontend-prompt-compras.md`](frontend-prompt-compras.md) · [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md) · [`frontend-prompt-pessoas.md`](frontend-prompt-pessoas.md) · [`frontend-prompt-responsavel-titular.md`](frontend-prompt-responsavel-titular.md).

---

## Objetivo

1. **Novo item de menu** (não misturar com Projeção). Projeção = o que já existe. Simulador = “e se eu comprar isso?”.
2. Reaproveitar **os mesmos componentes visuais da Projeção** (tabela 13 meses, chips de cartão, linha do responsável, uso de limite, Eu vs Outros).
3. Considerar **qual responsável** vai ficar com a dívida.
4. Default: **titular principal** + **responsável padrão** já carregados; o usuário pode trocar.
5. Ao escolher cartão + responsável, pegar **tudo que esse responsável já deve naquele cartão** e **somar a compra simulada**.
6. Ao lado, o **impacto geral**: soma das compras desse responsável **nos outros cartões** + a simulada, para ver quanto a “fatura do responsável” vai ficar — e evitar que ele deva demais ao titular.

---

## Conceitos (não misturar)

| Conceito | Significado | Nesta tela |
|----------|-------------|------------|
| **Titular (Pessoa)** | Dono do plástico / cartão | filtro de quais cartões aparecem |
| **Cartão** | Grupo (Nubank, Sofisa…) | onde a compra simulada “cai” |
| **Responsável** | Quem **deve** a compra | linha que recebe o overlay |
| **Projeção** | Faturas reais + parcelas já lançadas | base **sem** mutar |
| **Simulação** | Overlay em memória | some se sair da tela; **não** chama `POST /transacoes/cadastrar` |
| **Neste cartão** | Dívida do responsável **só** no cartão escolhido + parcelas simuladas | impacto local / limite |
| **Geral (todos os cartões)** | Dívida do responsável em **todos** os cartões + parcelas simuladas | “quanto ele vai te dever no mês” |

Não é rateio. A dívida inteira vai para um responsável.

Não é a [fatura do responsável](frontend-prompt-fatura-responsavel.md) (visão de um mês já existente). Aqui o mês a mês é **projeção + hipotética**.

---

## Menu / rotas

Item de menu sugerido: **Simulador** (ou **Simular compra**). Ícone tipo calculadora / “e se”.

**Não** colocar como aba dentro de Projeção. São telas irmãs; o Simulador **reusa o layout** da Projeção.

```
/simulador
/simulador?pessoa_id=1&cartao_id=2&responsavel_id=15&valor=3000&parcelas=10&data=2026-08-24
```

Deep-links úteis (pré-preencher o form):

| Origem | Query |
|--------|--------|
| Projeção (linha do cartão) | `cartao_id` + `pessoa_id` do cartão |
| Projeção (linha do responsável) | `responsavel_id` |
| Visualizar responsável | `responsavel_id` (+ `pessoa_id` se `pessoa` vier) |
| Fatura do cartão | `cartao_id` + `pessoa_id` + `responsavel_id` padrão da fatura |

---

## Defaults ao abrir

Carregar nesta ordem (tudo editável depois):

| Campo | Default |
|-------|---------|
| **Titular** | Pessoa principal (`GET /api/v1/auth/me` → `pessoa_id`, ou item `eh_principal: true` em `GET /pessoas/listar` / `pessoas-list`) |
| **Responsável** | Padrão do titular (ver abaixo) |
| **Cartão** | Primeiro cartão **ativo** daquele titular (ver filtro) |
| **Data** | Hoje |
| **Parcelas** | `1` |
| **Valor** | vazio — sem overlay até preencher um valor > 0 |

### Responsável padrão do titular

| Titular | Responsável default |
|---------|---------------------|
| Pessoa principal (você) | `default_responsavel_id` de `GET /api/v1/transacoes/lookups` (o **Eu**) |
| Outra pessoa (ex. Maysa) | `pessoa.responsavel_id` quando a API mandar (cadastro auto do titular). Senão, responsável cujo `nome` bate com `nome_completo` da pessoa. Continua **permitindo trocar**. |

`GET /pessoas/listar/{id}` / `toListArray` pode trazer `responsavel_id`. Se a listagem paginada ainda não mandar, usar `GET /pessoas/listar/{id}` ou o match por nome em `GET /responsaveis/responsaveis-list`.

### Cartões do titular

Fonte preferida (já vem na Projeção): `por_cartao[].pessoa_id` / `pessoa_nome` / `pessoa_eh_principal`.

1. Titular selecionado → filtrar `por_cartao` com `pessoa_id === titular.id`.
2. Se o titular for o principal, incluir também cartões com `pessoa_id === null` (legado sem titular).
3. Default = primeiro da lista filtrada (ordem da API = nome).
4. Select de cartão **só** com os cartões daquele titular. Ao **trocar o titular**, resetar o cartão (primeiro da nova lista) e o responsável (padrão do novo titular).

Empty: “Nenhum cartão deste titular.” CTA para Cadastro de cartões.

---

## Form (topo da tela)

Pergunta visível:

> Se eu comprar **R$ {valor}** em **{N}x** no **{cartão}**, no nome de **{responsável}**, como ficam as próximas faturas?

Campos:

| Campo | UI | Obrigatório p/ overlay |
|-------|-----|------------------------|
| Titular | select (`pessoas-list` / listagem; badge Principal) | sim |
| Cartão | select com chip `cor_fundo` / `cor_texto`; subtexto “Fecha dia {dia_limite_fatura} · Vence dia {dia_vencimento_fatura}” | sim |
| Responsável | select / modal no mesmo espírito das [compras](frontend-prompt-compras.md) (texto “Responsável: {nome}” + trocar). **Não** travar no padrão. | sim |
| Valor da compra | money BRL (`valor_compra`) | sim (> 0) |
| Parcelas | select **1..36** (igual compras) | sim |
| Data | date (compra) | sim |

Opcional no form (não precisa para simular): estabelecimento, categoria, origem. Só entram se o usuário clicar **Registrar esta compra**.

### UX do parcelamento (igual compras)

1. Usuário informa valor e N.
2. Front gera N parcelas iguais (`valor / N`; **centavos na última**).
3. Usuário **pode** ajustar cada parcela (accordion “Valores das parcelas”).
4. Soma das parcelas = `valor_compra` (bloquear overlay/simular se diferir > R$ 0,01).
5. Preview compacto sempre visível:

```
Parcela  R$ 300,00
1ª fatura  Set/2026   (ciclo: compras até dia 5 entram neste mês)
Última     Jun/2027
Total      R$ 3.000,00
```

### Recalcular

Recalcular o overlay (debounce ~300 ms) quando mudar: titular, cartão, responsável, valor, N, data, valores manuais das parcelas.

Não precisa de botão “Simular” se o valor já estiver preenchido. Com valor vazio, mostrar a Projeção **sem** overlay (estado atual) + hint “Informe o valor para ver o impacto”.

---

## APIs (MVP — já existem)

Base: `/api/v1` · Bearer Sanctum.

### 1. Matriz (obrigatório)

```http
GET /api/v1/dashboard/projecao-faturas?mes=8&ano=2026
```

Contrato completo: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md).

- `mes` / `ano` da query = **mês de referência** (default: atual). Colunas = **mês anterior + 12 à frente**.
- Ao mudar o seletor de competência da tela, **refetch** desta API (o overlay é reaplicado em cima).
- **Não** somar `/transacoes/listar` no front para “o que o responsável já deve”. Isso já está em:

| Pergunta | Onde na Projeção |
|----------|------------------|
| Fatura do **cartão** no mês (todo mundo) | `por_cartao[]` filtrado por `cartao_id` → `valores[i].total` |
| O que **este responsável** já deve **neste cartão** | `por_cartao_responsavel[]` do cartão → `por_responsavel[]` do id → `valores[i].total` |
| O que **este responsável** já deve **em todos os cartões** | `por_responsavel[]` do id → `valores[i].total` |
| Outros cartões (mesmo responsável) | `geral − neste_cartão` naquela coluna |
| Limite / em uso / livre | `por_cartao[].uso_limite` e `valores[i].em_uso` / `livre` |

`realizado` vs `projetado` da base **não** precisa ser reclassificado no overlay: a parcela simulada entra como **`simulado`** (terceira fatia), não como realizado.

### 2. Lookups do form

```http
GET /api/v1/auth/me
GET /api/v1/pessoas/pessoas-list
GET /api/v1/pessoas/listar/{id}          # responsavel_id / nome_completo quando precisar
GET /api/v1/transacoes/lookups           # default_responsavel_id, cartoes (ciclo + cores)
GET /api/v1/responsaveis/responsaveis-list
```

Cartões: preferir os da Projeção (`por_cartao`) para ter `pessoa_id` + ciclo + limite na mesma carga. Lookups de transação **não** mandam `pessoa_id`.

### 3. Atalhos (não simulam)

Depois de ver o impacto, links para telas já existentes (competência = coluna de referência ou a 1ª parcela):

- Fatura do responsável: `/projecao/responsaveis/:id/fatura?mes=&ano=`
- Visualizar responsável: `/responsaveis/:id?mes=&ano=`
- Projeção “de verdade” (sem overlay): `/projecao`

---

## Como aplicar o overlay (regra obrigatória)

**Não mutar** o JSON original da Projeção. Clonar as linhas usadas na UI.

### 1) Competência da 1ª parcela (ciclo do cartão)

Igual ao cadastro de compra (`Cartao::periodoFaturaParaData`):

- `dia_limite` = `por_cartao[].dia_limite_fatura` (ou lookups).
- Se `data.day <= dia_limite` (e `dia_limite` limitado aos dias do mês) → fatura do **mês da data**.
- Se `data.day > dia_limite` → fatura do **mês seguinte**.
- Se `dia_limite` for null → mês calendário da data.

Parcelas 2..N avançam **+1 mês** a partir dessa competência (não a partir da data civil).

Ex.: compra em 24/08/2026, fecha dia 5 → 1ª parcela em **Set/2026**; 10x termina em **Jun/2027**.

### 2) Valores das parcelas

- Default: `valor_compra / N` com 2 casas; **resto de centavos na última**.
- Se o usuário editou `parcelas[]`, usar esses valores (soma = total).

### 3) Encaixe nas 13 colunas

Para cada parcela `k` (1..N):

1. Competência = 1ª + `(k-1)` meses.
2. Achar índice `i` em `colunas[]` (`mes` + `ano`).
3. Se **não** estiver na janela: contar em `parcelas_fora_da_janela` (aviso na UI). **Não** inventar coluna extra no MVP.
4. Se estiver: `delta[i] += valor da parcela k`.

A janela da Projeção é 13 meses. 10x cabe na maioria dos casos; 36x provavelmente estoura — mostrar:

> 8 parcelas caem depois de {último label}. Troque a referência ou registre a compra para ver o restante na Projeção.

### 4) Onde somar o `delta[i]`

Seja `C` o cartão e `R` o responsável selecionados.

| Série | Antes (API) | Depois |
|-------|-------------|--------|
| Fatura do cartão C | `por_cartao[C].valores[i].total` | `+ delta[i]` |
| Responsável R **neste cartão** | `por_cartao_responsavel[C].por_responsavel[R].valores[i].total` | `+ delta[i]` |
| Responsável R **geral** (todos os cartões) | `por_responsavel[R].valores[i].total` | `+ delta[i]` |
| Responsável R **outros cartões** | `geral − neste_cartão` | **igual ao antes** (a simulação não muda outros cartões) |
| Demais responsáveis / demais cartões | inalterados | inalterados |

Recalcular na célula do cartão, **depois** do overlay:

- `em_uso_depois = total_depois`
- `livre_depois = limite − em_uso_depois` (`null` se sem limite)
- `%` de uso / livre
- Split `meu` / `outros`: se `R` é o `responsavel_eu_id`, o delta entra em `meu`; senão em `outros`

Na linha do responsável, `percentual_participacao` **depois** = `total_R_depois / total_do_mês_depois` (denominador = totais daquela visão: global ou daquele cartão).

### 5) Semântica visual da célula

Cada célula impactada mostra **antes → depois** e o **delta**:

```
R$ 1.050,90  →  R$ 1.350,90
         + R$ 300,00  simulado
```

Em mobile: `1.350,90` em destaque e `+300` em badge.

Cor do delta: distinta de `projetado` da Projeção (ex. azul/roxo “simulado”, vs tracejado de projetado legado).

Células sem delta: iguais à Projeção (só o valor atual).

---

## Layout sugerido

### 1) Form (sticky no desktop)

Titular · Cartão · Responsável · Valor · Parcelas · Data · preview da 1ª/última fatura.

Seletor de **competência de referência** (mês/ano) igual à Projeção — desloca a janela de 13 meses; o overlay recalcula o encaixe.

### 2) Cards de impacto (obrigatório)

Três cards, sempre **qtd de parcelas na janela** + valores. Competência = coluna `referencia: true` **ou** a competência da **1ª parcela** (preferir a 1ª parcela; subtexto com o label).

Usar a competência da **1ª parcela simulada** para o “quanto cai no primeiro mês”, e a **soma das 13 colunas** para o total na janela.

| Card | Antes | Depois | Subtexto |
|------|-------|--------|----------|
| **Neste cartão** (responsável) | soma / célula do responsável **neste** `cartao_id` | + simulado | “O que {nome} já deve neste cartão + esta compra” |
| **Geral** (responsável, todos os cartões) | linha `por_responsavel` | + simulado | “Fatura virtual dele no mês — inclui outros cartões” |
| **Fatura do cartão** (todo mundo) | `por_cartao.total` / `uso_limite` | + simulado | Limite · em uso % · livre % **depois** |

O card **Geral** é o que evita “ele deve demais ao titular”:

```
Ago/2026 · Maysa
Neste cartão     R$ 800  →  R$ 1.100   (+300)
Outros cartões   R$ 450  →  R$ 450     (inalterado)
────────────────
Total dela       R$ 1.250 → R$ 1.550
```

Se `eh_eu` (responsável é o Eu): o card Geral pode se chamar **Meu total** (você não se paga; ainda assim mostra o comprometimento).

Alertas (não bloqueiam):

- `% em uso do limite` depois > 80% → âmbar/vermelho no card do cartão.
- Total **geral** do responsável (não-Eu) no mês da 1ª parcela “alto” é só informativo; **não** inventar teto.

### 3) Tabela — reusar Projeção (obrigatório)

**Não** redesenhar a matriz do zero. Reusar os componentes da tela de Projeção, com três recortes:

#### A. Cartão selecionado (foco)

- Uma linha: o cartão escolhido, 13 meses, células com overlay.
- Expandido (Tabela 3 da Projeção): `por_responsavel` **daquele cartão**, destacando a linha de `R`.
- Demais responsáveis no mesmo cartão: valores **sem** delta (a compra não é deles).
- Cabeçalho: chip + limite / em uso / livre **depois** do overlay.

#### B. Responsável — impacto geral

- Uma linha: o responsável escolhido em **todos os cartões** (`por_responsavel`), 13 meses, com overlay.
- Opcional expandido: breakdown **por cartão** naquele mês:

```
Set/2026 · Maysa
  [chip Nubank]     800 + 300 simulado = 1.100   ← cartão da simulação
  [chip Sofisa]     450                         ← outros (só leitura)
  Total             1.550
```

Fonte do breakdown: `por_cartao_responsavel[]` (um grupo por cartão) → achar `por_responsavel` com o id; somar `delta` **só** no `cartao_id` simulado.

#### C. (Opcional) Visão completa

Toggle “Ver todos os cartões / responsáveis” = Projeção inteira com o overlay só nas linhas C e R. Default **desligado** (a tela é a simulação, não o relatório global).

### 4) Timeline das parcelas simuladas

Lista ou chips: `1/10 Set/2026 R$ 300` … `10/10 Jun/2027 R$ 300`.

- Dentro da janela: clicável → scroll/destaque da coluna.
- Fora da janela: cinza + “fora da projeção”.

### 5) CTA registrar (secundário)

A simulação **não** cadastra. Botão **Registrar esta compra** (opcional, recomendado) navega para o form já existente de compras com query/state:

`cartao_id`, `responsavel_id`, `valor_compra`, `parcelas_total`, `data`, e `parcelas[]` se o usuário ajustou.

Lá o usuário completa estabelecimento, origem, final do cartão, etc. ([`frontend-prompt-compras.md`](frontend-prompt-compras.md)).

Não chamar `POST /transacoes/cadastrar` desta tela.

---

## Endpoint recomendado (ainda não existe)

Para não repetir a regra de ciclo/centavos no front e devolver `antes` / `depois` prontos:

```http
POST /api/v1/dashboard/simular-compra
```

```json
{
  "pessoa_id": 1,
  "cartao_id": 2,
  "responsavel_id": 15,
  "valor_compra": "3000,00",
  "parcelas_total": 10,
  "data": "2026-08-24",
  "mes": 8,
  "ano": 2026,
  "parcelas": [
    { "parcela": 1, "valor": "300,00" }
  ]
}
```

`parcelas[]` opcional (senão o back divide igual o create). `mes`/`ano` = janela da Projeção.

### Shape sugerido (`data`)

Envelope igual aos outros: `{ data, status, message }`.

```json
{
  "referencia": { "mes": 8, "ano": 2026 },
  "colunas": [],
  "compra": {
    "valor_compra": 3000.0,
    "parcelas_total": 10,
    "data": "2026-08-24",
    "primeira_competencia": { "mes": 9, "ano": 2026, "label": "Set/2026" },
    "ultima_competencia": { "mes": 6, "ano": 2027, "label": "Jun/2027" },
    "valor_parcela": 300.0,
    "parcelas": [
      { "parcela": 1, "valor": 300.0, "mes": 9, "ano": 2026, "chave": "2026-09", "na_janela": true }
    ],
    "parcelas_na_janela": 10,
    "parcelas_fora_da_janela": 0
  },
  "selecao": {
    "pessoa_id": 1,
    "pessoa_nome": "Leonardo da Silva Ferreira",
    "cartao_id": 2,
    "cartao_nome": "Nubank",
    "responsavel_id": 15,
    "responsavel_nome": "Maysa Araujo da Conceicao",
    "eh_eu": false
  },
  "impacto_referencia": {
    "neste_cartao": {
      "antes": 800.0,
      "simulado": 300.0,
      "depois": 1100.0
    },
    "outros_cartoes": {
      "antes": 450.0,
      "simulado": 0,
      "depois": 450.0
    },
    "geral_responsavel": {
      "antes": 1250.0,
      "simulado": 300.0,
      "depois": 1550.0
    },
    "fatura_cartao": {
      "antes": 2100.0,
      "simulado": 300.0,
      "depois": 2400.0,
      "limite": 8000.0,
      "percentual_em_uso_depois": 30.0,
      "livre_depois": 5600.0
    }
  },
  "projecao": { }
}
```

`projecao`: **o mesmo shape** de `GET /projecao-faturas`, já com células enriquecidas, **ou** o shape original + arrays `delta_por_coluna[]` alinhados a `colunas[]`. Preferir:

```json
"deltas": {
  "por_coluna": [0, 0, 300, 300]
}
```

e o front soma. Assim a Projeção “pura” continua cacheável.

**Regras (quando existir):** mesmo ciclo do create; só `tipo=purchase` na base; não persistir; 404 se cartão/responsável/pessoa não forem do user.

> **Status atual:** este endpoint **não está implementado**. Entregar o MVP com overlay no front. Quando existir, o form passa a `POST` e os cards leem `impacto_referencia`.

---

## Estados

- Loading: skeleton do form + 3 cards + tabela (igual Projeção).
- Valor vazio: Projeção real, cards com “—” / só o **antes**, hint para preencher valor.
- Sem cartão do titular: empty + CTA cartões.
- 404 responsável/pessoa: toast + voltar aos defaults.
- Parcelas fora da janela: banner, não quebrar a tabela.
- Responsivo: form empilhado; tabela com scroll horizontal e 1ª coluna (nome) fixa — igual Projeção.

---

## O que **não** fazer

- Não cadastrar transação nesta tela.
- Não somar `/transacoes/listar` para montar a dívida atual.
- Não usar `GET /faturas/listar/{id}` como fonte da matriz.
- Não tratar titular e responsável como a mesma coisa: pode simular compra no cartão da Maysa no responsável Eu (e vice-versa).
- Não esconder o responsável `Eu`.
- Não misturar este menu com Projeção (item separado).
- Não recalcular ciclo com “sempre mês calendário” se o cartão tem `dia_limite_fatura`.

---

## Checklist de aceite

- [ ] Menu novo **Simulador** (rota `/simulador`), separado de Projeção
- [ ] Abre com titular principal + responsável padrão + 1º cartão daquele titular
- [ ] Trocar titular filtra cartões e reaplica responsável padrão (ainda editável)
- [ ] Trocar responsável recalcula: dívida **neste cartão** + **geral** (outros cartões)
- [ ] Valor, parcelas 1..36, data; split igual + centavos na última; parcelas editáveis
- [ ] Overlay usa `GET /dashboard/projecao-faturas` (clone, não mutar)
- [ ] 1ª competência respeita `dia_limite_fatura`; demais +1 mês
- [ ] 3 cards: neste cartão (responsável) · geral (responsável) · fatura do cartão / limite
- [ ] Card geral mostra **neste cartão vs outros cartões** (outros sem delta)
- [ ] Tabelas reusam a Projeção: cartão focado (expandido por responsável) + linha geral do responsável
- [ ] Células: antes → depois + badge do simulado
- [ ] Timeline das N parcelas; aviso se alguma sair da janela de 13 meses
- [ ] Não persiste; CTA opcional para o form de compras com os dados preenchidos
- [ ] Deep-link por query (`pessoa_id`, `cartao_id`, `responsavel_id`, valor, parcelas, data)
- [ ] Empty / loading / mobile / seletor de competência refetcha a base

---

## Fora de escopo

- Juros, IOF, rotativo, “melhor cartão para parcelar”
- Simular duas compras ao mesmo tempo
- Editar/excluir compras reais nesta tela
- Endpoint `POST /dashboard/simular-compra` (back futuro; front MVP não depende dele)
- Cadastro de titular/responsável/cartão (só selects + link se empty)
