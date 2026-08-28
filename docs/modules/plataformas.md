# Especificação — Plataformas de compra

Cadastro da **plataforma** onde a compra aconteceu (ex.: Loja Física, iFood, Mercado Livre). Escopo por usuário.

Não substitui `origem_compra` (canal fechado: online / presencial / serviços / fatura). A plataforma é um cadastro aberto, como categoria:

- Categoria: Alimentação
- Subcategoria: Pizzaria
- Origem: `COMPRAS_ONLINE`
- Plataforma: iFood

A mesma pizzaria na loja física: mesma categoria/sub, origem `COMPRAS_PRESENCIAL`, plataforma **Loja Física**.

## Tabela `plataformas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário (case-insensitive) |
| cor | string nullable | HEX tema. Vazio → `#000000`. Mesma paleta de categorias: [`cores-tema.md`](cores-tema.md) |
| ativo | boolean | default true |

## Relação

- Pode ser plataforma da compra (`transacoes.plataforma_id`, opcional)

Não há padrão no estabelecimento: a mesma loja pode vender no iFood e no balcão.

## Padrões no cadastro do usuário

No registro (`AuthService::seedDefaults`) e no backfill da migration, cada usuário recebe:

Loja Física, Mercado Livre, Shopee, Amazon, AliExpress, iFood, Magalu, Shein, Site da loja, Outros.

O usuário pode criar mais (Rappi, WhatsApp, …) pelo CRUD ou cadastro rápido.

## Rotas (`/api/v1/plataformas`)

CRUD padrão + `plataformas-list`.

Lookups: `cores` (HEX), `temas[]` (quadrados), `cor_padrao` (`#000000`) — iguais às categorias.

Reset em massa (junto com estabelecimentos/categorias): `DELETE /api/v1/estabelecimentos/excluir-todos`.

### Cadastro rápido

```http
POST /api/v1/plataformas/cadastrar-rapido
```

Body: `{ "nome": "...", "cor": "#ea1d2c" }` (`cor` opcional; omitida → preto `#000000`).

- Trim + unicidade **case-insensitive** por usuário
- Se já existir (ou soft-deleted): reutiliza / restaura — **não** retorna 422
- Resposta inclui `criado: true|false`

Uso no front (modal inline na compra): [`frontend-prompt-cadastro-rapido-plataforma.md`](../frontend-prompt-cadastro-rapido-plataforma.md).

Prompt da tela CRUD + formulário de compra: [`frontend-prompt-plataformas.md`](../frontend-prompt-plataformas.md).
