# Especificação — Auth (cadastro, isolamento, recuperar senha, login)

Prompt correspondente do front (mesmas etapas): [`../frontend-prompt-auth.md`](../frontend-prompt-auth.md).

Implementar **uma etapa por vez**, na ordem. O front usa o mesmo número de etapa.

| Etapa | Tema | Back hoje | Trabalho desta spec |
|-------|------|-----------|---------------------|
| **1** | Cadastro + sessão imediata | `POST /register` já cria token | Formalizar contrato, seed e resposta |
| **2** | Isolamento por `user_id` | Já existe nas tabelas e na maioria dos services | Formalizar a regra e auditar vazamento entre usuários |
| **3** | Recuperar senha (código 6 dígitos) | **Não existe** | Endpoints, tabela, e-mail, verificação e redefinição |
| **4** | Login com “lembrar-me” | `POST /login` sem o campo | Aceitar `lembrar_me` (e-mail persistido no front) |

---

## Rotas (`/api/v1/auth`)

| Método | Endpoint | Auth | Etapa | Descrição |
|--------|----------|------|-------|-----------|
| POST | `/register` | Não | 1 | Cadastro + token + seed de categorias/responsáveis |
| POST | `/login` | Não | 1 / 4 | Login + token Sanctum (`lembrar_me` na etapa 4) |
| POST | `/logout` | Sim | 1 | Revoga o token atual |
| GET | `/me` | Sim | 1 | Dados do usuário autenticado (`name`, `sobrenome`, `cpf_cnpj`, `email`) |
| PUT | `/perfil` | Sim | perfil | Atualiza dados do usuário logado — [`perfil.md`](perfil.md) |
| POST | `/recuperar-senha` | Não | 3 | Solicita código de 6 dígitos (não revela se o e-mail existe) |
| POST | `/verificar-codigo` | Não | 3 | Valida o código informado |
| POST | `/redefinir-senha` | Não | 3 | Troca a senha com e-mail + código válidos |

Envelope padrão de sucesso (já usado hoje):

```json
{
  "auth": {
    "data": { },
    "status": true,
    "message": "..."
  }
}
```

Erro:

```json
{
  "error": true,
  "message": "..."
}
```

O front **nunca** envia `user_id`. O backend obtém o dono via `Auth::id()` (token Sanctum).

---

# Etapa 1 — Cadastro de usuário (e sessão imediata)

## Objetivo

Quem se cadastra **já entra no sistema**. O `POST /register` devolve o mesmo formato de sessão do login (usuário + token Bearer). Não há passo extra de “agora faça login”.

## Payload — `POST /register`

```json
{
  "name": "Leonardo",
  "email": "leo@email.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

| Campo | Obrigatório | Regra |
|-------|-------------|-------|
| `name` | Sim | Não vazio |
| `email` | Sim | E-mail válido, único (ignora `deleted_at`) |
| `password` | Sim | Mínimo 6 caracteres |
| `password_confirmation` | Sim (front envia; back valida se vier) | Deve ser igual a `password` |

Mensagens:

| Situação | HTTP | Mensagem |
|----------|------|----------|
| Campos obrigatórios ausentes | 422 | `Nome, e-mail e senha são obrigatórios` |
| E-mail inválido | 422 | `E-mail inválido` |
| Senha curta | 422 | `A senha deve ter no mínimo 6 caracteres` |
| Confirmação diferente | 422 | `A confirmação da senha não confere` |
| E-mail já cadastrado | 422 | `E-mail já cadastrado` |

## Resposta 200

```json
{
  "auth": {
    "data": {
      "user": { "id": 1, "name": "Leonardo", "sobrenome": null, "cpf_cnpj": null, "email": "leo@email.com" },
      "token": "...",
      "token_type": "Bearer"
    },
    "status": true,
    "message": "Usuário cadastrado com sucesso!"
  }
}
```

`password` e `remember_token` **nunca** saem no JSON (`$hidden` do `User`).

## Efeitos colaterais no cadastro

1. Cria o usuário (`password` hashed).
2. Gera token Sanctum (`api-token`).
3. Faz seed **somente daquele usuário**:

Categorias: Alimentação, Transporte, Empresa, Lazer, Moradia, Saúde, Outros (com cores atuais do `AuthService::seedDefaults`).

Responsáveis: `Eu` (pessoal) e `Empresa` (empresa).

Nenhum outro usuário recebe esses registros.

## Login / logout / me (já existentes, fazem parte da etapa 1)

### `POST /login`

```json
{
  "email": "leo@email.com",
  "password": "secret123"
}
```

| Situação | HTTP | Mensagem |
|----------|------|----------|
| E-mail/senha ausentes | 422 | `E-mail e senha são obrigatórios` |
| Credenciais inválidas | 401 | `Credenciais inválidas` (não distinguir “e-mail não existe” vs “senha errada”) |
| Sucesso | 200 | Mesmo envelope do register, mensagem `Login realizado com sucesso!` |

### `POST /logout` (Bearer)

Revoga **somente** o token atual. 401 se não autenticado.

### `GET /me` (Bearer)

Devolve o usuário autenticado no envelope `auth`. 401 se não autenticado.

```json
{
  "auth": {
    "data": {
      "user": { "id": 1, "name": "Leonardo", "sobrenome": null, "cpf_cnpj": null, "email": "leo@email.com" }
    },
    "status": true,
    "message": "Usuário autenticado"
  }
}
```

## Fora de escopo desta etapa

- Ícone de olho na senha → só front (não implementar no back).
- Recuperar senha → etapa 3.
- `lembrar_me` → etapa 4.

## Checklist back — etapa 1

- [x] `POST /register` devolve `user` + `token` + `token_type`
- [x] Front consegue persistir o token e chamar rotas autenticadas sem novo login
- [x] Seed de categorias/responsáveis usa o `user_id` do recém-criado
- [x] E-mail duplicado retorna 422 sem criar segundo usuário
- [x] `GET /me` e `POST /logout` funcionam com o token do cadastro
- [x] `password_confirmation` validada quando enviada (`A confirmação da senha não confere`)
- [x] Payload de `user` com `id`, `name`, `sobrenome`, `cpf_cnpj`, `email` (sem senha; extras podem ser `null`)
- [x] `GET /me` no envelope `auth` (`data.user`)

---

# Etapa 2 — Isolamento por usuário (`user_id`)

## Objetivo

Faturas, cartões, compras e todo o restante são **do usuário logado**. O usuário A não vê, edita nem apaga dados do usuário B.

## Regra de ouro

1. Toda entidade de negócio tem dono (`user_id`) **ou** pertence a um pai que tem dono.
2. Todo `SELECT` / `UPDATE` / `DELETE` filtra por `Auth::id()`.
3. FKs de outro módulo (cartão, categoria, estabelecimento…) só são aceitas se também forem do mesmo `user_id`.
4. O client **não envia** `user_id`. Se enviar, **ignorar**.

## Tabelas com `user_id` direto

| Tabela | Observação |
|--------|------------|
| `cartoes` | Grupo do cartão |
| `categorias` | Seed no register |
| `subcategorias` | |
| `estabelecimentos` | |
| `lojas` | |
| `responsaveis` | Seed no register |
| `faturas` | PDF em `storage/app/faturas/{user_id}` |
| `transacoes` | |
| `repasses` | |
| `estabelecimento_categorias` | (legado / pivot interno) |

## Tabelas sem `user_id` (herdam do pai)

| Tabela | Dono via |
|--------|----------|
| `cartao_bandeiras` | `cartao_id` → `cartoes.user_id` |
| `cartao_numeros` | `cartao_bandeira_id` → `cartoes.user_id` |

Ao criar/editar bandeira ou número, validar que o cartão (e a bandeira) pertencem a `Auth::id()`.

## Jobs e arquivos

- `ProcessInvoicePdfJob` usa `fatura.user_id` (não o Auth do request — a fila não tem sessão).
- Path do PDF: `storage/app/faturas/{user_id}/...`. Download (`GET /faturas/pdf/{id}`) só se a fatura for do usuário autenticado.

## Endpoints de reset (testes)

`DELETE /faturas/excluir-todas` e `DELETE /estabelecimentos/excluir-todos` apagam **somente** os registros de `Auth::id()`.

## Auditoria (trabalho desta etapa no back)

Percorrer services e confirmar `->where('user_id', Auth::id())` (ou equivalente via join) em:

- listagem, detalhe, lookups, async-select
- cadastrar / editar / excluir
- upload/processar PDF, exportar CSV, matriz de repasses, dashboard/projeção/ranking

Se algum endpoint listar sem filtro de usuário, **corrigir nesta etapa** antes de seguir.

## Implementação (back)

- Trait `App\Models\Concerns\BelongsToUser` nas entidades com dono: `user_id` não vai no JSON; scopes `forUser` / `forAuthUser`.
- `RequestDataService::fromRequest()` remove `user_id` do payload. Controllers autenticados passam por aí — o client **não** escolhe o dono.
- Download/processamento de PDF/CSV só aceita path `faturas/{user_id}/...`.
- Job da fatura usa `fatura.user_id` (sem sessão) e valida o final padrão contra o cartão do dono.

## Checklist back — etapa 2

- [x] Nenhuma listagem autenticada devolve registro de outro `user_id`
- [x] Detalhe/`listar/{id}` de ID alheio → 404 (não 403 com vazamento de existência, salvo padrão já usado no módulo)
- [x] Cadastro grava `user_id = Auth::id()`
- [x] FK de outro usuário → 422 (`Cartão inválido`, `Categoria inválida`, etc.)
- [x] PDF/CSV e jobs respeitam o dono da fatura
- [x] Resets de teste não atravessam usuários
- [x] `user_id` enviado pelo client é ignorado (`RequestDataService`)
- [x] `user_id` não sai no JSON das entidades (`BelongsToUser`)

---

# Etapa 3 — Recuperar senha (código de 6 dígitos)

## Objetivo

Fluxo sem revelar se o e-mail existe:

1. Usuário informa o e-mail.
2. Se existir cadastro, envia e-mail com **código numérico de 6 dígitos**.
3. Usuário informa o código (front: 6 inputs).
4. Usuário define nova senha.
5. Código inválido/expirado falha; e-mail inexistente **não** é anunciado no passo 1.

## Tabela `password_reset_codes`

Não reutilizar `password_reset_tokens` (token opaco). Tabela própria:

| Campo | Tipo | Obs |
|-------|------|-----|
| id | bigint PK | |
| email | string | index |
| codigo | string | **hash** do código (nunca guardar em claro) |
| expires_at | timestamp | agora + 15 minutos |
| tentativas | unsigned int | default 0; máximo 5 |
| used_at | timestamp nullable | preenchido ao redefinir com sucesso |
| created_at / updated_at | timestamps | |

Um e-mail pode ter só **um** código ativo: ao solicitar de novo, invalidar os anteriores (`used_at = now()` ou delete).

## Configuração de e-mail

Usar `MAIL_*` do `.env`. Em local, Mailpit na porta `1025` (UI `8025`).

- Com `php artisan serve` no host: `MAIL_HOST=127.0.0.1` (padrão do `.env.example`).
- Só use `MAIL_HOST=mailpit` se a API estiver na mesma rede Docker do container Mailpit.

```bash
docker run -d --name mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit:latest
```

Mailable: `App\Mail\RecuperarSenhaMail`.

Conteúdo mínimo:

- Assunto: `Código para redefinir sua senha`
- Corpo: nome do app, o código em destaque (`123456`), validade (15 minutos), aviso para ignorar se não foi solicitado.

Nunca incluir a senha atual nem o token Sanctum.

## `POST /recuperar-senha`

```json
{
  "email": "leo@email.com"
}
```

### Comportamento

1. Validar formato de e-mail. Inválido → 422 `E-mail inválido`.
2. Sempre responder **200** com a **mesma** mensagem, exista ou não o usuário:

```json
{
  "auth": {
    "data": [],
    "status": true,
    "message": "Se o e-mail informado estiver cadastrado, um código será enviado."
  }
}
```

3. Se o e-mail **existir** (usuário não deletado):
   - throttle: no máximo 1 envio a cada 60 segundos por e-mail; se estiver no intervalo, **não envia de novo**, mas a resposta continua a mesma (não avisar o throttle ao client).
   - gerar código `random_int(0, 999999)` com `str_pad` de 6 dígitos;
   - gravar hash (`Hash::make`);
   - `expires_at` = now + 15 min;
   - enviar o Mailable.
4. Se **não existir**: não gravar código, não enviar e-mail, mesma resposta 200.
5. Evitar diferença óbvia de tempo (trabalho constante razoável; não precisa de dummy sleep sofisticado).

Não retornar o código no JSON (nem em `local`).

## `POST /verificar-codigo`

Usado pelo front depois dos 6 inputs, **antes** da tela de nova senha.

```json
{
  "email": "leo@email.com",
  "codigo": "123456"
}
```

| Situação | HTTP | Mensagem |
|----------|------|----------|
| E-mail/código ausentes ou código ≠ 6 dígitos | 422 | `Informe o e-mail e o código de 6 dígitos` |
| Código inválido, expirado, já usado, ou e-mail sem código | 422 | `Código inválido ou expirado` |
| Mais de 5 tentativas no código ativo | 422 | `Código inválido ou expirado` (invalidar o código) |
| Sucesso | 200 | `Código verificado` |

Sucesso:

```json
{
  "auth": {
    "data": { "email": "leo@email.com", "codigo_valido": true },
    "status": true,
    "message": "Código verificado"
  }
}
```

Não revelar “e-mail não cadastrado”. Incrementar `tentativas` em código existente e senha/hash que não confere.

O código **não** é consumido neste endpoint — só na redefinição. Assim o usuário pode errar a nova senha e tentar de novo enquanto o código valer.

## `POST /redefinir-senha`

```json
{
  "email": "leo@email.com",
  "codigo": "123456",
  "password": "novaSenha1",
  "password_confirmation": "novaSenha1"
}
```

| Situação | HTTP | Mensagem |
|----------|------|----------|
| Validação de campos | 422 | mensagens da etapa 1 (senha / confirmação) |
| Código inválido/expirado/usado | 422 | `Código inválido ou expirado` |
| Sucesso | 200 | Usuário **já autenticado** (mesmo envelope do login) |

No sucesso:

1. Atualizar `password`.
2. Marcar código como `used_at = now()`.
3. Revogar tokens Sanctum anteriores daquele usuário (força reauth em outros devices).
4. Emitir **novo** token e devolver no envelope de login (`Login`/`senha redefinida`).

Mensagem sugerida: `Senha redefinida com sucesso!`

## Fora de escopo

- Link mágico / token na URL (só código de 6 dígitos).
- Troca de senha autenticada (usuário logado em configurações) — não é esta etapa.

## Checklist back — etapa 3

- [x] Migration `password_reset_codes`
- [x] `POST /recuperar-senha` sempre 200 com a mensagem genérica (e-mail existente ou não)
- [x] E-mail enviado só quando o usuário existe
- [x] Código de 6 dígitos, hashed, expira em 15 min, throttle 60s
- [x] `POST /verificar-codigo` e `POST /redefinir-senha` não vazam existência do e-mail
- [x] Após redefinir: senha nova, tokens antigos revogados, novo token na resposta
- [x] Mailable testável com Mailpit em local

---

# Etapa 4 — Login com “lembrar-me”

## Objetivo

Checkbox **Lembrar-me**: o **e-mail** permanece preenchido na próxima visita. **Não** é “manter logado” e **não** grava a senha.

A persistência do e-mail é no **front** (`localStorage`). O back só reconhece o campo para o contrato ficar estável.

## Payload — `POST /login` (estendido)

```json
{
  "email": "leo@email.com",
  "password": "secret123",
  "lembrar_me": true
}
```

| Campo | Obrigatório | Regra |
|-------|-------------|-------|
| `email` / `password` | Sim | iguais à etapa 1 |
| `lembrar_me` | Não | boolean; default `false`; **ignorado para TTL do token** |

O token Sanctum continua até o logout (ou revogação na etapa 3). Não usar `remember_token` do Laravel (guard `web`/cookie) nesta API.

Resposta: **idêntica** à etapa 1, com ou sem `lembrar_me`.

## Checklist back — etapa 4

- [x] Login aceita `lembrar_me` sem erro de validação
- [x] Presença/ausência do campo não muda token, expiração nem o usuário retornado
- [x] Credenciais inválidas continuam 401 `Credenciais inválidas`

---

## Ordem de implementação

```
Etapa 1  →  Etapa 2  →  Etapa 3  →  Etapa 4
cadastro     isolamento   recuperar     lembrar-me
+ sessão     user_id      senha
```

Não iniciar a etapa 3 no back sem a 1 estável (o e-mail do cadastro é o destino do código). A etapa 2 pode ser auditada em paralelo à 1, mas precisa estar fechada antes de tratar o sistema como multi-usuário de verdade.

Depois das 4 etapas: tela **Perfil** (`PUT /perfil`, sem papéis) — [`perfil.md`](perfil.md).
