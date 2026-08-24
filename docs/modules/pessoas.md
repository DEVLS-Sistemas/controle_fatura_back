# Especificação — Pessoas (titulares)

Prompt do front: [`../frontend-prompt-pessoas.md`](../frontend-prompt-pessoas.md).

## Conceito

| Conceito | Significado | Módulo |
|----------|-------------|--------|
| **Usuário / login** | Quem entra no sistema | `users` + auth |
| **Pessoa** | Titular da fatura/cartão (dono do plástico) | `pessoas` |
| **Responsável** | Quem deve a compra | `responsaveis` (já existia) |

Uma **conta** (user) pode gerenciar várias **pessoas** (ex.: Leonardo + Maysa). Cartões e faturas pertencem a uma pessoa. Não misturar com responsável.

**Fora de escopo (proposital):**

- Bloquear import se o nome do PDF ≠ nome do cadastro
- “Sessões” por string de nome na tela de faturas (sem entidade)
- Papéis / administrador / username

## Tabela `pessoas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | dono do espaço |
| nome | string | obrigatório |
| sobrenome | string nullable | |
| cpf_cnpj | string(14) nullable | só dígitos |
| eh_principal | bool | pessoa do login; 1 por user; não exclui |
| ativo | bool | |

Soft deletes. No register e no backfill: cria a pessoa principal a partir do perfil. `PUT /auth/perfil` sincroniza a principal.

## Vínculos

| Tabela | Campo | Obs |
|--------|-------|-----|
| `cartoes` | `pessoa_id` nullable | dono do cartão |
| `faturas` | `pessoa_id` nullable | dono da fatura; filtro na listagem |

Auth `user` passa a incluir `pessoa_id` (id da principal).

## Rotas (`/api/v1/pessoas`)

CRUD padrão + `pessoas-list`.

| Método | Rota |
|--------|------|
| GET | `/lookups` |
| GET | `/listar` |
| GET | `/listar/{id}` |
| POST | `/cadastrar` |
| PUT | `/editar` |
| DELETE | `/excluir/{id}` |
| GET | `/pessoas-list` |

Não exclui principal. Não exclui se houver cartão/fatura vinculada.

## Confirmação de titular no cadastro de fatura

Após senha PDF e metadados (cartão/mês/ano), se o anexo tiver `nome_no_cartao` / titulares e **nenhum** bater (match frouxo) com pessoas da conta nem com `nome_no_cartao` já gravados no cartão:

```http
422
codigo: precisa_confirmar_titular
```

Não é rejeição definitiva. Retry com:

| Campo | Efeito |
|-------|--------|
| `pessoa_id` | Vincula fatura + cartão a pessoa existente |
| `cadastrar_pessoa=true` + `pessoa_nome` (ou nome+sobrenome) | Cria pessoa e vincula |
| `confirmar_titular=true` | Importa mesmo assim (usa pessoa do cartão se houver) |

Match frouxo: acentos/case, abreviação (`LEONARDO S FERREIRA` ≈ `Leonardo da Silva Ferreira`). Metadata do parser inclui `titulares[]`.

Ordem dos modais: senha → metadados → **titular** → **cartão do titular** (se o mês já tiver fatura de outra pessoa no mesmo cartão) → bandeira/final.

Match frouxo: acentos/case, abreviação (`LEONARDO S FERREIRA` ≈ `Leonardo da Silva Ferreira`). Metadata do parser inclui `titulares[]` (também via `Olá, Nome` / linha em CAIXA ALTA antes de `FATURA`, ex. Nubank).

**Unicidade:** uma fatura por `(cartão/bandeira + mês/ano)`. Duas pessoas no mesmo mês = **dois cartões**. Se o upload cair no cartão que já tem PDF do período com outro titular → `precisa_cartao_do_titular` (não sobrescreve).

## Responsável automático (outro titular)

Quando a fatura fica vinculada a uma **pessoa que não é a principal**:

1. O back cria (ou reutiliza) um **responsável** com o nome completo dessa pessoa (`tipo: pessoal`).
2. Grava `pessoas.responsavel_id` e `faturas.responsavel_id`.
3. No processamento do PDF/CSV, **todas as transações importadas** usam esse responsável (não o `Eu`).
4. Pessoa principal continua usando o responsável **`Eu`**.

Assim a fatura da Maysa já nasce com compras no responsável “Maysa …”, pronta para repasses / filtros.

Campos extras na listagem de faturas: `responsavel_id`, `responsavel_nome`.
Payload da pessoa: `responsavel_id`.
