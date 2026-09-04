# Especificação — Raio-X Financeiro

Leitura interpretada do mês: **três sinais** (pagamentos, evolução das faturas, comprometimento da renda) + **um** problema principal + frase de projeção.

Não é dashboard de totais. Frases e níveis 🟢🟡🔴 saem prontos da API. O front **não** recalcula.

Prompt: [`docs/frontend-prompt-raio-x.md`](../frontend-prompt-raio-x.md).

## Rota

```http
GET /api/v1/dashboard/raio-x?mes=8&ano=2026
```

Autenticado (Sanctum). Escopo pelo `user_id` do token.

### Query

| Param | Default | Descrição |
|-------|---------|-----------|
| `mes` / `ano` | competência atual | Recorte pelas **faturas** da competência (não pela data da compra) |
| `responsavel_id` | — | Opcional. Default = conta inteira (`faturas.valor_total` do mês) |

## Conceitos

| Termo | Significado |
|-------|-------------|
| Competência | Mês/ano da fatura (`faturas.mes` / `ano`) |
| Valor do mês | Soma de `faturas.valor_total` da competência (o que a fatura pede para pagar) |
| Pagamentos em dia | Faturas da competência (e anteriores ainda em aberto) vs `data_vencimento` e `pago` |
| Crescimento | Valor do mês vs competência anterior (`null` se não houver base → não tratar como 0%) |
| Comprometimento | `valor do mês / renda_mensal`. Sem renda → sinal `incompleto` |
| Parcelas futuras | Compras com `compra_grupo_id` e `parcelas_total > 1` ainda ativas: `valor_aberto` + quantidade (mesmo critério do ranking, `apenas_abertas=1`) |
| Projeção de comprometimento | Percorre a matriz de projeção (12 meses à frente). Sem novas parceladas, acha o primeiro mês em que o % sobre a renda cai de forma relevante (ex.: ≥ 10 pp ou atravessa faixa). Frase pronta com o `label` desse mês |

`renda_mensal` vive em `users` (nullable). `GET /me` e `PUT /auth/perfil` passam a expor/gravar o campo. Sem renda o Raio-X **não** inventa média.

## Sinais (`data.sinais[]`)

Sempre **3**, nesta ordem: `pagamentos`, `crescimento`, `comprometimento`.

Cada um: `id`, `nivel` (`positivo` \| `atencao` \| `alerta` \| `incompleto`), `titulo`, `frase`, `contexto`, `metricas?`, `atalho`.

### Pagamentos

- `positivo` — nenhuma fatura vencida em aberto
- `atencao` — fatura a vencer em breve (ex.: ≤ 5 dias) **ou** fatura anterior ainda **aguardando confirmação** de pagamento
- `alerta` — atraso **confirmado** (não basta `pago === false` + vencimento passado)

Quitação usa a regra já existente (`pago` / `valor_restante` da fatura). Stubs `pendente` sem anexo anteriores à primeira fatura processada da bandeira entram como pagos (histórico incompleto de parcelas materializadas — ver [`faturas.md`](faturas.md)).

**Quando o atraso se confirma** (F = fatura vencida e ainda não `pago`):

| Situação | Sinal |
|----------|--------|
| F tem PDF e F+1 **ainda não** tem anexo, e hoje ainda está no mês de F+1 (ex.: hoje setembro, última anexada agosto) | **Não** é atraso. `atencao` + frase de aguardando confirmação |
| F+1 tem anexo e **não** trouxe pagamento que quite F | `alerta` — atraso confirmado |
| Salto de 2 meses: hoje já é F+2 e F+1 nunca foi anexada (ex.: hoje outubro, última anexada agosto) | `alerta` — atraso confirmado |

Enquanto aguarda, o contexto deixa claro: a fatura do mês anterior **ainda não tem definição de atraso**; o pagamento só se confirma com o anexo da fatura seguinte ou por operação manual.

`metricas` do sinal `pagamentos`: `atrasadas`, `a_vencer`, `aguardando_confirmacao`, `em_aberto`, `valor_restante`, `valor_atrasado`, `valor_aguardando`. O front **não** decide o nível — só renderiza `frase` / `contexto`.

### Crescimento

Comparar competência atual × anterior.

Sugestão de faixa (ajustável no service, não no front):

- `positivo` — caiu ou estável (≤ 0%)
- `atencao` — subiu até 20%
- `alerta` — subiu mais de 20%

Sem mês anterior com fatura: `nivel: positivo`, frase do tipo “Primeiro mês com fatura neste recorte.” — `variacao_percentual: null`.

### Comprometimento

Faixas sugeridas (renda informada):

- `positivo` — abaixo de 30%
- `atencao` — 30–50%
- `alerta` — acima de 50%

Sem renda: `incompleto` + `atalho.rota = perfil`. `diagnostico.projecao` = `null`.

## Diagnóstico (`data.diagnostico`)

Um objeto ou `null`. Escolher **um** `tipo` por prioridade (primeiro que disparar):

1. `atraso` — sinal de pagamentos em `alerta`
2. `parceladas` — valor em aberto de parceladas relevante (ex.: ≥ 20% do valor do mês ou ≥ N compras ativas)
3. `assinaturas` — estimativa mensal de assinaturas oficiais pesando no mês
4. `crescimento` — sinal de crescimento em `alerta`
5. `concentracao` — um lugar/categoria concentra demais (reusar ideia de gastos críticos, sem copiar a tela)
6. `ok` — nada dominante; tom neutro/positivo

Campos: `tipo`, `titulo`, `frase`, `projecao` (nullable), `contexto`, `metricas`, `atalho`.

Exemplo de `tipo = parceladas`:

- `titulo`: `Principal problema: compras parceladas.`
- `frase`: `Você possui R$ 8.420 em parcelas futuras, distribuídas em 23 compras.`
- `projecao`: `Se não realizar novas compras parceladas, seu comprometimento deve cair para 51% em janeiro.`

A `projecao` só existe com renda informada e horizonte encontrado na matriz. Se o comprometimento **não** cai no horizonte de 12 meses, frase alternativa honesta (ex.: “Mesmo sem novas parceladas, o comprometimento segue alto nos próximos 12 meses.”) — ainda assim um parágrafo, não uma tabela.

## Ações (`data.acoes[]`)

Lista curta de CTAs (`id`, `label`, `atalho`) alinhada ao diagnóstico (parceladas, posso comprar, gastos críticos, faturas…). O front só navega.

## Fora de escopo

- Classificação por LLM
- Juros, IOF, score
- Overlay de compra nova (já é simulador / Posso comprar?)
- Recalcular frases no cliente

Service: `App\Services\Dashboard\RaioXService`  
Reusa (no servidor) faturas, totais do mês, `RankingParceladasService`, `ProjecaoFaturasService`.

## Checklist back

- [x] `GET /api/v1/dashboard/raio-x`
- [x] Sempre 3 sinais: pagamentos, crescimento, comprometimento
- [x] Um diagnóstico (`atraso` > `parceladas` > `assinaturas` > `crescimento` > `concentracao` > `ok`)
- [x] Frases prontas (não recalcular no front)
- [x] `renda_mensal` no perfil; sem renda o 3º sinal é `incompleto`
