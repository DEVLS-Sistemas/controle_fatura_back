# Especificação — Responsáveis

## Tabela `responsaveis`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | |
| tipo | enum | `pessoal` \| `empresa` |
| ativo | boolean | |

Seed automático no registro: Eu (pessoal), Empresa (empresa).

O responsável `Eu` é o **default** em create de transação e em imports (PDF/CSV/XML).
Em `transacoes.responsavel_id` o campo é obrigatório.

## Rotas (`/api/v1/responsaveis`)

CRUD padrão + `responsaveis-list`.
