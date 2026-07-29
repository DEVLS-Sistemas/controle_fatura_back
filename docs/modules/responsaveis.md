# Especificação — Responsáveis

## Tabela `responsaveis`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| nome | string | |
| tipo | enum | `pessoal` \| `empresa` |
| ativo | boolean | |

Seed automático no registro: Eu (pessoal), Empresa (empresa).

## Rotas (`/api/v1/responsaveis`)

CRUD padrão + `responsaveis-list`.
