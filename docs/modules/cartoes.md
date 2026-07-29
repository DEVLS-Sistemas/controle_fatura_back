# Especificação — Cartões

## Tabela `cartoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK users | Multiusuário |
| nome | string | Nome personalizado |
| bandeira | string nullable | Visa, Mastercard... |
| banco | string nullable | |
| ultimos_digitos | string(4) nullable | |
| ativo | boolean | default true |

SoftDeletes + timestamps.

## Rotas (`/api/v1/cartoes`)

Padrão CRUD completo (`lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `cartoes-list`).

## Filtros listar

- `nome`, `bandeira`, `banco`, `ativo`, `palavra_chave`
- `page`, `perPage`
