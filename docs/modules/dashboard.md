# Especificação — Dashboard

## Rotas

### Resumo

```http
GET /api/v1/dashboard/resumo?ano=2026&mes=7
```

- `ano` (default: ano atual)
- `mes` (opcional; se omitido, consolida o ano)

### Projeção de faturas

```http
GET /api/v1/dashboard/projecao-faturas?mes=7&ano=2026
```

- `mes` / `ano`: mês de referência (default: atual)
- Retorna matriz de **13 meses** (mês anterior + 12 à frente)
- Três visões: **por cartão**, **por responsável** e **por cartão × responsável**
- Parcelas futuras projetadas só quando ainda **não** há linha materializada (legado / casos sem `compra_grupo_id`). Compras manuais e import PDF parcelado materializam N transações (`compra_grupo_id`) e entram na projeção como **realizado**.
- Evita duplicidade: parcelas já registradas (mesmo com valor da última parcela diferente por centavos) não são projetadas de novo
- Mês com fatura `processada`: cartão usa `valor_total`; responsável / cruzamento usam soma de compras

## Resposta resumo (`data`)

- `totais` — compras, pagamentos, estornos, antecipações, líquido, qtd
  - totais por tipo vêm das `transacoes`
  - `total_liquido` = soma de `faturas.valor_total` do período (mesmo saldo rolante das faturas cadastradas)
- `por_mes` — série mensal do ano (`SUM(faturas.valor_total)` por mês)
- `por_categoria` / `por_responsavel` — apenas compras
  - `por_categoria` usa `transacoes.categoria_id` (categoria da compra)
- `por_cartao` — `SUM(faturas.valor_total)` por cartão
- `por_tipo` — soma por tipo de transação

## Resposta projeção (`data`)

- `referencia` — mês/ano base
- `colunas` — 13 períodos com `label`, `chave`, `referencia`
- `por_cartao[]` — linha por cartão ativo; inclui `limite_credito`; `valores[]` alinhado às colunas
- `por_responsavel[]` — linha por responsável ativo
- `por_cartao_responsavel[]` — cartão com `por_responsavel[]` aninhado (quanto cada um gastou naquele cartão); também inclui `limite_credito`
- `totais_por_coluna[]` — soma por mês (cartões e responsáveis)
- Célula base: `{ realizado, projetado, total, fonte }`
- Células por cartão / cartão×responsável acrescentam: `{ percentual_utilizado, disponivel }` (`null` se sem `limite_credito`)

Todas as agregações filtradas pelo `user_id` autenticado.

Ver também: [`docs/frontend-prompt-projecao-faturas.md`](../frontend-prompt-projecao-faturas.md)
