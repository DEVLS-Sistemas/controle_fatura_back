# Prompt — Frontend: Raio-X Financeiro

Use este prompt no repositório do frontend para criar a tela **Raio-X Financeiro**.

**Ponto-chave do produto:** esta tela **não mostra simplesmente números**. Ela **interpreta** os dados e fala com o usuário em frases. O olhar cai no diagnóstico, não numa grade de KPIs.

Referência visual (primeira dobra — é exatamente isto, não um dashboard):

```
Seu mês

🟢 Pagamentos em dia
🟡 Faturas cresceram 18%
🔴 74% da sua renda já está comprometida

Principal problema: compras parceladas.

Você possui R$ 8.420 em parcelas futuras, distribuídas em 23 compras.

Se não realizar novas compras parceladas, seu comprometimento deve cair para 51% em janeiro.
```

Backend **já implementado**. **Não** montar a tela somando `resumo` + `ranking-parceladas` + `projecao-faturas` + listagem de faturas no cliente. Frases, níveis 🟢🟡🔴 e o “principal problema” vêm prontos de `GET /dashboard/raio-x`.

Spec do back: [`modules/raio-x.md`](modules/raio-x.md).

---

## Objetivo

A tela responde, no mês escolhido, em linguagem humana:

1. **Como está o mês?** — três sinais (pagamentos, evolução das faturas, comprometimento da renda)
2. **Qual é o principal problema?** — um só, com frase
3. **O que acontece se eu parar?** — uma frase de projeção (ex.: comprometimento cai a 51% em janeiro)

Não é substituto de:

| Tela | O que ela já faz |
|------|------------------|
| Dashboard / resumo | Totais e gráficos |
| Projeção | Matriz 13 meses |
| Parceladas | Ranking / timeline das compras |
| Gastos críticos | Onde está gastando demais |
| Posso comprar? | Veredito de **uma** compra nova |

Raio-X é o **resumo interpretado** que aponta para essas telas.

---

## Conceito de produto

Três camadas, nesta ordem, com muito ar:

```
1. Seu mês          → 3 sinais 🟢🟡🔴 (frases curtas)
2. Diagnóstico      → 1 problema principal (título + 2 frases)
3. Ações            → atalhos (parceladas, faturas, posso comprar, gastos críticos)
```

Regras de produto (não negociar):

- **Tipografia primeiro.** A frase *é* a UI. Número entra dentro da frase, não ao lado num card “R$ 8.420 / 23 compras”.
- **Sempre 3 sinais** no bloco “Seu mês” (o terceiro degrada se não houver renda — ver abaixo).
- **Um** problema principal. Não listar 5 “insights” competindo.
- **Não** recalcular `frase`, `titulo`, `%` nem BRL no front. Renderizar como veio.
- **Não** abrir a tela com tabela de 13 meses, pizza de categoria ou 8 cards de métrica.
- Semáforo = conselho, não trava. Não existe “você não pode usar o app”.

Níveis (iguais ao Posso comprar?):

| `nivel` | UI | Significado |
|---------|-----|-------------|
| `positivo` | 🟢 verde | Ok / no caminho certo |
| `atencao` | 🟡 âmbar | Vale olhar — inclusive **aguardando confirmação** de pagamento (ainda não é atraso) |
| `alerta` | 🔴 vermelho | Compromete / **atraso confirmado** / estourando |
| `incompleto` | ⚪ cinza | Falta dado (quase sempre: renda) |

Não usar LLM para classificar.

---

## Menu / rota

Item de menu visível: **Raio-X Financeiro** (ou **Raio-X**).  
Não enterrar só como widget do dashboard.

Rota: `/raio-x` (ou `/dashboard/raio-x`).

Subtítulo de uma linha (fixo no front, não vem da API):

> Uma leitura do mês — não um relatório.

Deep-link: `/raio-x?mes=8&ano=2026`.

---

## API (Bearer Sanctum)

```http
GET /api/v1/dashboard/raio-x?mes=8&ano=2026
Authorization: Bearer {token}
```

| Param | Default | Uso |
|-------|---------|-----|
| `mes` / `ano` | competência atual | Seletor no topo; ao mudar, refetch |
| `responsavel_id` | — | Opcional / avançado. Default = conta inteira (o que as faturas pedem para pagar) |

Envelope padrão do dashboard: `{ data, status, message }`.

Comprometimento da renda usa `users.renda_mensal` (campo novo no perfil). Sem renda, o 3º sinal vem `nivel: "incompleto"` e a projeção de % some — o resto da tela continua.

### Shape (`data`)

```json
{
  "referencia": {
    "mes": 8,
    "ano": 2026,
    "label": "Agosto 2026",
    "label_curto": "Seu mês"
  },
  "renda": {
    "informada": true,
    "valor": 11400.0,
    "moeda": "BRL"
  },
  "sinais": [
    {
      "id": "pagamentos",
      "nivel": "positivo",
      "titulo": "Pagamentos em dia",
      "frase": "Pagamentos em dia",
      "contexto": "Nenhuma fatura vencida em aberto neste mês.",
      "atalho": { "rota": "faturas", "query": { "mes": 8, "ano": 2026 } }
    },
    {
      "id": "crescimento",
      "nivel": "atencao",
      "titulo": "Faturas cresceram 18%",
      "frase": "Faturas cresceram 18%",
      "contexto": "R$ 4.820 neste mês vs R$ 4.085 no mês anterior.",
      "metricas": {
        "variacao_percentual": 18.0,
        "valor_atual": 4820.0,
        "valor_anterior": 4085.0
      },
      "atalho": { "rota": "faturas", "query": { "mes": 8, "ano": 2026 } }
    },
    {
      "id": "comprometimento",
      "nivel": "alerta",
      "titulo": "74% da sua renda já está comprometida",
      "frase": "74% da sua renda já está comprometida",
      "contexto": "R$ 8.430 de faturas sobre R$ 11.400 de renda mensal.",
      "metricas": {
        "percentual": 74.0,
        "valor_comprometido": 8430.0,
        "renda": 11400.0
      },
      "atalho": { "rota": "projecao", "query": { "mes": 8, "ano": 2026 } }
    }
  ],
  "diagnostico": {
    "tipo": "parceladas",
    "titulo": "Principal problema: compras parceladas.",
    "frase": "Você possui R$ 8.420 em parcelas futuras, distribuídas em 23 compras.",
    "projecao": "Se não realizar novas compras parceladas, seu comprometimento deve cair para 51% em janeiro.",
    "contexto": "Sem novas parceladas, a curva cai conforme as compras atuais terminam.",
    "metricas": {
      "valor_aberto": 8420.0,
      "compras": 23,
      "comprometimento_atual_percentual": 74.0,
      "comprometimento_projetado_percentual": 51.0,
      "horizonte": { "mes": 1, "ano": 2027, "label": "janeiro" }
    },
    "atalho": { "rota": "parceladas", "query": { "mes": 8, "ano": 2026 } }
  },
  "acoes": [
    {
      "id": "parceladas",
      "label": "Ver compras parceladas",
      "atalho": { "rota": "parceladas", "query": { "mes": 8, "ano": 2026 } }
    },
    {
      "id": "posso_comprar",
      "label": "Posso comprar?",
      "atalho": { "rota": "simulador" }
    },
    {
      "id": "gastos_criticos",
      "label": "Onde estou gastando demais?",
      "atalho": { "rota": "gastos-criticos" }
    }
  ]
}
```

`diagnostico` pode ser `null` (mês sem compras / sem sinal dominante).  
`renda.informada === false` → `renda.valor` é `null`; o sinal `comprometimento` vem `incompleto`.

### `diagnostico.tipo` (um por resposta)

| `tipo` | Título típico (já vem em `titulo`) | Destino do clique |
|--------|-------------------------------------|-------------------|
| `parceladas` | Principal problema: compras parceladas. | `/parceladas` |
| `atraso` | Principal problema: faturas em atraso. | `/faturas` |
| `assinaturas` | Principal problema: assinaturas. | `/assinaturas` |
| `crescimento` | Principal problema: as faturas subiram rápido. | `/faturas` ou `/gastos-criticos` |
| `concentracao` | Principal problema: gasto concentrado em um lugar. | `/gastos-criticos` |
| `ok` | Nenhum problema dominante este mês. | — |

O front **não** escolhe o tipo. Só troca o ícone/atalho conforme `tipo` + `atalho`.

### Sinal `comprometimento` sem renda

```json
{
  "id": "comprometimento",
  "nivel": "incompleto",
  "titulo": "Informe sua renda para ver o comprometimento",
  "frase": "Informe sua renda para ver o comprometimento",
  "contexto": "Com a renda mensal, o Raio-X diz quanto da sua entrada já está nas faturas.",
  "atalho": { "rota": "perfil" }
}
```

Neste caso `diagnostico.projecao` vem `null` (não inventar “cai para X%” sem renda). A `frase` do problema (parcelas futuras em R$) **continua**.

### Sinal `pagamentos` — atraso vs aguardando confirmação

O back **não** pinta de vermelho só porque a fatura venceu e `pago === false`. O pagamento da competência F só aparece no PDF de F+1 (ou numa operação manual). Enquanto o mês seguinte não tem anexo, a fatura anterior **não tem definição de atraso**.

| `nivel` | `frase` típica (já vem pronta) | O que o usuário precisa entender |
|---------|--------------------------------|----------------------------------|
| `positivo` | Pagamentos em dia | Nada vencido em aberto |
| `atencao` | Fatura vence em breve | Vence em ≤ 5 dias |
| `atencao` | Aguardando confirmação de pagamento | Mês anterior com PDF; mês atual **sem** anexo. Ainda **não** é atraso |
| `alerta` | Há fatura em atraso | Atraso **confirmado**: o PDF do mês seguinte existe e **não** quitou, **ou** pulou 2 meses (hoje outubro e a última anexada é agosto) |

Exemplo (`atencao`, aguardando — **não** tratar como atraso):

```json
{
  "id": "pagamentos",
  "nivel": "atencao",
  "titulo": "Aguardando confirmação de pagamentos",
  "frase": "Aguardando confirmação de pagamentos",
  "contexto": "As faturas de agosto ainda não têm definição de atraso. O pagamento se confirma com o anexo da fatura seguinte ou por operação manual.",
  "metricas": {
    "atrasadas": 0,
    "a_vencer": 0,
    "aguardando_confirmacao": 2,
    "em_aberto": 2,
    "valor_restante": 11078.07,
    "valor_atrasado": 0,
    "valor_aguardando": 11078.07
  },
  "atalho": { "rota": "faturas", "query": { "mes": 8, "ano": 2026 } }
}
```

Regras de UI (não negociar):

- Renderizar `frase` + `contexto` **como vieram**. Não reescrever “em atraso” a partir de `em_aberto` ou `valor_restante`.
- `nivel === "atencao"` + `metricas.aguardando_confirmacao > 0` + `atrasadas === 0` → âmbar, **nunca** vermelho, **nunca** título “faturas em atraso”.
- `diagnostico.tipo === "atraso"` só existe quando o sinal está em `alerta`. Sem isso, não inventar o bloco “Principal problema: faturas em atraso.”
- Clique no sinal → `atalho` (faturas). Lá o usuário anexa o PDF do mês seguinte ou registra a operação manual.
- Não mostrar `metricas` como KPI cards. O contexto já explica.

---

## UX da tela (obrigatório)

Layout nesta ordem. Desktop e mobile = **uma coluna**. Muito padding. Sem sidebar de filtros na primeira dobra.

### 0) Controles

1. H1: **Raio-X Financeiro**
2. Seletor mês/ano (default: atual). Persistência opcional: `localStorage` `raio_x_mes` / `raio_x_ano` — se o salvo for o mês corrente, ok; se o usuário entrou por deep-link, a query ganha.
3. Filtro avançado (drawer / “Mais”): responsável. Não poluir o topo.

Não mostrar intervalo de datas tipo gastos críticos. Aqui o recorte é **competência da fatura**.

### 1) Bloco “Seu mês”

Eyebrow / kicker: `referencia.label_curto` (**Seu mês**) ou, se quiser o mês por extenso, `referencia.label` em texto secundário (`Agosto 2026`).

Abaixo, **três linhas**, uma por item de `sinais` **na ordem da API**:

```
🟢  {sinal.frase}
🟡  {sinal.frase}
🔴  {sinal.frase}
```

Cada linha:

- Bolinha / emoji do `nivel` à esquerda (grande o suficiente para ler à distância)
- **Só a `frase`** como texto principal (já inclui o %)
- `contexto` em texto secundário, uma linha, opcional — mostrar no hover/tap ou logo abaixo em cinza menor. Não empilhar dois parágrafos por sinal.
- Clique na linha → `atalho`

**Não** transformar isto em 3 cards com título + valor grande + sparkline.

Empty de um sinal: a API sempre manda 3. Não completar no front.

### 2) Diagnóstico

Só renderiza se `diagnostico !== null`.

Espaço extra entre “Seu mês” e este bloco (é o segundo fôlego da página).

```
{diagnostico.titulo}

{diagnostico.frase}

{diagnostico.projecao}
```

Hierarquia:

| Campo | Papel visual |
|-------|----------------|
| `titulo` | H2. Ex.: **Principal problema: compras parceladas.** |
| `frase` | Corpo 1 — o fato (R$ + quantidade) |
| `projecao` | Corpo 2 — o “e se”. Se `null`, omitir o parágrafo (não mostrar vazio) |

`tipo === "ok"`: mesmo bloco, tom neutro/positivo (sem “problema” vermelho). Ainda assim usar `titulo` / `frase` da API.

Clique no bloco (ou botão “Ver detalhes”) → `diagnostico.atalho`.

**Não** repetir abaixo uma tabela “23 compras · R$ 8.420 · 51%”. A frase já carregou o número.

### 3) Ações

Faixa discreta, **depois** do diagnóstico:

Botões/links a partir de `acoes[]` (`label` + `atalho`). Secundários, não competir com o H2.

Mapa de `atalho.rota`:

| `rota` | Destino |
|--------|---------|
| `faturas` | Listagem de faturas + `query` mes/ano |
| `parceladas` | Ranking de parceladas + mes/ano |
| `projecao` | Projeção de faturas + mes/ano |
| `simulador` | Posso comprar? (`/simulador`) |
| `gastos-criticos` | `/gastos-criticos` |
| `assinaturas` | `/assinaturas` |
| `perfil` | `/perfil` (renda) |

Reusar telas existentes. Não inventar um detalhe terceiro.

### 4) Renda — CTA inline (obrigatório se incompleto)

Se `renda.informada === false`:

1. O 3º sinal já pede a renda (`incompleto`).
2. Além disso, um card baixo **no fim do bloco “Seu mês”** (não um modal na cara):

```
Qual é a sua renda mensal?
[ R$          ]  [Salvar]
Usamos só para calcular o comprometimento das faturas. Você pode alterar no Perfil.
```

Salvar:

```http
PUT /api/v1/auth/perfil
{ "renda_mensal": "11400,00" }
```

Mandar também os campos já conhecidos do user (`name`, `email`, …) se o back do perfil exigir o payload completo — igual à tela Perfil. Ver [`frontend-prompt-perfil.md`](frontend-prompt-perfil.md).

Ao sucesso: atualizar o store + **refetch** do Raio-X. O 3º sinal e a `projecao` passam a existir.

Se `renda.informada === true`: não mostrar o form. Link discreto “Alterar renda” → Perfil (ou o mesmo input em modo edição, colapsado).

Formato BRL no input (`11.400,00`). Não aceitar 0.

### 5) Empty / loading / erro

| Estado | UI |
|--------|-----|
| Loading | Skeleton das 3 linhas + 3 linhas de texto no diagnóstico. Sem spinner de dashboard. |
| Erro | `message` da API + tentar de novo |
| Sem faturas / sem compras (`diagnostico === null` e sinais todos “vazios”/positivos genéricos) | Ilustração + “Importe uma fatura ou cadastre uma compra para o Raio-X ter o que ler.” CTAs: Faturas, Nova compra |
| Mês futuro sem dados | “Ainda não há fatura nesta competência.” |

---

## Campo `renda_mensal`

Campo no perfil (`users.renda_mensal`). Já existe no back.

| Onde | O que fazer |
|------|-------------|
| Perfil | Input opcional **Renda mensal** (BRL). Mesmo `PUT /auth/perfil` |
| `GET /me` | `user.renda_mensal` (`number` \| `null`) |
| Raio-X | CTA inline se `null` (acima) |

Não bloquear o resto do app sem renda. Só o 3º sinal e a frase de projeção em %. Omitir `renda_mensal` no PUT **não** apaga o valor já gravado; enviar `""` limpa.

Detalhe do contrato: [`modules/raio-x.md`](modules/raio-x.md) · Perfil: [`frontend-prompt-perfil.md`](frontend-prompt-perfil.md).

---

## O que **não** fazer

- Recalcular “cresceram 18%”, “74%”, “51% em janeiro” no cliente
- Inferir atraso no cliente a partir de `em_aberto`, vencimento ou `pago` — só o back confirma atraso
- Média inventada de renda; score de crédito; juros; IOF
- Semáforo de **compra nova** (isso é Posso comprar?)
- Lista das 23 parceladas nesta tela (isso é `/parceladas`)
- Gráfico de evolução como peça principal (isso é Gastos críticos / Projeção)
- LLM / texto gerado no browser
- Traduzir `nivel` para outra paleta (manter verde / âmbar / vermelho / cinza)

---

## Critérios de aceite

- [ ] Menu próprio **Raio-X Financeiro**, rota `/raio-x`
- [ ] Primeira dobra = 3 sinais em frase + diagnóstico (título, fato, projeção) — **igual à referência**
- [ ] Frases da API renderizadas como estão (sem reescrita)
- [ ] Sinal de pagamentos em `atencao` com “Aguardando confirmação…” **não** vira vermelho nem “faturas em atraso”
- [ ] Um problema principal; clique usa `diagnostico.atalho`
- [ ] Sem renda: 3º sinal cinza + CTA para informar; resto da tela funciona; `projecao` omitida
- [ ] Com renda: PUT perfil + refetch
- [ ] Seletor mes/ano refetch `?mes=&ano=`
- [ ] Ações apontam para telas já existentes
- [ ] Sem tabela 13 meses, sem pizza, sem 8 KPI cards na primeira dobra
- [ ] Empty / loading / erro / responsivo (uma coluna no mobile)

---

## Fora de escopo

- Implementar a regra de negócio no front (atraso confirmado vs aguardando PDF, %, horizonte da projeção)
- Editar compra, quitar fatura ou confirmar assinatura a partir desta tela (só navegação)
- Overlay de “e se eu comprar X” (Posso comprar? / Simulador)
- Relatório PDF / compartilhar

---

## Backend (já implementado)

```http
GET /api/v1/dashboard/raio-x
```

Service: `App\Services\Dashboard\RaioXService`  
Reusa internamente faturas (`pago` / vencimento), totais do mês, ranking de parceladas e projeção — **no servidor**.

Spec: [`docs/modules/raio-x.md`](modules/raio-x.md)  
Parceladas: [`frontend-prompt-ranking-parceladas.md`](frontend-prompt-ranking-parceladas.md)  
Projeção: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md)  
Posso comprar?: [`frontend-prompt-posso-comprar.md`](frontend-prompt-posso-comprar.md)  
Gastos críticos: [`frontend-prompt-gastos-criticos.md`](frontend-prompt-gastos-criticos.md)
