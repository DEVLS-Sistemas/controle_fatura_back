# Especificação — Estabelecimentos

Cadastro de estabelecimentos (ex.: Atacadão) com categoria/subcategoria **padrão** (sugestão).

## Tabela `estabelecimentos`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| categoria_padrao_id | FK nullable | pré-seleção na compra |
| subcategoria_padrao_id | FK nullable | exige categoria padrão + vínculo N:N |
| ativo | boolean | default true |

## Rotas (`/api/v1/estabelecimentos`)

CRUD padrão + `estabelecimentos-list`.

Lookups: `categorias`, `subcategorias`.

## Regras

- Editar categoria/subcategoria **na transação** não altera o padrão do estabelecimento.
- Na criação da compra, se `categoria_id` / `subcategoria_id` forem omitidos, aplica os padrões do estabelecimento (subcategoria só se compatível com a categoria resolvida).
- Não é possível excluir estabelecimento com transações vinculadas.

## Filtros listar

- `nome`, `categoria_padrao_id`, `ativo`, `palavra_chave`
- `page`, `perPage`
