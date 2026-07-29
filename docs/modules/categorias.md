# Especificação — Categorias

## Tabela `categorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| cor | string nullable | hex |
| ativo | boolean | |

Seed automático no registro do usuário: Alimentação, Transporte, Empresa, Lazer, Moradia, Saúde, Outros.

A associação categoria ↔ estabelecimento fica em `estabelecimento_categorias`
(ver [`estabelecimento-categorias.md`](./estabelecimento-categorias.md)).

## Rotas (`/api/v1/categorias`)

CRUD padrão + `categorias-list`.
