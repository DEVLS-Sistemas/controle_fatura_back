# Especificação — Estabelecimentos

Cadastro de estabelecimentos (nome da maquininha, ex.: `atacadao152145`) com categoria/subcategoria **padrão** (sugestão) e opcional vínculo a uma **loja** (nome fantasia).

## Tabela `estabelecimentos`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário (identificador da maquininha) |
| loja_id | FK nullable | nome fantasia — ver [`lojas.md`](lojas.md) |
| categoria_padrao_id | FK nullable | pré-seleção na compra |
| subcategoria_padrao_id | FK nullable | exige categoria padrão + vínculo N:N |
| ativo | boolean | default true |

## Rotas (`/api/v1/estabelecimentos`)

CRUD padrão + `estabelecimentos-list` + `DELETE /excluir-todos` + estatísticas.

Lookups: `categorias`, `subcategorias`, `lojas`.

### Estatísticas de compras

```http
GET /api/v1/estabelecimentos/estatisticas/{id}?mes=8&ano=2026
GET /api/v1/estabelecimentos/listar/{id}?data_inicio=2026-01-01&data_fim=2026-08-22
GET /api/v1/estabelecimentos/listar
```

`listar` / `listar/{id}` incluem `estatisticas`. Período (query):

| Param | Efeito |
|-------|--------|
| omitido | histórico: primeira compra → hoje |
| `data_inicio` / `data_fim` | janela (`Y-m-d`) |
| `mes` + `ano` | aquele mês |

Campos: `compras` (evento: parcelado = 1), `ocorrencias` (linhas na fatura), `valor_total`, `ticket_medio`, `frequencia` (`label` tipo “1 vez a cada 12 dias”, `por_dia` / `por_semana` / `por_mes` / `por_ano`), primeira/última compra.

Filtro pela **data da compra**. Prompt: [`frontend-prompt-estatisticas-estabelecimento-loja.md`](../frontend-prompt-estatisticas-estabelecimento-loja.md).

### Async select (`estabelecimentos-list`)

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

- Filtra pelo usuário autenticado e `ativo = true`
- Com `palavra_chave`: `LIKE` no nome **ou** no nome da loja, limitado a 10
- Filtro opcional: `loja_id`
- Retorna: `id`, `nome`, `loja_id`, `loja_nome`, `categoria_padrao_id`, `categoria_padrao_nome`, `categoria_padrao_cor` (tema; sem categoria padrão → `null`; categoria sem HEX → `#000000`), `subcategoria_padrao_id`, `subcategoria_padrao_nome`

Usado no formulário de compra (não há lista estática de estabelecimentos em `/transacoes/lookups`).

## Excluir todos (reset de testes)

```http
DELETE /api/v1/estabelecimentos/excluir-todos
{ "confirmar": true }
```

Soft-delete de **todos** os estabelecimentos, lojas, categorias e subcategorias do usuário autenticado; remove vínculos N:N em `categoria_subcategoria`. Exige `confirmar=true` (body ou query). Bloqueia se ainda houver transações — limpe faturas antes (`DELETE /api/v1/faturas/excluir-todas`). Não apaga faturas, cartões nem responsáveis. Ver prompt: [`frontend-prompt-limpar-estabelecimentos.md`](../frontend-prompt-limpar-estabelecimentos.md).

## Regras

- Vários estabelecimentos podem compartilhar a mesma `loja_id` (nome fantasia).
- Se o estabelecimento **não tem** `categoria_padrao_id` e uma transação recebe categoria, essa categoria (e subcategoria, se houver) vira o padrão do estabelecimento.
- Ao aprender o padrão, todas as outras transações do mesmo estabelecimento com `categoria_id` nulo recebem a mesma categoria/subcategoria. Transações já categorizadas não são sobrescritas.
- Se o estabelecimento **já tem** padrão, editar categoria numa transação altera só aquela linha (e irmãs do grupo se `propagar_grupo`).
- Na criação da compra, se `categoria_id` / `subcategoria_id` forem omitidos, aplica os padrões do estabelecimento (subcategoria só se compatível com a categoria resolvida).
- Não é possível excluir estabelecimento com transações vinculadas.

## Filtros listar

- `nome`, `loja_id`, `categoria_padrao_id`, `ativo`, `palavra_chave`
- `data_inicio`, `data_fim`, `mes`, `ano` (estatísticas)
- `page`, `perPage`

Prompt front (modal de loja): [`frontend-prompt-loja-estabelecimento.md`](../frontend-prompt-loja-estabelecimento.md).  
Estatísticas: [`frontend-prompt-estatisticas-estabelecimento-loja.md`](../frontend-prompt-estatisticas-estabelecimento-loja.md).
