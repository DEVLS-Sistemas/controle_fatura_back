# Prompt — Frontend: Auth (cadastro, isolamento, recuperar senha, login)

Use este prompt no repositório do **frontend** para alinhar telas de autenticação à API do `controle_fatura_back`.

Spec do back (mesmas etapas): [`modules/auth.md`](modules/auth.md).

Implementar **uma etapa por vez**, no mesmo número que o back. Quando o back começar a etapa N, o front faz a etapa N.

| Etapa | Tema | Telas / comportamento |
|-------|------|------------------------|
| **1** | Cadastro + sessão imediata | Tela de cadastro; **sem** ícone de olho na senha; após sucesso, usuário já entra |
| **2** | Isolamento por usuário | Token no header; nunca enviar `user_id`; 401 → login |
| **3** | Recuperar senha | E-mail → 6 inputs do código → nova senha |
| **4** | Login com “lembrar-me” | Checkbox; e-mail persistido no `localStorage` |

Base: `/api/v1/auth`

Rotas públicas (sem Bearer): `register`, `login`, `recuperar-senha`, `verificar-codigo`, `redefinir-senha`.

Rotas autenticadas: `logout`, `me` e **todo o restante da API**.

Envelope de sucesso:

```json
{
  "auth": {
    "data": {
      "user": { "id": 1, "name": "...", "sobrenome": null, "cpf_cnpj": null, "renda_mensal": null, "email": "..." },
      "token": "...",
      "token_type": "Bearer"
    },
    "status": true,
    "message": "..."
  }
}
```

Erro: `{ "error": true, "message": "..." }`.

---

# Etapa 1 — Cadastro de usuário (já logado)

## Objetivo

1. Tela de cadastro com nome, e-mail, senha e confirmação.
2. **Não exibir ícone de olho** para revelar a senha (nem no campo senha, nem na confirmação). Inputs `type="password"` sem toggle.
3. No sucesso, persistir o token e **entrar no app** (mesmo fluxo pós-login). Não redirecionar para a tela de login.

## Tela

Campos:

| Campo | Tipo | Observação |
|-------|------|------------|
| Nome | text | obrigatório |
| E-mail | email | obrigatório |
| Senha | password | min 6; **sem** ícone de olho |
| Confirmar senha | password | deve coincidir; **sem** ícone de olho |

Link para “Já tenho conta” → tela de login.

Validação no client antes do POST: campos preenchidos, e-mail válido, senha ≥ 6, confirmação igual.

## API

```http
POST /api/v1/auth/register
```

```json
{
  "name": "Leonardo",
  "email": "leo@email.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

### Sucesso (200)

1. Guardar `auth.data.token` (mesmo storage do login).
2. Guardar `auth.data.user` se o app tiver store de sessão.
3. Configurar `Authorization: Bearer {token}` em **todas** as próximas requests.
4. Redirecionar para a home / dashboard (área logada).
5. Toast/mensagem da API (`Usuário cadastrado com sucesso!`).

### Erro

Exibir `message` (422 e-mail duplicado, senha curta, etc.). Não limpar o token antigo se a request falhar (não havia sessão).

## Login desta etapa (tela já existente)

Manter a tela de login funcionando com:

```http
POST /api/v1/auth/login
{ "email": "...", "password": "..." }
```

Mesmo tratamento de token/redirect do cadastro. Credenciais inválidas: 401 `Credenciais inválidas`.

Logout:

```http
POST /api/v1/auth/logout
Authorization: Bearer {token}
```

Limpar token + user no client e ir para o login, mesmo se o back falhar (token já inválido).

`GET /api/v1/auth/me` no bootstrap da área logada: se 401, limpar sessão e ir ao login.

Resposta 200 (mesmo envelope `auth`; **sem** token):

```json
{
  "auth": {
    "data": {
      "user": { "id": 1, "name": "Leonardo", "sobrenome": null, "cpf_cnpj": null, "renda_mensal": null, "email": "leo@email.com" }
    },
    "status": true,
    "message": "Usuário autenticado"
  }
}
```

Ler `auth.data.user` (não `data` na raiz).

## Fora de escopo desta etapa

- Olho na senha (não implementar).
- Recuperar senha (etapa 3) — um link “Esqueci a senha” pode existir desabilitado ou escondido até a etapa 3.
- Checkbox lembrar-me (etapa 4).

## Checklist front — etapa 1

- [ ] Cadastro sem ícone de olho nos dois campos de senha
- [ ] Confirmação de senha no client
- [ ] Sucesso do register = usuário **dentro** do sistema (token + redirect logado)
- [ ] Header Bearer nas rotas autenticadas
- [ ] Login/logout/`me` alinhados ao envelope `auth.data`

---

# Etapa 2 — Isolamento (front só fala pelo usuário logado)

## Objetivo

O back filtra por `user_id` do token. O front **não** escolhe o dono dos dados.

## Regras

1. **Nunca** enviar `user_id` em query, body ou path de CRUD. Se enviar, o back **ignora**. As respostas também **não** trazem `user_id`.
2. Um único token por sessão; trocar de conta = logout + login (não misturar caches).
3. Ao 401 em qualquer request autenticada: limpar token, caches (React Query / SWR / etc.) e redirecionar ao login.
4. Ao login/cadastro/redefinir senha: invalidar **todo** cache de listagens (faturas, cartões, compras…) — senão um usuário vê dados do anterior no mesmo browser.
5. Não persistir IDs de fatura/cartão de uma sessão para usar na próxima conta.
6. Detalhe de ID de outro usuário: **404** (`não encontrada`). Tratar como “não existe neste login”, não como erro de permissão.

## UX

Não mostrar “seu usuário #1” como filtro. O isolamento é invisível: cada um só vê o que cadastrou.

## Checklist front — etapa 2

- [ ] Nenhum formulário/query envia `user_id`
- [ ] 401 global → login + cache zerado
- [ ] Troca de usuário não reaproveita listagens em memória/localStorage de entidades
- [ ] Token só no storage de sessão combinado com o user atual

---

# Etapa 3 — Recuperar senha

## Objetivo

Três telas (ou um wizard na mesma rota), nesta ordem:

```
[1. E-mail]  →  [2. Código · 6 inputs]  →  [3. Nova senha]  →  área logada
```

**Não** avisar se o e-mail existe. Depois do passo 1, sempre a mesma mensagem do back e seguir para o código.

## Tela 1 — Informar e-mail

- Campo e-mail + botão “Enviar código”.
- Link voltar ao login.

```http
POST /api/v1/auth/recuperar-senha
{ "email": "leo@email.com" }
```

Sucesso (sempre 200 se o e-mail for válido):

> Se o e-mail informado estiver cadastrado, um código será enviado.

Guardar o e-mail na memória do fluxo (não precisa localStorage) e ir para a tela 2.

422 só se e-mail inválido/vazio. Loading no botão. Não chamar a tela 2 se a request falhar.

## Tela 2 — Código de 6 dígitos

- Texto: código enviado para o e-mail (mostrar o e-mail mascarado, ex. `le***@email.com`, ou o e-mail completo).
- **6 inputs** separados, um dígito cada (`inputMode="numeric"`, `maxLength={1}`, apenas `0-9`).
- UX:
  - ao digitar um dígito, focar o próximo;
  - Backspace no vazio foca o anterior;
  - **colar** um código de 6 dígitos preenche os seis de uma vez;
  - opcional: submeter automaticamente ao completar o 6º dígito.
- Link “Reenviar código” → de novo `POST /recuperar-senha` com o mesmo e-mail (respeitar o throttle silencioso do back; mesma mensagem genérica).
- **Não** ícone de olho (não há senha nesta tela).

```http
POST /api/v1/auth/verificar-codigo
{ "email": "leo@email.com", "codigo": "123456" }
```

Sucesso (`codigo_valido: true`) → tela 3, mantendo e-mail + código.

Erro 422 `Código inválido ou expirado` → limpar os inputs, focar o primeiro, mostrar a mensagem. **Não** dizer que o e-mail não existe.

## Tela 3 — Nova senha

- Senha + confirmação (min 6). Pode seguir o cadastro: **sem** ícone de olho (consistência).
- Botão “Redefinir senha”.

```http
POST /api/v1/auth/redefinir-senha
{
  "email": "leo@email.com",
  "codigo": "123456",
  "password": "novaSenha1",
  "password_confirmation": "novaSenha1"
}
```

Sucesso 200: mesmo envelope do login (`token` + `user`).

1. Persistir token/user.
2. Invalidar caches (etapa 2).
3. Entrar no app (área logada).
4. Toast `Senha redefinida com sucesso!`.

Código expirado no meio do passo 3: mensagem da API e voltar à tela 1 ou 2.

## Ligação com o login

Na tela de login, link **Esqueci a senha** → tela 1 desta etapa.

## Checklist front — etapa 3

- [ ] Passo 1: mensagem genérica; nunca “e-mail não encontrado”
- [ ] Passo 2: 6 inputs, foco automático, suporte a colar
- [ ] Reenviar código sem quebrar o fluxo
- [ ] Passo 3 redefine e **já autentica** com o token da resposta
- [ ] Erros de código inválido/expirado sem vazar existência do e-mail

---

# Etapa 4 — Login com “lembrar-me”

## Objetivo

Checkbox **Lembrar-me**: na próxima visita, o **e-mail** já vem preenchido. A senha **nunca** é salva. Não substitui o token (sessão continua sendo o Bearer até o logout).

## Tela de login

Campos: e-mail, senha, checkbox “Lembrar-me”, botão entrar, links cadastro + esqueci a senha.

```http
POST /api/v1/auth/login
{
  "email": "leo@email.com",
  "password": "secret123",
  "lembrar_me": true
}
```

Enviar `lembrar_me: true` só se o checkbox estiver marcado; caso contrário `false` ou omitir.

## Persistência (só front)

Chave sugerida: `auth.lembrar_email`.

| Ação | Comportamento |
|------|----------------|
| Login com checkbox **marcado** e sucesso | Salvar o e-mail no `localStorage` |
| Login com checkbox **desmarcado** e sucesso | Remover a chave |
| Abrir a tela de login | Se existir chave, preencher o e-mail e deixar o checkbox marcado |
| Logout | **Não** apagar o e-mail lembrado (só o token). Na próxima vez o e-mail continua lá |

Nunca gravar senha, token nesse mesmo chave, nem `user_id` como “lembrar-me”.

## Checklist front — etapa 4

- [ ] Checkbox visível no login
- [ ] E-mail preenchido automaticamente se o usuário marcou antes
- [ ] Senha nunca persistida
- [ ] Desmarcar e logar de novo esquece o e-mail
- [ ] Logout não apaga o e-mail lembrado

---

## Rotas de UI sugeridas

| Rota | Etapa | Auth |
|------|-------|------|
| `/login` | 1 / 4 | pública; se já tem token → home |
| `/cadastro` | 1 | pública; se já tem token → home |
| `/recuperar-senha` | 3 | pública (wizard e-mail → código → nova senha) |
| restante do app | 2 | privada; sem token → `/login` |

Guest e área logada: se autenticado tenta abrir `/login` ou `/cadastro`, redirecionar para a home.

---

## Ordem (igual ao back)

```
Etapa 1  →  Etapa 2  →  Etapa 3  →  Etapa 4
cadastro     isolamento   recuperar     lembrar-me
sem olho     401/cache    6 dígitos     e-mail salvo
já logado
```
