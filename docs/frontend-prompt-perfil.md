# Prompt — Frontend: Perfil do usuário

Use este prompt no repositório do **frontend** para criar a tela **Perfil** e exibir o nome do usuário logado no sistema, alinhado à API do `controle_fatura_back`.

Spec do back: [`modules/perfil.md`](modules/perfil.md).  
Auth já existente (cadastro, login, `me`): [`frontend-prompt-auth.md`](frontend-prompt-auth.md).

---

## Objetivo

1. Depois do login/cadastro, o **nome** (já informado no cadastro) aparece no sistema — header, menu, avatar de texto, etc.
2. No menu **Perfil**, o usuário vê e edita os próprios dados.
3. Formulário **simples**: só o que existe hoje. Não inventar campos.

---

## Fora de escopo (não implementar)

- Níveis de acesso, Administrador, papéis, permissões
- Campo **nome de usuário** / `username`
- Troca de senha nesta tela (já existe recuperar senha)
- Foto / avatar upload
- Validação de dígito verificador de CPF/CNPJ (o back só checa 11 ou 14 dígitos)

Cadastro de **faturas** não depende deste formulário estar completo. Sobrenome e CPF/CNPJ são opcionais — existem para o usuário ter o que preencher. O que importa agora: **nome** e **e-mail**.

---

## Onde mostrar o nome

O `user` já vem no login, no cadastro e no `GET /me`. Guardar no store de sessão (o mesmo de [`frontend-prompt-auth.md`](frontend-prompt-auth.md)).

Payload atualizado (campos novos podem ser `null`):

```json
{
  "id": 1,
  "name": "Leonardo",
  "sobrenome": null,
  "cpf_cnpj": null,
  "email": "leo@email.com"
}
```

Exibição no header / menu:

```
[name, sobrenome].filter(Boolean).join(' ')
```

Exemplos: `Leonardo` · `Leonardo Silva`.

Não mostrar `id`, `cpf_cnpj` nem e-mail no header (e-mail só no Perfil).

Se o store ficar desatualizado (F5), hidratar com `GET /api/v1/auth/me` (já previsto no bootstrap da área logada).

---

## Menu e rota

Item **Perfil** no menu do usuário logado (junto de logout).

Rota sugerida: `/perfil` (privada; sem token → `/login`).

---

## Tela Perfil

Formulário único, pré-preenchido.

| Campo (label) | Bind | Tipo | Obrigatório | Observação |
|---------------|------|------|-------------|------------|
| Nome | `name` | text | Sim | Já vem do cadastro |
| Sobrenome | `sobrenome` | text | Não | |
| CPF/CNPJ | `cpf_cnpj` | text | Não | Máscara no input; enviar dígitos ou mascarado (o back aceita os dois) |
| E-mail | `email` | email | Sim | Já vem do cadastro |

Botões: **Salvar**. Cancelar opcional (volta / descarta alterações).

Não pedir senha. Não mostrar `id`.

Placeholder / ajuda curta no CPF/CNPJ, por exemplo: `000.000.000-00 ou 00.000.000/0000-00`.

### Máscara CPF/CNPJ (só front)

Enquanto digita, decidir pela quantidade de dígitos:

- até 11 → CPF `000.000.000-00`
- 12 a 14 → CNPJ `00.000.000/0000-00`

Pode enviar no PUT o valor mascarado **ou** só dígitos. O back devolve **só dígitos** (`"12345678901"`). Ao carregar a tela, aplicar a máscara de novo.

### Validação no client (antes do PUT)

- `name` e `email` preenchidos
- e-mail com formato válido
- se CPF/CNPJ preenchido: 11 ou 14 dígitos (depois de tirar máscara)

---

## API

Bearer Sanctum em todas. Envelope igual ao auth: ler `auth.data.user`.

### Carregar

```http
GET /api/v1/auth/me
Authorization: Bearer {token}
```

Sucesso 200:

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
    "message": "Usuário autenticado"
  }
}
```

401 → limpar sessão e ir ao login (regra da etapa 2 de auth).

Pode usar o `user` já persistido no login e só refetch no `GET /me` ao abrir o Perfil (garante dados frescos).

### Salvar

```http
PUT /api/v1/auth/perfil
Authorization: Bearer {token}
Content-Type: application/json
```

```json
{
  "name": "Leonardo",
  "sobrenome": "Silva",
  "cpf_cnpj": "123.456.789-01",
  "email": "leo@email.com"
}
```

Campos opcionais vazios: enviar `""` ou omitir — o back grava `null`.

Sucesso 200 — **sem** token novo (a sessão continua):

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
    "message": "Perfil atualizado com sucesso!"
  }
}
```

1. Atualizar o store de sessão com `auth.data.user` (o nome no header muda na hora).
2. Toast com a `message` da API.
3. Loading no botão Salvar; não disparar de novo enquanto a request estiver andando.

### Erros (exibir `message`)

| HTTP | `message` |
|------|-----------|
| 401 | `Não autenticado` |
| 422 | `Nome e e-mail são obrigatórios` |
| 422 | `E-mail inválido` |
| 422 | `E-mail já cadastrado` |
| 422 | `CPF/CNPJ inválido` |

---

## Compatibilidade com auth

Login / cadastro / redefinir senha já devolvem o `user` expandido. Se o store antigo só tinha `id`, `name`, `email`, aceitar os novos campos (`sobrenome`, `cpf_cnpj`) sem quebrar.

Não enviar `user_id` no body (isolamento: o dono é o token).

---

## Checklist front

- [ ] Nome do cadastro visível no header/menu após login
- [ ] Item de menu **Perfil** na área logada
- [ ] Tela com nome, sobrenome, CPF/CNPJ e e-mail (sem username, sem níveis)
- [ ] Nome e e-mail obrigatórios; o resto opcional
- [ ] Máscara de CPF/CNPJ no input
- [ ] `GET /me` ao abrir a tela (ou bootstrap + refetch)
- [ ] `PUT /perfil` atualiza o store e o nome no header
- [ ] 401 → login; 422 mostra `message`
- [ ] Cadastro de faturas **não** bloqueia se sobrenome/CPF estiverem vazios
