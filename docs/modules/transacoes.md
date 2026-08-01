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

Lookups: `tipos`, `categorias`, `subcategorias`, `responsaveis`, `default_responsavel_id`, `cartoes`, `faturas`.

Estabelecimentos **não** vêm no lookups — usar busca async:

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

## Create/edit

### Cartão (obrigatório no create) vs fatura

Na tela de cadastro o usuário seleciona o **cartão**, não a fatura:

```json
{
  "cartao_id": 1,
  "data": "2026-07-31",
  "estabelecimento_id": 104,
  "valor": "125,50",
  "parcela_atual": 1,
  "parcelas_total": 12,
  "tipo": "purchase",
  "categoria_id": 1,
  "subcategoria_id": 1,
  "responsavel_id": 1,
  "observacoes": "..."
}
```

- Backend resolve `fatura_id` pelo `cartao_id` + mês/ano da `data` (ou mês atual se data omitida).
- Se a fatura do período não existir, **cria automaticamente** (`status=pendente`).
- `fatura_id` explícito ainda é aceito (ex.: tela de detalhe da fatura).
- `valor` aceita formato BR (`125,50` / `1.234,56`) ou decimal.

### Demais regras

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
