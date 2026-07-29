# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| data | date nullable | |
| estabelecimento | string | chave de vínculo com categoria |
| valor | decimal | |
| parcelas_total | int nullable | |
| parcela_atual | int nullable | |
| valor_parcela | decimal nullable | |
| tipo | enum | purchase, payment, refund, advance |
| responsavel_id | FK nullable | |
| observacoes | text nullable | |

> **Categoria:** não há `categoria_id` nesta tabela. A categoria vem de
> `estabelecimento_categorias` (join por `user_id` + `estabelecimento`).
> Ver [`estabelecimento-categorias.md`](./estabelecimento-categorias.md).

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export:

```http
GET /api/v1/transacoes/exportar
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

Lookups: `tipos`, `categorias`, `responsaveis`, `cartoes`, `faturas`.

## Categoria no create/edit

Payload ainda aceita `categoria_id` opcional. O valor é persistido em
`estabelecimento_categorias` para o estabelecimento da transação (upsert).
Todas as demais transações com o mesmo estabelecimento passam a exibir essa categoria.
Enviar `categoria_id` vazio/nulo remove o vínculo do estabelecimento.

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `tipo`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`

Respostas de listagem/detalhe continuam expondo `categoria_id`, `categoria_nome` e `categoria_cor`
(resolvidos pelo join).
