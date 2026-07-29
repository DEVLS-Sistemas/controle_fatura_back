# Especificação — Auth

## Rotas (`/api/v1/auth`)

| Método | Endpoint | Auth | Descrição |
|--------|----------|------|-----------|
| POST | `/register` | Não | Cadastro + token + seed de categorias/responsáveis |
| POST | `/login` | Não | Login + token Sanctum |
| POST | `/logout` | Sim | Revoga token atual |
| GET | `/me` | Sim | Dados do usuário autenticado |

## Payload register/login

```json
{
  "name": "Leonardo",
  "email": "leo@email.com",
  "password": "secret123"
}
```

Login precisa apenas de `email` e `password`.

## Resposta

```json
{
  "auth": {
    "data": {
      "user": { "id": 1, "name": "...", "email": "..." },
      "token": "...",
      "token_type": "Bearer"
    },
    "status": true,
    "message": "..."
  }
}
```
