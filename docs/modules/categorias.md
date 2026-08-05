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

### Cadastro rápido

```http
POST /api/v1/categorias/cadastrar-rapido
```

Body: `{ "nome": "...", "cor": "#14b8a6" }` (`cor` opcional).

- Trim + unicidade **case-insensitive** por usuário
- Se já existir (ou soft-deleted): reutiliza / restaura — **não** retorna 422
- Resposta inclui `criado: true|false`

Uso no front (modal inline na compra/fatura): [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](../frontend-prompt-cadastro-rapido-categoria-subcategoria.md).
