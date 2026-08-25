# Especificação — Perfil do usuário

Prompt correspondente do front: [`../frontend-prompt-perfil.md`](../frontend-prompt-perfil.md).

Tela simples para o usuário **ver e editar** os próprios dados depois do login. Sem papéis, sem níveis de acesso, sem nome de usuário (`username`).

O cadastro de faturas **não** exige perfil completo. Hoje só nome e e-mail importam de verdade; sobrenome e CPF/CNPJ existem para o usuário ter o que preencher.

---

## Campos em `users`

| Campo | Tipo | Origem | Obrigatório |
|-------|------|--------|-------------|
| `name` | string | Cadastro (`POST /auth/register`) | Sim |
| `email` | string | Cadastro | Sim |
| `sobrenome` | string nullable | Perfil | Não |
| `cpf_cnpj` | string(14) nullable | Perfil | Não (só dígitos) |

Não existe `username`. Não existe `role` / `nivel` / `administrador`.

`cpf_cnpj` é gravado **somente com dígitos**: 11 (CPF) ou 14 (CNPJ). Vazio vira `null`.

---

## Rotas (`/api/v1/auth`)

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/me` | Sim | Dados do usuário autenticado (já existia; payload expandido) |
| PUT | `/perfil` | Sim | Atualiza nome, sobrenome, CPF/CNPJ e e-mail |

Envelope de sucesso (igual ao resto do auth):

```json
{
  "auth": {
    "data": {
      "user": {
        "id": 1,
        "name": "Leonardo",
        "sobrenome": "Silva",
        "cpf_cnpj": "12345678901",
        "email": "leo@email.com"
      }
    },
    "status": true,
    "message": "..."
  }
}
```

Erro: `{ "error": true, "message": "..." }`.

Login, cadastro e redefinir senha passam a devolver o **mesmo** `user` (campos novos vêm `null` se ainda não preenchidos).

---

## `GET /me`

Idêntico ao contrato anterior, com os campos extras no `user`. 401 se não autenticado.

Mensagem: `Usuário autenticado`.

---

## `PUT /perfil`

```json
{
  "name": "Leonardo",
  "sobrenome": "Silva",
  "cpf_cnpj": "123.456.789-01",
  "email": "leo@email.com"
}
```

| Campo | Obrigatório | Regra |
|-------|-------------|-------|
| `name` | Sim | Não vazio |
| `email` | Sim | E-mail válido; único (ignora o próprio id e `deleted_at`) |
| `sobrenome` | Não | Trim; vazio → `null` |
| `cpf_cnpj` | Não | Aceita mascarado; guarda só dígitos; vazio → `null`; se preenchido, 11 ou 14 dígitos |

Não altera senha. Não altera token.

| Situação | HTTP | Mensagem |
|----------|------|----------|
| Sem token | 401 | `Não autenticado` |
| Nome ou e-mail vazio | 422 | `Nome e e-mail são obrigatórios` |
| E-mail inválido | 422 | `E-mail inválido` |
| E-mail de outro usuário | 422 | `E-mail já cadastrado` |
| CPF/CNPJ com tamanho diferente de 11/14 | 422 | `CPF/CNPJ inválido` |
| Sucesso | 200 | `Perfil atualizado com sucesso!` |

---

## Fora de escopo

- Níveis / administrador / permissões
- `username`
- Troca de senha no perfil (já existe recuperar senha)
- Validação de dígitos verificadores de CPF/CNPJ (só tamanho)
- Foto / avatar
- Renda mensal (`renda_mensal`) — fica para o Raio-X Financeiro: [`raio-x.md`](raio-x.md) · [`../frontend-prompt-raio-x.md`](../frontend-prompt-raio-x.md)

## Checklist back

- [x] Colunas `sobrenome` e `cpf_cnpj` em `users`
- [x] `toAuthArray()` com `id`, `name`, `sobrenome`, `cpf_cnpj`, `email`
- [x] `GET /me` devolve o payload expandido
- [x] `PUT /perfil` autenticado, sem senha, sem papéis
- [x] CPF/CNPJ normalizado para dígitos
