# Especificação — Categorias

Cadastro de categorias (ex.: Alimentação). Escopo por usuário.

## Tabela `categorias`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | único por usuário |
| cor | string nullable | HEX tema. Vazio → `#000000`. Paleta e gráficos: [`cores-tema.md`](cores-tema.md) |
| ativo | boolean | default true |

## Catálogo nativo

No cadastro, o usuário recebe as 20 categorias e as subcategorias de `CatalogoCategoriasNativas`, com a cor do tema na categoria e a cor da variação no pivot. A migration `2026_10_07_200000_seed_catalogo_categorias_nativas` completa o mesmo catálogo para quem já existia.

O match é pelo nome, case-insensitive, no escopo do usuário. Linha já existente é reutilizada: nome, cor e `ativo` não são reescritos, e soft delete não é restaurado. Nome de subcategoria repetido no catálogo (`Outros`, `Software`, `Celular`, `Acessórios`) é uma linha só, com cor diferente em cada vínculo. Inativar e editar seguem o CRUD.

Categoria antiga cujo nome existe **só** como subcategoria do catálogo, com um único pai (ex.: Açougue → Alimentação), deixa de ser categoria. Compras e padrão de estabelecimento passam para a categoria pai e essa subcategoria. Nome que também é categoria do catálogo (ex.: Outros, Restaurante) permanece categoria.

## Relações

- N:N com subcategorias (`categoria_subcategoria`)
- Pode ser padrão de estabelecimentos (`categoria_padrao_id`)
- Pode ser categoria da compra (`transacoes.categoria_id`)

## Rotas (`/api/v1/categorias`)

CRUD padrão + `categorias-list`.

Lookups: `cores` (HEX), `temas[]` (quadrados), `cor_padrao` (`#000000`). Etapas de cor: [`cores-tema.md`](cores-tema.md) · front: [`../frontend-prompt-cores-tema.md`](../frontend-prompt-cores-tema.md).

Reset em massa (junto com estabelecimentos e subcategorias): `DELETE /api/v1/estabelecimentos/excluir-todos` — ver [`estabelecimentos.md`](estabelecimentos.md) e [`frontend-prompt-limpar-estabelecimentos.md`](../frontend-prompt-limpar-estabelecimentos.md).

### Cadastro rápido

```http
POST /api/v1/categorias/cadastrar-rapido
```

Body: `{ "nome": "...", "cor": "#14b8a6" }` (`cor` opcional; omitida → preto `#000000`).

- Trim + unicidade **case-insensitive** por usuário
- Se já existir (ou soft-deleted): reutiliza / restaura — **não** retorna 422
- Resposta inclui `criado: true|false`

Uso no front (modal inline na compra/fatura): [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](../frontend-prompt-cadastro-rapido-categoria-subcategoria.md).
