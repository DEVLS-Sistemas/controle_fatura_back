# Especificação — Subcategorias

Cadastro próprio (ex.: Feira do Mês), vinculado a **uma ou várias** categorias (N:N).  
Não existe 3º nível hierárquico; detalhe livre fica em `observacoes` da transação.

## Tabela `subcategorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| ativo | boolean | default true |

## Pivot `categoria_subcategoria`

| Campo | Tipo |
|-------|------|
| categoria_id | FK |
| subcategoria_id | FK |

Unique `(categoria_id, subcategoria_id)`.

## Rotas (`/api/v1/subcategorias`)

CRUD padrão + `subcategorias-list`.

Lookups: `categorias`.

### Payload create/edit

- `nome` (obrigatório no create)
- `categoria_ids` (array; obrigatório ter ao menos 1)
- `ativo` (opcional)

## Na compra

- `subcategoria_id` só é aceita com `categoria_id` e com vínculo N:N válido.
- Async list aceita filtro `categoria_id` para popular selects dependentes.

## Filtros listar

- `nome`, `categoria_id`, `ativo`, `palavra_chave`
- `page`, `perPage`
