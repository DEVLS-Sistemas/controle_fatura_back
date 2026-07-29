# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| data | date nullable | |
| estabelecimento | string | |
| valor | decimal | |
| parcelas_total | int nullable | |
| parcela_atual | int nullable | |
| valor_parcela | decimal nullable | |
| tipo | enum | purchase, payment, refund, advance |
| categoria_id | FK nullable | |
| responsavel_id | FK nullable | |
| observacoes | text nullable | |

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export:

```http
GET /api/v1/transacoes/exportar
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

Lookups: `tipos`, `categorias`, `responsaveis`, `cartoes`, `faturas`.

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `tipo`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`
