# Prompt — Frontend: Posso comprar?

Use este prompt no repositório do frontend. **Não é tela nova.** Encaixa no **Simulador de compra** (`/simulador`) que já existe.

A tela do simulador continua igual na fase idle (form + botão). Este prompt muda o **significado do resultado**: depois de analisar as próximas faturas, a primeira resposta é um veredito em três níveis — não uma tabela.

Specs base (não substituir, só complementar): [`frontend-prompt-simulador-compra.md`](frontend-prompt-simulador-compra.md) · [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md). Overlay, ciclo `dia_limite`, titular/cartão/responsável: **inalterados**.

---

## Pergunta que a tela responde

O usuário informa, por exemplo:

> Quero comprar um celular de R$ 2.500 em 10x.

O sistema olha as **próximas faturas daquele cartão** (projeção + overlay da parcela) e responde **uma** destas:

| Nível | UI | Título |
|-------|-----|--------|
| `baixo` | 🟢 verde | **Baixo impacto** |
| `moderado` | 🟡 âmbar | **Impacto moderado** |
| `alto` | 🔴 vermelho | **Compra compromete demais os próximos meses** |

Isso é conselho, não trava. Mesmo no vermelho o CTA **Registrar esta compra** permanece.

---

## O que mudar (e o que não mudar)

| Já existe | Continua | Passa a ser |
|-----------|----------|-------------|
| Rota `/simulador` | sim | — |
| Form (titular, cartão, responsável, valor, parcelas) | sim | Título/menu **Posso comprar?** |
| GET projeção **só depois** do clique | sim | — |
| Overlay cliente (sem `POST`) | sim | Fonte do veredito |
| Hero da parcela + resumo do responsável | sim | **Abaixo** do veredito |
| Tabelas de 13 meses | accordion | — |

Não criar `/posso-comprar`. Não chamar endpoint novo. Não usar LLM para classificar.

---

## Menu / copy do form (fase idle)

- Item de menu e H1: **Posso comprar?**
- Subtítulo (uma linha):

  > Informe o valor e as parcelas. O sistema olha as próximas faturas e diz se a compra cabe.

- Empty discreto:

  > Escolha o cartão, o responsável, o valor e as parcelas para ver se a compra cabe.

- Botão primário: **Posso comprar?** (o antigo **Simular** é a mesma ação — trocar o label, não o fluxo).
- Deep-link inalterado: `/simulador?cartao_id=&responsavel_id=&valor=2500&parcelas=10`

Resto do form: igual ao prompt do simulador (cartão filtrado pelo titular, valor vazio ao abrir, não GET de projeção no mount).

---

## Fase 2 — ordem da primeira dobra (obrigatório)

```
1. Veredito  ← novidade (o olhar vai AQUI)
2. Hero da parcela (“Entra nesta fatura”)
3. Resumo do responsável (já deve + parcela = passa a dever)
4. Accordion “Ver detalhes” (fatura do cartão, 13 meses, limite)
```

O veredito **substitui** o hero como peça principal. O valor da parcela continua visível, mas menor que o título do nível.

### Bloco 0 — Veredito

Um card só, muito ar. Sem tabela.

```
🟢  Baixo impacto
    Pode comprar

    A parcela de R$ 250,00 cabe nas próximas 10 faturas.

    Nubank  ·  R$ 2.500,00 em 10x  ·  Set/2026 → Jun/2027
```

Vermelho (exemplo celular apertando o limite):

```
🔴  Compra compromete demais os próximos meses
    Melhor não agora

    Em mar/2027 o Nubank iria a 94% do limite
    (R$ 7.520 de R$ 8.000).

    Nubank  ·  R$ 2.500,00 em 10x  ·  Set/2026 → Jun/2027
```

| Elemento | Visual |
|----------|--------|
| Semáforo | bolinha / ícone 🟢🟡🔴 **e** fundo suave da cor (não só emoji — precisa de cor + texto por acessibilidade) |
| Título | o maior texto do card (`Baixo impacto` / `Impacto moderado` / `Compra compromete demais os próximos meses`) |
| Eyebrow | `Pode comprar` / `Dá para comprar, com ressalva` / `Melhor não agora` — muted, uma linha |
| Frase | **uma** frase pronta (catálogo abaixo). Não improvisar. |
| Contexto | cartão · valor · Nx · 1ª → última competência |
| Motivos | 1–3 chips (`Limite 41%` · `Parcela = 12% da fatura` · `10 meses`) — secundários |

Abaixo do card, **faixa das N competências da compra** (não as 13 da Projeção):

```
Set   Out   Nov   Dez   Jan   Fev   Mar   Abr   Mai   Jun
 32%   35%   38%   40%   41%   44%   94%   48%   45%   42%
  ·     ·     ·     ·     ·     ·     ●     ·     ·     ·
```

- Cada coluna = um mês em que cai parcela.
- Número = `% do limite` **depois** do overlay (`valores[i].percentual_utilizado`). Sem limite: mostrar o `total` depois em R$ (curto, `1,2 mil`).
- Cor da coluna = nível **daquele mês** (verde / âmbar / vermelho), não o veredito global.
- Mês que definiu o veredito (`mes_critico`): ênfase (anel, peso, ou ponto ●).
- Clique numa coluna **não** navega; pode só destacar a frase se quiser. Sem modal.

Mobile: a faixa scrolla na horizontal; o card do veredito empilha.

---

## De onde vêm os números

Depois do clique, o mesmo fluxo de hoje:

1. `GET /api/v1/dashboard/projecao-faturas?mes=&ano=` com a competência da **1ª parcela**.
2. Overlay da parcela em clone do JSON (não mutar).
3. **Calcular o veredito no cliente** com as regras deste arquivo.

Janela `W` = os índices de coluna em que esta compra coloca parcela (1..N, no máximo até o fim das 13 colunas). Se N > colunas restantes, analisar só as que existem e na frase usar “nas faturas visíveis”.

Para cada índice `i` em `W`, no `por_cartao` do cartão simulado:

| Símbolo | Fonte |
|---------|--------|
| `antes[i]` | `valores[i].total` **antes** do overlay |
| `depois[i]` | `valores[i].total` **depois** do overlay |
| `parcela[i]` | `delta[i]` desta simulação (`depois − antes`; em geral `valor / N`, centavos na última) |
| `limite` | `por_cartao[].limite_credito` (nullable) |
| `uso_depois[i]` | `valores[i].percentual_utilizado` depois do overlay (`null` se sem limite) |

Não usar `/transacoes/listar`. Não média inventada de “renda”. Dívida do responsável **não** entra no semáforo (continua no bloco 3).

---

## Regras do veredito (obrigatório — não “achar”)

Nível do mês: `0` baixo · `1` moderado · `2` alto.

```
function nivelMes(antes, depois, parcela, limite, usoDepois):
  score = 0

  // 1) Limite depois da compra (estado em que a pessoa vai viver)
  se usoDepois != null:
    se usoDepois >= 90  → score = max(score, 2)
    senão se usoDepois >= 70 → score = max(score, 1)

  // 2) Peso da parcela na fatura que já existia
  se antes > 0:
    peso = parcela / antes
    se peso >= 0,40 → score = max(score, 2)
    senão se peso >= 0,20 → score = max(score, 1)

  // 3) Fatura vazia: só o limite (já coberto em 1).
  //    Sem limite e sem histórico → 0 (nada compete com a parcela).

  retorna score
```

**Veredito global** = `max(score)` em `W`, com um bump de duração:

- Se o max for `1` **e** houver **≥ 4** meses com score ≥ 1 → sobe para `2` (a parcela amarela em muitos meses seguidos **compromete os próximos meses**).
- À vista (`N = 1`): sem bump (não há “próximos meses” no sentido de 10x).

Mapa: `0` → `baixo` · `1` → `moderado` · `2` → `alto`.

`mes_critico` = o mês de **maior** score; empate → o de **maior** `usoDepois` (ou maior `peso` se sem limite); ainda empate → o **primeiro** da janela.

### Motivos (chips, no máximo 3)

Gerar só o que disparou, nesta prioridade:

| id | Quando | Label |
|----|--------|--------|
| `limite` | `usoDepois` do crítico ≥ 70 | `Limite {usoDepois}%` (0 casa se inteiro, senão 1) |
| `peso` | `antes > 0` e peso do crítico ≥ 0,20 | `Parcela = {peso%}% da fatura` |
| `duracao` | bump de ≥ 4 meses amarelos/vermelhos | `{k} meses pesados` |
| `parcela` | sempre, se ainda couber chip | `{Nx}` ou `à vista` |

Não mostrar chip `limite` quando `limite` é `null`.

### Casos especiais

| Situação | Nível | Frase |
|----------|-------|--------|
| Sem cartão / GET falhou | não renderiza veredito | toast de erro (já existe) |
| Cartão sem `limite_credito` e faturas já com valor | só regra de **peso** | usar frases sem “% do limite” |
| Cartão sem limite e faturas zeradas em `W` | `baixo` | *Esta parcela entra sozinha nas faturas. Não há histórico neste cartão para comparar.* |
| Compra começa depois da última coluna da projeção | tratar como 1 mês na 1ª coluna disponível se o overlay já desloca; se não houver coluna, `moderado` + *Não há faturas projetadas nesse período.* | |
| `N > 13` | analisa as colunas que existirem | chip `duracao` se couber |

---

## Catálogo de frases (renderizar como está)

Preencher os `{placeholders}` com BRL (`pt-BR`, 2 casas), `%` com 0 ou 1 casa, `label` da coluna (`Set/2026`). **Uma** frase por vez: a primeira regra que casar no nível.

### `baixo`

1. `A parcela de {parcela} cabe nas próximas {n} faturas.`
2. Se `N = 1`: `Esta compra à vista cabe na fatura de {mes_critico}.`

Eyebrow: **Pode comprar**

### `moderado`

1. Se tiver `antes` e `depois` no crítico: `Dá para comprar, mas {mes_critico} fica mais pesado: a fatura vai de {antes} para {depois}.`
2. Se tiver limite: `A parcela cabe, porém {mes_critico} vai a {usoDepois}% do limite.`
3. Fallback: `Dá para comprar, mas os próximos meses ficam mais carregados.`

Eyebrow: **Dá para comprar, com ressalva**

### `alto`

1. Se `usoDepois >= 90` (ou bump por duração com algum mês ≥ 70): `Em {mes_critico} o {cartao} iria a {usoDepois}% do limite ({depois} de {limite}).`
2. Se disparou por peso, sem limite alto: `Em {mes_critico} esta parcela representa {peso%} da fatura. Melhor reduzir o valor, alongar as parcelas ou outro cartão.`
3. Se bump de duração: `A parcela pesa em {k} faturas seguidas. Isso compromete demais os próximos meses.`
4. Fallback: `Esta compra compromete demais os próximos meses.`

Eyebrow: **Melhor não agora**

Não concatenar as 4. Não reescrever com tom de coach (“você deveria pensar melhor…”).

---

## Exemplo canônico (celular R$ 2.500 em 10x)

Form: valor `2500`, parcelas `10`, cartão Nubank, limite `8000`.

Parcela ≈ `R$ 250,00`. 1ª competência Set/2026 … 10ª Jun/2027.

| Se nas 10 faturas… | Veredito |
|--------------------|----------|
| Depois do overlay, nenhum mês ≥ 70% do limite **e** parcela < 20% da fatura de cada mês | 🟢 Baixo impacto |
| Algum mês 70–89% **ou** parcela ≥ 20% e < 40% da fatura, e isso em **até 3** meses | 🟡 Impacto moderado |
| Algum mês ≥ 90% **ou** parcela ≥ 40% da fatura **ou** ≥ 4 meses já amarelos | 🔴 Compromete demais |

Números do hero / responsável **não mudam**: entra `R$ 250,00`; já deve + 250 = passa a dever.

---

## Integração com o que já está na fase 2

- Bloco 1 (parcela grande): **encolhe**. Continua o número `Entra nesta fatura`, mas tipografia **abaixo** do título do veredito. Pode viver no mesmo card, rodapé do veredito, se ficar limpo — aí não duplicar o contexto `Nubank · 10x`.
- Bloco 2 (responsável): igual, logo abaixo.
- Accordion de 13 meses / limite: igual. Alerta de limite > 80% que já existia no detalhe **não** compete com o semáforo; o semáforo é a versão visível. Pode manter o banner só **dentro** do accordion.
- **Registrar esta compra** / **Nova simulação**: depois do bloco 2, como hoje. Vermelho **não** esconde o registrar.
- Ao registrar, a compra é **manual** (`compra_manual: true`) — mesmo parcelada. Na fatura ela pede conciliação. Não confundir com parcelas que o PDF copia sozinho para faturas `pendente`.
- Editar o form → some o veredito até clicar de novo (mesma regra de esconder resultado).

---

## Estados

| Estado | UI |
|--------|----|
| Idle | Form. Zero semáforo, zero faixa de meses. |
| Loading do GET | Skeleton do **card do veredito** (não da tabela). |
| Resultado | Veredito + faixa N meses + parcela + responsável. |
| Erro | Toast; sem card cinza de “não foi possível classificar”. |
| Sem limite | Veredito só por peso; frases sem % de limite. |

Contraste WCAG no fundo colorido. Não depender só da cor (título por extenso + ícone).

---

## O que **não** fazer

- Não criar rota/menu separado de `/simulador`.
- Não abrir o resultado ainda com 3 cards + 13 meses na primeira dobra.
- Não classificar “no olho” nem com GPT.
- Não misturar dívida de **outro** cartão no semáforo (o veredito é **deste** cartão). O geral do responsável continua no bloco 2.
- Não usar renda, score de crédito, juros, IOF.
- Não travar o cadastro da compra no vermelho.
- Não mostrar o semáforo na fase idle (“como está hoje”) — isso é a Projeção.
- Não recalcular `dia_limite` diferente do simulador.

---

## Checklist de aceite

- [ ] Menu + H1 **Posso comprar?** na mesma rota `/simulador`
- [ ] Idle continua só o form; GET de projeção só no clique
- [ ] Fase 2 abre com 🟢 / 🟡 / 🔴 + título + **uma** frase do catálogo
- [ ] Regras de 70% / 90% / peso 20% / 40% / bump de 4 meses implementadas como neste arquivo
- [ ] Faixa só dos meses da compra, mês crítico destacado
- [ ] Exemplo R$ 2.500 em 10x classifica com o overlay real daquele cartão (não um mock fixo)
- [ ] Cartão sem limite: ainda classifica (peso); não quebra
- [ ] Hero da parcela + responsável **abaixo** do veredito
- [ ] Vermelho não bloqueia **Registrar esta compra**
- [ ] Compra registrada por esse CTA trata `compra_manual` / `precisa_conciliar` como cadastro manual (ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md))
- [ ] Editar o form esconde o veredito até novo clique
- [ ] Acessível sem depender só da cor; mobile com faixa em scroll

---

## Fora de escopo

- `POST /dashboard/simular-compra` (o back ainda não tem; veredito é no cliente)
- Sugerir outro cartão / outra quantidade de parcelas automaticamente
- Juros, rotativo, “melhor dia”
- Duas compras ao mesmo tempo
- Gastos críticos (`/gastos-criticos`) — é outra pergunta (“onde estou gastando demais?”)

Tela base: [`docs/frontend-prompt-simulador-compra.md`](frontend-prompt-simulador-compra.md)
