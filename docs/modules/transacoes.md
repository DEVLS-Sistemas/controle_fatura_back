# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| estabelecimento_id | FK | obrigatório |
| data | date nullable | |
| valor | decimal | |
| parcelas_total | int nullable | |
| parcela_atual | int nullable | |
| valor_parcela | decimal nullable | |
| tipo | enum | purchase, payment, refund, advance |
| categoria_id | FK nullable | categoria **da compra** |
| subcategoria_id | FK nullable | exige categoria + vínculo N:N |
| responsavel_id | FK | obrigatório; default = responsável `Eu` |
| observacoes | text nullable | |

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export:

```http
GET /api/v1/transacoes/exportar
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

Lookups: `tipos`, `categorias`, `subcategorias`, `estabelecimentos`, `responsaveis`, `default_responsavel_id`, `cartoes`, `faturas`.

## Create/edit

- Estabelecimento: enviar `estabelecimento_id` **ou** `estabelecimento` (texto; faz find-or-create).
- Categoria/subcategoria: opcionais na compra.
  - Create: se omitidas, usa padrões do estabelecimento (quando compatíveis).
  - Edit: altera só a transação; **não** atualiza padrão do estabelecimento.
- Subcategoria sem categoria → 422.
- Responsável: se omitido no create/import, usa `Eu`.

## Import PDF/CSV/XML

- Resolve estabelecimento pelo nome (cria se necessário).
- Aplica padrões do estabelecimento.
- Sempre define responsável `Eu`.

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `subcategoria_id`, `estabelecimento_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `tipo`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`

Respostas expõem `estabelecimento` (nome), `categoria_*`, `subcategoria_*`, `responsavel_*`.
