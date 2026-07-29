# Especificação — Dashboard

## Rota

```http
GET /api/v1/dashboard/resumo?ano=2026&mes=7
```

- `ano` (default: ano atual)
- `mes` (opcional; se omitido, consolida o ano)

## Resposta (`data`)

- `totais` — compras, pagamentos, estornos, antecipações, líquido, qtd
- `por_mes` — série mensal do ano
- `por_categoria`
- `por_responsavel`
- `por_cartao`
- `por_tipo`

Todas as agregações filtradas pelo `user_id` autenticado.
