# Especificação — Categorias

## Tabela `categorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| cor | string nullable | hex |
| ativo | boolean | |

Seed automático no registro do usuário: Alimentação, Transporte, Empresa, Lazer, Moradia, Saúde, Outros.

## Rotas (`/api/v1/categorias`)

CRUD padrão + `categorias-list`.
