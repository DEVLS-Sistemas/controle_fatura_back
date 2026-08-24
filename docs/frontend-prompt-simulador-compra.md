# Prompt — Frontend: Simulador de compra

Use este prompt no repositório do frontend para a tela **Simulador de compra** (`/simulador`).

Se a tela **já existe e está poluída** (abre já mostrando Projeção / faturas / cards / tabelas): este arquivo é o **ajuste**. Prioridade: **estado inicial limpo** + **cartão filtrado pelo titular**.

Pergunta que a tela responde **depois** de simular:

> Se eu comprar R$ 3.000 em 10x neste cartão, neste responsável, como ficam minhas próximas faturas?

Não grava transação.

Lookups de cartão agora trazem `pessoa_id` / `pessoa_nome`. Use `GET /cartoes/cartoes-list?pessoa_id=` ou filtre `lookups.cartoes` pelo titular. `GET /dashboard/projecao-faturas` **somente após o usuário simular**.

Specs: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md) · [`frontend-prompt-compras.md`](frontend-prompt-compras.md) · [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md).

---

## Ajuste urgente (tela já implementada)

A implementação atual erra o fluxo: ao abrir `/simulador` já dispara a Projeção e renderiza faturas, limite, Eu vs Outros, tabelas de 13 meses, etc.

**Corrigir assim:**

1. **Abrir = só o formulário.** Título curto + 4 campos + botão **Simular**. Zero tabela, zero card de fatura, zero seletor de competência da Projeção, zero timeline.
2. **Não** chamar `GET /dashboard/projecao-faturas` no mount. Fonte do form: `GET /transacoes/lookups` (cartões já vêm com `pessoa_id`) e/ou `GET /cartoes/cartoes-list?pessoa_id=`.
3. Resultados **só depois** de titular (se 2+) + cartão + responsável + valor > 0 + parcelas + clique em **Simular**.
4. Se o usuário mudar qualquer campo depois, **esconder os resultados** até simular de novo.
5. **Cartão filtrado pelo titular.** Dois Nubank (Leonardo e Maysa) nunca no mesmo select. Titular visível quando houver 2+ pessoas.
6. Data default = hoje, só em “opções” se quiser.
7. **Resultado sem poluição.** Depois de Simular: (1) um número enorme = o que **esta compra** coloca na fatura da 1ª parcela; (2) resumo do responsável: já deve no mês + esta parcela = total. Tabelas de 13 meses e limite **não** na primeira dobra.

Não é refator da regra de overlay — é esconder tudo que não é o form até haver simulação.

---

## Duas fases (obrigatório)

```
┌─────────────────────────────────────────┐
│  FASE 1 — Idle (default ao abrir)       │
│  Só o form. Sem GET de projeção.        │
└─────────────────┬───────────────────────┘
                  │ Simular (form válido)
                  ▼
┌─────────────────────────────────────────┐
│  FASE 2 — Resultado                     │
│  GET projecao-faturas + overlay         │
│  Cards + recorte da matriz (focado)     │
└─────────────────────────────────────────┘
```

| Fase | O que aparece | O que **não** aparece |
|------|----------------|------------------------|
| **Idle** | Título, texto de 1 linha, form, botão Simular | Projeção, faturas, limite, cards, timeline |
| **Resultado** | Form compacto + **hero da parcela** + **resumo do responsável** | Três cards iguais, Projeção inteira, 13 meses na primeira dobra |

Empty da fase idle (centro ou abaixo do form, discreto):

> Escolha o cartão, o responsável, o valor e as parcelas para ver o impacto nas próximas faturas.

---

## Objetivo

1. Menu **novo** (`/simulador`), irmão da Projeção — não é aba dela.
2. Começar **simples**. Simular é uma ação explícita.
3. Só então reusar **recortes** visuais da Projeção (não a tela inteira).
4. A dívida é de **um** responsável. Default carregado (Eu / padrão do titular), sempre trocável.
5. Resultado mostra (a) o que ele já deve **neste cartão** + a compra e (b) o **geral** dele nos outros cartões, para não dever demais ao titular.

---

## Conceitos (não misturar)

| Conceito | Significado | Nesta tela |
|----------|-------------|------------|
| **Titular (Pessoa)** | Dono do plástico | default silencioso; filtra cartões se o campo existir |
| **Cartão** | Grupo (Nubank, Sofisa…) | campo principal do form |
| **Responsável** | Quem **deve** a compra | campo principal do form |
| **Projeção** | Faturas reais + parcelas já lançadas | **só na fase resultado**, recortada |
| **Simulação** | Overlay em memória | some ao sair / ao editar o form |

Não é rateio. Não é a [fatura do responsável](frontend-prompt-fatura-responsavel.md).

---

## Menu / rotas

```
/simulador
/simulador?cartao_id=2&responsavel_id=15&valor=3000&parcelas=10
```

Deep-link **pré-preenche o form** e **permanece na fase idle**, a menos que `valor` e `parcelas` também venham na query — aí pode ir direto à fase resultado (opcional). Sem `valor` na URL: **não** simular sozinho.

---

## Form (fase 1 — o que a tela **é** ao abrir)

Layout: card único, centralizado ou no topo, bastante respiro. Não sticky com metade da Projeção atrás.

Título: **Simular compra**

Subtítulo (uma linha):

> Veja como fica a fatura **antes** de lançar a compra.

### Campos visíveis (só estes)

Com **2+ titulares** na conta, o select de **Titular vem primeiro** (não é opcional). Sem ele o Nubank do Leonardo e o da Maysa aparecem iguais.

| Campo | UI | Obrigatório p/ Simular |
|-------|-----|-------------------------|
| **Titular** | select de pessoas (`pessoas-list`). Default = `eh_principal`. **Sempre visível se houver 2+ pessoas.** | sim (quando o campo existe) |
| **Cartão** | select **filtrado pelo titular** (ver abaixo) | sim |
| **Responsável** | select (default Eu / padrão do titular) | sim |
| **Valor** | money BRL, vazio ao abrir | sim (> 0) |
| **Parcelas** | select **1..36**, default `1` | sim |

Uma pessoa só: omitir o select de titular (já está no default) — o filtro de cartão continua pelo `pessoa_id` principal.

Botão primário: **Simular**. Desabilitado até os campos visíveis válidos.

### Fora do form principal

| Campo | Onde |
|-------|------|
| Data | Default **hoje**. Collapsible “Data da compra” (ciclo do cartão). Não é campo de primeira linha. |
| Competência da Projeção | **Não** na fase idle. Na fase resultado, se existir, secundário. |
| Estabelecimento / categoria / origem | Só no CTA “Registrar esta compra”. |
| Preview 1ª/última fatura | **Só depois** de Simular (ou, no máximo, uma linha pequena no form quando valor+parcelas+cartão já estão preenchidos — sem tabela). |
| Accordion das N parcelas | Só na fase resultado, ou collapsed no form. Não abrir 10 inputs ao carregar a página. |

### Cartões **só do titular** (obrigatório)

Dois Nubank na mesma conta (Leonardo vs Maysa) são cartões **diferentes**. O select **nunca** lista os dois ao mesmo tempo.

Fonte (já traz titular — **não** usar a Projeção para isso):

```http
GET /api/v1/transacoes/lookups
# lookups.cartoes[] → pessoa_id, pessoa_nome, pessoa_eh_principal, dia_limite_fatura, cores

GET /api/v1/cartoes/cartoes-list?pessoa_id={titular.id}
# já vem filtrado no back
```

Regra de filtro no front (se usar lookups):

```
cartoes.filter(c => c.pessoa_id === titular.id)
```

- Titular Leonardo → só o Nubank (e demais) com `pessoa_id` dele. **Não** entra o Nubank da Maysa.
- Titular Maysa → só os cartões dela.
- Cartão com `pessoa_id === null` (legado): só no titular **principal**.
- Ao **trocar o titular**: recarregar/filtrar o select e selecionar o primeiro cartão da nova lista. Se a lista ficar vazia: “Nenhum cartão deste titular.”
- Label do option: `Nubank` basta **depois** de filtrar. Se em algum outro select da app listar todos, usar `Nubank · Maysa` (`pessoa_nome`) para não confundir.

**Não** listar todos os cartões da conta “porque o lookup não tinha pessoa_id”. Agora tem. Filtrar é regra de produto, não fallback.

Preferência: `GET /cartoes/cartoes-list?pessoa_id=` para o select já nascer filtrado.

### Defaults do form (lookups, não Projeção)

Ao montar:

```http
GET /api/v1/transacoes/lookups
GET /api/v1/pessoas/pessoas-list          # só se 2+ titulares
GET /api/v1/responsaveis/responsaveis-list  # se lookups.responsaveis não bastar
GET /api/v1/auth/me                       # pessoa_id principal
```

| Campo | Default |
|-------|---------|
| Titular | Pessoa `eh_principal` (`GET /auth/me` → `pessoa_id` ou `pessoas-list`) |
| Responsável | `lookups.default_responsavel_id` (Eu). Se o titular não for o principal: `pessoas-list[].responsavel_id` ou match de nome. Sempre editável. |
| Cartão | Primeiro cartão **daquele titular** (`pessoa_id`). Nunca o da outra pessoa. |
| Parcelas | `1` |
| Valor | vazio |
| Data | hoje (escondida) |

Empty cartões: “Nenhum cartão cadastrado.” + link para cartões. **Ainda sem** Projeção.

### Não fazer no form

- Não buscar projeção para popular o select de cartão.
- Não mostrar limite / em uso / livre antes de simular.
- Não auto-simular no debounce de cada keypress. **Botão Simular.**

---

## Fase 2 — depois de Simular

Só agora:

```http
GET /api/v1/dashboard/projecao-faturas?mes=&ano=
```

`mes`/`ano` default = competência da **1ª parcela** (ciclo do cartão + data). Overlay em clone do JSON (não mutar).

O form **encolhe** no topo (titular, cartão, responsável, valor, Nx) + **Nova simulação** (volta à fase idle, limpa resultado). Mudar um campo → esconde o resultado até Simular de novo.

**Não** abrir 3 cards iguais + 2 tabelas de 13 meses. O usuário não sabe onde olhar. A fase 2 tem **dois blocos obrigatórios**, nesta ordem, e o resto vai para “ver mais”.

Competência de tudo abaixo = **mês da 1ª parcela desta compra** (a fatura em que a parcela atual entra). Ex.: compra 24/08, fecha dia 5 → **Set/2026**.

---

### Bloco 1 — O que esta compra coloca na fatura (hero)

Um único painel, muito ar. Sem tabela, sem %. Sem “antes → depois” ainda.

```
Simulação de compra
Nubank  ·  10x  ·  parcela 1 de 10

Esta fatura     Setembro/2026

Entra nesta fatura
R$ 300,00
```

Regras de tipografia (obrigatório):

| Elemento | Visual |
|----------|--------|
| Eyebrow | `Simulação de compra` — pequeno, uppercase / muted |
| Contexto | cartão · Nx · `parcela 1 de {N}` — uma linha, secundário |
| Mês | label da competência (`Setembro/2026` ou `Set/2026`) — médio, nítido |
| Label do valor | `Entra nesta fatura` (ou `Valor desta parcela`) |
| **Valor** | **o maior número da tela** — 2–3× o body, peso bold, cor de destaque (primária). Moeda BRL com 2 casas |

O número é **somente o valor da parcela desta simulação** que cai nesse mês (`delta` da 1ª parcela — em geral `valor_compra / N`). **Não** misturar com o que já estava na fatura. É “quanto **dessa compra** entra”.

Se N = 1: `R$ 3.000,00` e esconder “parcela 1 de 1” ou mostrar `à vista`.

Uma linha de apoio, pequena, abaixo do número:

`R$ 3.000,00 em 10x neste cartão`

Não colocar limite / em uso / Eu vs Outros neste bloco.

---

### Bloco 2 — O responsável neste mês (resumo)

Logo **abaixo** do hero, um card mais baixo. Continua simples: duas linhas + total. Sem matriz.

Pergunta: *quanto {responsável} já deve neste mês, e com esta parcela?*

**Fonte do “já deve”:** `por_responsavel[R].valores[i].total` na competência da 1ª parcela = dívida **geral** dele (todos os cartões). É o que ele vai ter que acertar com o titular.

```
Maysa  ·  Set/2026

Já deve neste mês          R$ 1.250,00
+ Esta parcela               R$   300,00
────────────────────────────────────────
Passa a dever              R$ 1.550,00
```

- `Já deve` = total do responsável naquele mês **sem** o overlay (antes).
- `Esta parcela` = o mesmo valor do hero.
- `Passa a dever` = soma. **Segundo maior número da tela** (menor que o hero, maior que o body).

Uma linha extra, menor, só se ele também gasta em **outro** cartão (`outros_cartoes > 0`):

`Neste cartão R$ 800  ·  Outros cartões R$ 450`

Se `eh_eu`: título **Você neste mês** (não “a receber”).

Não repetir o hero. Não três cards lado a lado com o mesmo peso.

---

### O que **não** vai na primeira dobra

Tudo isso existe, mas **fechado** (accordion / “Ver detalhes”) **depois** dos dois blocos:

| Conteúdo | Onde |
|----------|------|
| Fatura do **cartão** (todo mundo) + limite / em uso % | “Como fica a fatura do cartão” |
| Matriz 13 meses do cartão | “Próximas faturas deste cartão” |
| Matriz 13 meses do responsável | “Como fica o responsável nos próximos meses” |
| Timeline `1/10 Set … 10/10 Jun` | dentro do accordion do cartão |
| Toggle “todos os cartões” | default **off**, nunca na abertura |

Se abrir o detalhe da fatura do cartão, aí sim um mini-resumo:

```
Fatura Nubank  Set/2026
Antes R$ 2.100   + parcela R$ 300   = R$ 2.400
Limite R$ 8.000 · em uso 30%
```

Alerta só se `% em uso` depois > 80% — um banner discreto no accordion, não no hero.

### CTA (secundário, depois do bloco 2)

**Registrar esta compra** → form de compras com os dados. Não cadastra aqui.

**Nova simulação** / voltar — limpa resultado, form de novo. Não compete visualmente com os dois números.

---

## Overlay (inalterado — só roda na fase 2)

Contrato da Projeção: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md).

**Não** somar `/transacoes/listar`. Dívida atual:

| Pergunta | Campo |
|----------|--------|
| Fatura do cartão | `por_cartao[C].valores[i].total` |
| Responsável neste cartão | `por_cartao_responsavel[C].por_responsavel[R].valores[i].total` |
| Responsável geral | `por_responsavel[R].valores[i].total` |
| Outros cartões | `geral − neste_cartão` |
| Limite | `uso_limite` / `valores[i].em_uso` |

Parcela simulada = fatia **`simulado`**, não `realizado`.

### Ciclo (1ª parcela)

`dia_limite` do cartão (`lookups.cartoes[].dia_limite_fatura`):

- `data.day <= dia_limite` → fatura do mês da data
- `data.day > dia_limite` → mês seguinte
- `dia_limite` null → mês calendário

Parcelas 2..N: **+1 mês** na competência. Ex.: 24/08, fecha dia 5, 10x → 1ª **Set/2026**, última **Jun/2027**.

Split: `valor / N`, centavos na última. Soma deve bater com o total (tol. R$ 0,01).

### Onde somar `delta[i]`

| Série | Depois |
|-------|--------|
| Fatura do cartão C | `+ delta[i]` |
| Responsável R neste cartão | `+ delta[i]` |
| Responsável R geral | `+ delta[i]` |
| R em outros cartões | inalterado |
| Demais linhas | inalteradas |

Depois: `em_uso`, `livre`, `%`; delta em `meu` se `R === responsavel_eu_id`, senão `outros`.

---

## Endpoint recomendado (ainda não existe)

O front **não espera** este POST. Continua overlay no cliente após o GET da Projeção.

```http
POST /api/v1/dashboard/simular-compra
```

Ver revisão anterior do prompt se for implementar no back depois. Status: **não implementado**. Sem bloqueio.

---

## Estados

| Estado | UI |
|--------|----|
| Loading inicial | Skeleton **só do form** (4 campos). Não skeleton de tabela. |
| Idle | Form + empty discreto. |
| Simular (request) | Loading nos resultados (abaixo do form). Form permanece. |
| Resultado | Form compacto + hero (parcela) + resumo do responsável. Tabelas só em “ver detalhes”. |
| Erro do GET | Toast + permanece no form; não deixar lixo de tabela. |
| Sem cartão | Empty no form + CTA cartões. |
| Editar após resultado | Resultados ocultos / “Simule de novo”. |

Responsivo: form empilhado. Tabela só na fase 2, scroll horizontal, 1ª coluna fixa.

---

## O que **não** fazer

- Não abrir `/simulador` já com Projeção / faturas / limite / 13 meses.
- Não chamar `GET /projecao-faturas` no mount (nem “para ter os cartões”).
- Não auto-simular com valor vazio “só para mostrar o estado atual”. Isso é a tela **Projeção**.
- Não cadastrar transação nesta tela.
- Não somar `/transacoes/listar` para a dívida atual.
- Não tratar titular e responsável como a mesma coisa.
- Não esconder o responsável `Eu`.
- Não abrir o resultado com 3 cards + 2 tabelas de 13 meses. O olhar vai no **valor da parcela**, depois no **já deve + parcela** do responsável.

---

## Checklist de aceite

- [ ] `/simulador` abre **limpo**: titular (se 2+ pessoas) + cartão **só desse titular** + responsável + valor + parcelas + **Simular**
- [ ] Select de cartão **não** lista o Nubank de outro titular (filtrar `pessoa_id` ou `cartoes-list?pessoa_id=`)
- [ ] Trocar titular esvazia/refaz o select de cartão e o responsável padrão
- [ ] Zero request de projeção até clicar Simular
- [ ] Defaults: responsável padrão + primeiro cartão; valor vazio
- [ ] Titular/data fora da primeira dobra (titular só se 2+ pessoas)
- [ ] Simular exige os 4 campos; aí sim GET + overlay
- [ ] Fase 2, **primeira dobra** (nesta ordem, sem tabela):
  - [ ] Hero: competência da 1ª parcela + valor **desta parcela** em fonte bem grande (`Entra nesta fatura`)
  - [ ] Resumo do responsável: já deve no mês (geral) + esta parcela = passa a dever
- [ ] Limite, 13 meses e fatura do cartão só em accordion “Ver detalhes”
- [ ] Não renderizar a Projeção completa por default
- [ ] Editar o form esconde o resultado até Simular de novo
- [ ] Ciclo `dia_limite_fatura`
- [ ] Não persiste; CTA registrar opcional depois do resumo
- [ ] Loading/empty/erro/mobile sem poluir a fase idle

---

## Fora de escopo

- Juros, IOF, rotativo, “melhor cartão”
- Duas compras ao mesmo tempo
- Editar compras reais
- `POST /dashboard/simular-compra`
- Cadastro de titular/responsável/cartão no próprio simulador
