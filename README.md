# Controle de Faturas — Backend API

API REST Laravel para gestão de faturas de cartão de crédito, com upload/parsing de PDF, categorias, responsáveis e dashboard.

## Stack

- Laravel 10 + Sanctum (Bearer Token)
- MySQL
- Spatie PDF-to-Text (`pdftotext`)
- Jobs/Queues para processamento de PDF

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* no .env (DB_DATABASE=controleFaturaBack)
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=5000
```

Usuário demo criado pelo seeder:

- **E-mail:** `demo@demo.com`
- **Senha:** `123456`

Com fila assíncrona (recomendado em produção):

```bash
# .env
QUEUE_CONNECTION=database
php artisan queue:table && php artisan migrate
php artisan queue:work
```

> Em `local` com `QUEUE_CONNECTION=sync` o Job roda sincronamente no request.

Dependência do sistema: `pdftotext` (pacote `poppler-utils`).

```bash
# Debian/Ubuntu
sudo apt install poppler-utils
```

## Autenticação

```http
POST /api/v1/auth/register
POST /api/v1/auth/login
POST /api/v1/auth/logout   (Bearer)
GET  /api/v1/auth/me       (Bearer)
```

Envie o header: `Authorization: Bearer {token}`

No registro, o sistema já cria categorias e responsáveis padrão.

## Módulos (padrão CRUD)

Todos os módulos autenticados seguem:

| Método | Rota | Ação |
|--------|------|------|
| GET | `/lookups` | Lookups |
| GET | `/listar` | Listagem paginada |
| GET | `/listar/{id}` | Detalhe |
| POST | `/cadastrar` | Criar |
| PUT | `/editar` | Editar |
| DELETE | `/excluir/{id}` | Soft delete |
| GET | `/{modulo}-list` | Async select |

Prefixos:

- `/api/v1/cartoes`
- `/api/v1/categorias`
- `/api/v1/subcategorias`
- `/api/v1/estabelecimentos`
- `/api/v1/responsaveis`
- `/api/v1/faturas`
- `/api/v1/transacoes`
- `/api/v1/dashboard/resumo`
- `/api/v1/dashboard/projecao-faturas`

### Faturas — extras

```http
GET    /api/v1/faturas/listar         # agrupado por cartão; ordem competência→cartão→status; perPage = faturas
POST   /api/v1/faturas/upload-pdf     # multipart: id, arquivo_pdf, processar_automatico
POST   /api/v1/faturas/processar/{id} # reprocessa PDF
GET    /api/v1/faturas/pdf/{id}       # visualiza/baixa o PDF original
DELETE /api/v1/faturas/excluir-todas  # reset de testes: body/query confirmar=true
```

Prompts do front:

- Cartões (grupo → bandeira → número): [`docs/frontend-prompt-cartoes.md`](docs/frontend-prompt-cartoes.md)
- Faturas (bandeira + agrupamento por final): [`docs/frontend-prompt-faturas.md`](docs/frontend-prompt-faturas.md)
- Compras (seleção de `cartao_numero_id`): [`docs/frontend-prompt-compras.md`](docs/frontend-prompt-compras.md)
- Limpar faturas/transações (reset testes): [`docs/frontend-prompt-limpar-faturas.md`](docs/frontend-prompt-limpar-faturas.md)

### Transações — extras

```http
GET /api/v1/transacoes/exportar     # CSV (Excel) com os mesmos filtros da listagem
```

## Parsers de PDF

Ver guia completo em [`docs/pdf-parsers.md`](docs/pdf-parsers.md).

Parsers inclusos: **Nubank**, **Itaú**, **Inter**, **C6**, **PicPay**, **Sofisa** e **Genérico** (fallback).

## Padrões

Ver `docs/backend-patterns.md` e `docs/crud-template.md`.  
Especificações por módulo em `docs/modules/`.
