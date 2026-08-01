# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| estabelecimento_id | FK | obrigatório |
| data | date nullable | data da compra (igual em todas as parcelas do grupo) |
| valor | decimal | valor da parcela (o que cai na fatura do mês) |
| parcelas_total | int nullable | 1..36 |
| parcela_atual | int nullable | 1..N |
| valor_parcela | decimal nullable | em geral = `valor` |
| compra_grupo_id | uuid nullable | liga as N parcelas da mesma compra; null se à vista |
| tipo | enum | purchase, payment, refund, advance |
| categoria_id | FK nullable | categoria **da compra** |
| subcategoria_id | FK nullable | exige categoria + vínculo N:N |
| responsavel_id | FK | obrigatório; default = responsável `Eu` |
| observacoes | text nullable | |

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export:

```http
GET /api/v1/transacoes/exportar
DELETE /api/v1/transacoes/excluir/{id}?excluir_grupo=1
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

Lookups: `tipos`, `categorias`, `subcategorias`, `responsaveis`, `default_responsavel_id`, `cartoes`, `faturas`.

Estabelecimentos **não** vêm no lookups — usar busca async:

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

## Create — compra à vista e parcelada

### Payload (parcelado)

```json
{
  "cartao_id": 1,
  "data": "2026-03-15",
  "estabelecimento_id": 104,
  "valor_compra": "1000,00",
  "parcelas_total": 10,
  "parcelas": [
    { "parcela": 1, "valor": "100,00" },
    { "parcela": 2, "valor": "100,00" }
  ],
  "tipo": "purchase",
  "categoria_id": 1,
  "subcategoria_id": 1,
  "responsavel_id": 1,
  "observacoes": "..."
}
```

### Regras

- `valor_compra` = total da venda. Aceita BR (`125,50`) ou decimal. Alternativa: `valor` (ver legado abaixo).
- `parcelas_total` ∈ 1..36 (default 1).
- `parcelas[]` opcional: se omitido, backend divide `valor_compra` igualmente (centavos na última).
- Se `parcelas[]` vier: tamanho = `parcelas_total`, números 1..N sem buracos; soma deve bater com `valor_compra` (tol. R$ 0,01) → senão 422.
- Sempre materializa **1..N** transações (ignora `parcela_atual` no create).
- Mês da **data** = fatura da parcela 1; demais = +1 mês no mesmo cartão (`findOrCreateByCartaoPeriodo`).
- `data` da compra é gravada igual em todas as linhas; cada uma tem seu `fatura_id`.
- `parcelas_total > 1` → todas compartilham o mesmo `compra_grupo_id` (UUID).
- À vista (`parcelas_total = 1`): uma linha, `compra_grupo_id = null`.
- `fatura_id` explícito ainda é aceito (tela da fatura); o cartão vem da fatura. Sem `data`, usa mês/ano da fatura como base.
- Estabelecimento: `estabelecimento_id` **ou** `estabelecimento` (texto; find-or-create).
- Categoria/subcategoria: opcionais; create usa padrões do estabelecimento se omitidas.
- Subcategoria sem categoria → 422.
- Responsável omitido → `Eu`.

### Resposta do create

```json
{
  "transacao": {
    "data": {
      "compra_grupo_id": "uuid-ou-null",
      "valor_compra": 1000,
      "parcelas_total": 10,
      "transacoes": [ { "id": 1, "parcela_atual": 1, "...": "..." } ]
    },
    "status": true,
    "message": "Compra parcelada cadastrada com sucesso!"
  }
}
```

### Legado (breaking)

Payload antigo com `valor` + `parcelas_total > 1` **sem** `valor_compra`/`parcelas`:
- `valor` = valor **de cada** parcela
- Backend cria N linhas com esse valor (ex.: `valor=100`, `parcelas_total=10` → 10× R$ 100, total R$ 1000)

Com `valor_compra`, o total é dividido em N.

### À vista (exemplo)

```json
{
  "cartao_id": 1,
  "data": "2026-07-31",
  "estabelecimento_id": 104,
  "valor_compra": "125,50",
  "parcelas_total": 1,
  "tipo": "purchase"
}
```

Também aceita `valor` no lugar de `valor_compra` quando `parcelas_total` é 1.

## Edit

- Por linha (ajuste fino de valor/parcela/fatura).
- Flag `propagar_grupo: true`: propaga estabelecimento, categoria, subcategoria, responsável e observações para as irmãs do mesmo `compra_grupo_id` (não propaga valor/fatura/parcela_*).

## Delete

- Default: exclui só a linha.
- `?excluir_grupo=1` (ou body `excluir_grupo`): soft-delete de todas as parcelas do `compra_grupo_id`.

## Import PDF/CSV/XML

- Resolve estabelecimento pelo nome (cria se necessário).
- Aplica padrões do estabelecimento.
- Sempre define responsável `Eu`.
- **Não** cria `compra_grupo_id` (continua 1 linha por item da fatura; projeção cobre o restante).

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `subcategoria_id`, `estabelecimento_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `tipo`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`

Respostas expõem `estabelecimento` (nome), `categoria_*`, `subcategoria_*`, `responsavel_*`, `compra_grupo_id`.
