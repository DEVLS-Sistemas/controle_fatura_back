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

CRUD padrão + `estabelecimentos-list` + `DELETE /excluir-todos`.

Lookups: `categorias`, `subcategorias`.

### Async select (`estabelecimentos-list`)

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

- Filtra pelo usuário autenticado e `ativo = true`
- Com `palavra_chave`: `LIKE` no nome, limitado a 10
- Retorna: `id`, `nome`, `categoria_padrao_id`, `categoria_padrao_nome`, `categoria_padrao_cor`, `subcategoria_padrao_id`, `subcategoria_padrao_nome`

Usado no formulário de compra (não há lista estática de estabelecimentos em `/transacoes/lookups`).

## Excluir todos (reset de testes)

```http
DELETE /api/v1/estabelecimentos/excluir-todos
{ "confirmar": true }
```

Soft-delete de **todos** os estabelecimentos, categorias e subcategorias do usuário autenticado; remove vínculos N:N em `categoria_subcategoria`. Exige `confirmar=true` (body ou query). Bloqueia se ainda houver transações — limpe faturas antes (`DELETE /api/v1/faturas/excluir-todas`). Não apaga faturas, cartões nem responsáveis. Ver prompt: [`frontend-prompt-limpar-estabelecimentos.md`](../frontend-prompt-limpar-estabelecimentos.md).

## Regras

- Se o estabelecimento **não tem** `categoria_padrao_id` e uma transação recebe categoria, essa categoria (e subcategoria, se houver) vira o padrão do estabelecimento.
- Ao aprender o padrão, todas as outras transações do mesmo estabelecimento com `categoria_id` nulo recebem a mesma categoria/subcategoria. Transações já categorizadas não são sobrescritas.
- Se o estabelecimento **já tem** padrão, editar categoria numa transação altera só aquela linha (e irmãs do grupo se `propagar_grupo`).
- Na criação da compra, se `categoria_id` / `subcategoria_id` forem omitidos, aplica os padrões do estabelecimento (subcategoria só se compatível com a categoria resolvida).
- Não é possível excluir estabelecimento com transações vinculadas.

## Filtros listar

- `nome`, `categoria_padrao_id`, `ativo`, `palavra_chave`
- `page`, `perPage`
