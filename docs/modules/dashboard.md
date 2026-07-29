# Especificação — Dashboard

## Rota

```http
GET /api/v1/dashboard/resumo?ano=2026&mes=7
```

- `ano` (default: ano atual)
- `mes` (opcional; se omitido, consolida o ano)

## Resposta (`data`)

- `totais` — compras, pagamentos, estornos, antecipações, líquido, qtd
  - totais por tipo vêm das `transacoes`
  - `total_liquido` = soma de `faturas.valor_total` do período (mesmo saldo rolante das faturas cadastradas)
- `por_mes` — série mensal do ano (`SUM(faturas.valor_total)` por mês)
- `por_categoria` / `por_responsavel` — apenas compras
- `por_cartao` — `SUM(faturas.valor_total)` por cartão
- `por_tipo` — soma por tipo de transação

Todas as agregações filtradas pelo `user_id` autenticado e pelo `ano`/`mes` da fatura.
