# Especificação — Lojas (nome fantasia)

Nome amigável / fantasia que agrupa vários estabelecimentos vindos da maquininha (ex.: `atacadao152145` e `atacadai4555` → loja **Atacadão**).

## Tabela `lojas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| ativo | boolean | default true |

## Relações

- 1:N com estabelecimentos (`estabelecimentos.loja_id`, nullable)
- Ao excluir a loja: desvincula os estabelecimentos (`loja_id = null`) e soft-delete

## Rotas (`/api/v1/lojas`)

CRUD padrão + `lojas-list` + `cadastrar-rapido`.

### Async select (`lojas-list`)

```http
GET /api/v1/lojas/lojas-list?palavra_chave=atacad
```

- Filtra pelo usuário autenticado e `ativo = true`
- Com `palavra_chave`: `LIKE` no nome, limitado a 10
- Retorna: `id`, `nome`

### Cadastro rápido

```http
POST /api/v1/lojas/cadastrar-rapido
```

```json
{
  "nome": "Atacadão",
  "estabelecimento_id": 12
}
```

- `nome` — obrigatório (trim + espaços colapsados)
- `estabelecimento_id` — opcional; se enviado, vincula a loja ao estabelecimento na mesma operação
- Match **case-insensitive** por usuário
- Soft-deleted com o mesmo nome → restaura e reativa
- **Não** retorna 422 por duplicidade — reutiliza

**Resposta:**

```json
{
  "loja": {
    "data": {
      "id": 3,
      "nome": "Atacadão",
      "ativo": true,
      "estabelecimentos_count": 1,
      "estabelecimentos": [
        { "id": 12, "nome": "atacadao152145", "ativo": true }
      ]
    },
    "status": true,
    "criado": true,
    "message": "Loja cadastrada com sucesso!"
  }
}
```

### Vincular / desvincular sem cadastrar-rapido

```http
PUT /api/v1/estabelecimentos/editar
```

```json
{ "id": 12, "loja_id": 3 }
```

Para limpar: `{ "id": 12, "loja_id": null }`.

## Filtros listar

- `nome`, `ativo`, `palavra_chave`
- `page`, `perPage`

Listagem inclui `estabelecimentos_count`. Detalhe (`listar/{id}`) inclui a lista de estabelecimentos vinculados.

## Reset em massa

Incluído em `DELETE /api/v1/estabelecimentos/excluir-todos` (junto com estabelecimentos, categorias e subcategorias).

Uso no front (modal na tela de estabelecimento): [`frontend-prompt-loja-estabelecimento.md`](../frontend-prompt-loja-estabelecimento.md).
