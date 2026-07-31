# Especificação — Categorias

Cadastro de categorias (ex.: Alimentação). Escopo por usuário.

## Tabela `categorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| cor | string nullable | |
| ativo | boolean | default true |

## Relações

- N:N com subcategorias (`categoria_subcategoria`)
- Pode ser padrão de estabelecimentos (`categoria_padrao_id`)
- Pode ser categoria da compra (`transacoes.categoria_id`)

## Rotas (`/api/v1/categorias`)

CRUD padrão + `categorias-list`.

Lookups: `cores`.
