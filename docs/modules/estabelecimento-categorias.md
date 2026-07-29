# Especificação — Estabelecimento × Categoria

A categoria não fica mais na transação: fica vinculada ao **nome do estabelecimento** do usuário.
Assim, qualquer transação com o mesmo `estabelecimento` herda a categoria automaticamente.
Ao editar a categoria do estabelecimento, todas as transações daquele nome refletem a mudança via join.

## Tabela `estabelecimento_categorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| estabelecimento | string | único por usuário (mesmo texto de `transacoes.estabelecimento`) |
| categoria_id | FK | |

## Rotas (`/api/v1/estabelecimento-categorias`)

CRUD padrão + `estabelecimento-categorias-list`.

Lookups: `categorias`.

## Filtros listar

- `estabelecimento`, `categoria_id`, `palavra_chave`
- `page`, `perPage`

## Integração com transações

Ao criar/editar uma transação enviando `categoria_id`, o backend faz upsert nesta tabela
para o `estabelecimento` da transação (não grava mais `categoria_id` em `transacoes`).

Listagens, detalhe, export CSV e dashboard resolvem a categoria via:

`transacoes` → `estabelecimento_categorias` (user_id + estabelecimento) → `categorias`.
