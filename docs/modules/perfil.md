# Especificação — Perfil do usuário

Prompt correspondente do front: [`../frontend-prompt-perfil.md`](../frontend-prompt-perfil.md).

Tela simples para o usuário **ver e editar** os próprios dados depois do login. Sem papéis, sem níveis de acesso, sem nome de usuário (`username`).

O cadastro de faturas **não** exige perfil completo. Nome e e-mail importam de verdade; sobrenome, CPF/CNPJ e renda mensal são opcionais. A renda alimenta o **Raio-X Financeiro**.

---

## Campos em `users`

| Campo | Tipo | Origem | Obrigatório |
|-------|------|--------|-------------|
| `name` | string | Cadastro (`POST /auth/register`) | Sim |
| `email` | string | Cadastro | Sim |
| `sobrenome` | string nullable | Perfil | Não |
| `cpf_cnpj` | string(14) nullable | Perfil | Não (só dígitos) |
| `renda_mensal` | decimal(12,2) nullable | Perfil / Raio-X | Não (BRL; usado no comprometimento) |

Não existe `username`. Não existe `role` / `nivel` / `administrador`.

`cpf_cnpj` é gravado **somente com dígitos**: 11 (CPF) ou 14 (CNPJ). Vazio vira `null`.

---

## Rotas (`/api/v1/auth`)

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| GET | `/me` | Sim | Dados do usuário autenticado (já existia; payload expandido) |
| PUT | `/perfil` | Sim | Atualiza nome, sobrenome, CPF/CNPJ, renda mensal e e-mail |

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
        "renda_mensal": 11400.0,
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
  "renda_mensal": "11400,00",
  "email": "leo@email.com"
}
```

| Campo | Obrigatório | Regra |
|-------|-------------|-------|
| `name` | Sim | Não vazio |
| `email` | Sim | E-mail válido; único (ignora o próprio id e `deleted_at`) |
| `sobrenome` | Não | Trim; vazio → `null` |
| `cpf_cnpj` | Não | Aceita mascarado; guarda só dígitos; vazio → `null`; se preenchido, 11 ou 14 dígitos |
| `renda_mensal` | Não | BRL (`11400,00` / `11.400,00` / número). Omitir no PUT **mantém** o valor. `""` / `null` apaga. Zero ou negativo → 422 |

Não altera senha. Não altera token.

| Situação | HTTP | Mensagem |
|----------|------|----------|
| Sem token | 401 | `Não autenticado` |
| Nome ou e-mail vazio | 422 | `Nome e e-mail são obrigatórios` |
| E-mail inválido | 422 | `E-mail inválido` |
| E-mail de outro usuário | 422 | `E-mail já cadastrado` |
| CPF/CNPJ com tamanho diferente de 11/14 | 422 | `CPF/CNPJ inválido` |
| Renda mensal ≤ 0 ou não numérica | 422 | `Renda mensal inválida` |
| Sucesso | 200 | `Perfil atualizado com sucesso!` |

---

## Fora de escopo

- Níveis / administrador / permissões
- `username`
- Troca de senha no perfil (já existe recuperar senha)
- Validação de dígitos verificadores de CPF/CNPJ (só tamanho)
- Foto / avatar

Renda mensal alimenta o Raio-X: [`raio-x.md`](raio-x.md) · [`../frontend-prompt-raio-x.md`](../frontend-prompt-raio-x.md)

## Checklist back

- [x] Colunas `sobrenome` e `cpf_cnpj` em `users`
- [x] Coluna `renda_mensal` em `users`
- [x] `toAuthArray()` com `id`, `name`, `sobrenome`, `cpf_cnpj`, `renda_mensal`, `email`
- [x] `GET /me` devolve o payload expandido
- [x] `PUT /perfil` autenticado, sem senha, sem papéis
- [x] CPF/CNPJ normalizado para dígitos
- [x] Renda mensal opcional (BRL); omitir no PUT não apaga
