# Especificação — Responsáveis

## Tabela `responsaveis`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | |
| tipo | enum | `pessoal` \| `empresa` |
| ativo | boolean | |

Seed automático no registro: Eu (pessoal), Empresa (empresa).

O responsável `Eu` é o **default** em create de transação e em imports (PDF/CSV/XML) **quando a fatura não tem outro padrão**.

Quando a fatura é de **outro titular** (pessoa não principal), o back cria/reutiliza um responsável com o nome dessa pessoa e grava em `faturas.responsavel_id` — o import usa esse responsável. Ver [`pessoas.md`](pessoas.md).

Em `transacoes.responsavel_id` o campo é obrigatório.

## Rotas (`/api/v1/responsaveis`)

CRUD padrão + `responsaveis-list`.

```http
GET /api/v1/responsaveis/visualizar/{id}?mes=8&ano=2026
```

Hub do responsável: cadastro + contadores (compras, em aberto, repasse, competência) + cartões/categorias + atalhos para fatura virtual, fatura do cartão, repasses e compras.

- `compras` = eventos (parcelado = 1). `ocorrencias` = linhas `purchase` nas faturas
- `em_aberto` = parceladas ativas na competência (igual ranking)
- `repasse` = o que o responsável ainda deve ao usuário (igual matriz)
- `competencia` / `por_cartao` = só o mês/ano da query (default: atual)
- `totais` / `por_categoria` = histórico completo

Prompt: [`frontend-prompt-visualizacao-responsavel.md`](../frontend-prompt-visualizacao-responsavel.md)
