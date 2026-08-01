# Especificação — Faturas

## Tabela `faturas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| cartao_id | FK cartoes | |
| mes | tinyint 1-12 | |
| ano | smallint | |
| valor_total | decimal | atualizado no parsing do PDF e ao criar/editar/excluir transações |
| arquivo_pdf | string nullable | path em `storage/app/faturas/{user_id}` |
| status | enum | pendente, processando, processada, erro |
| erro_mensagem | text nullable | |
| processado_em | timestamp nullable | |

## Criação automática via compra

Ao cadastrar transação com `cartao_id` + `data` (sem `fatura_id`), o backend chama
`FaturaService::findOrCreateByCartaoPeriodo` e cria a fatura do período se ainda não existir (`status=pendente`).

## Rotas (`/api/v1/faturas`)

CRUD padrão + extras:

- `POST /upload-pdf` — `id`, `arquivo_pdf` (multipart), `processar_automatico` (bool)
- `POST /processar/{id}` — dispara `ProcessInvoicePdfJob`
- `GET /pdf/{id}` — visualiza/baixa o PDF original (Bearer)

Detalhe (`GET /listar/{id}`) inclui `tem_pdf`, `pdf_url` e `total_transacoes`.

Ao excluir uma fatura (`DELETE /excluir/{id}`), as transações vinculadas também são soft-deleted.

## Parsing PDF

Arquitetura em `App\Services\Pdf`:

1. `InvoicePdfParserService` extrai texto via Spatie PDF-to-Text
2. Seleciona parser (`Nubank`, `Itau`, `Inter`, `C6`, `Generico`)
3. Job cria `transacoes` e atualiza `valor_total` / `status`

Guia completo: [`docs/pdf-parsers.md`](../pdf-parsers.md).
