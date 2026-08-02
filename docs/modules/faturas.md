# Especificação — Faturas

## Tabela `faturas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| cartao_id | FK cartoes | |
| mes | tinyint 1-12 | Competência |
| ano | smallint | Competência |
| valor_total | decimal | atualizado no parsing do PDF e ao criar/editar/excluir transações |
| arquivo_pdf | string nullable | path em `storage/app/faturas/{user_id}` |
| status | enum | pendente, processando, processada, erro |
| erro_mensagem | text nullable | |
| processado_em | timestamp nullable | |

SoftDeletes + timestamps. Índice `(user_id, cartao_id, mes, ano)`.

O intervalo do ciclo (`periodo_inicio` / `periodo_fim` / `data_vencimento`) **não é coluna** — é calculado a partir de `mes`/`ano` + `dia_limite_fatura` / `dia_vencimento_fatura` do cartão (`Cartao::intervaloPeriodoFatura`).

## Criação automática via compra

Ao cadastrar transação com `cartao_id` + `data` (sem `fatura_id`), o backend usa o
`dia_limite_fatura` do cartão para calcular o período (mês/ano), chama
`FaturaService::findOrCreateByCartaoPeriodo` e cria a fatura se ainda não existir (`status=pendente`).

No processamento de PDF, compras parceladas também disparam `findOrCreateByCartaoPeriodo` para as competências futuras das parcelas restantes — faturas criadas **sem** `arquivo_pdf`, apenas com a transação da parcela.

## Listagem (`GET /listar`) — agrupada por cartão

**Breaking:** a resposta deixa de ser uma lista plana de faturas.

- Ordenação: **competência** (`ano`/`mes` desc) → **cartão** (`nome`) → **status**
- Paginação é por **fatura** (`perPage`); a página é reagrupada por cartão em `data[]`
- Cada item de `data` é um grupo: dados do cartão + array `faturas`
- Faturas **não** incluem o array de transações (apenas `total_transacoes` / `transacoes_com_categoria`)
- Cada fatura traz `competencia`, `periodo_inicio`, `periodo_fim`, `data_vencimento`, `tem_pdf`

Filtros: `cartao_id`, `mes`, `ano`, `status`, `palavra_chave`, `page`, `perPage`.

Prompt do front: [`docs/frontend-prompt-faturas.md`](../frontend-prompt-faturas.md).

## Detalhe (`GET /listar/{id}`)

Inclui chip do cartão, intervalo do ciclo, `tem_pdf`, `pdf_url` e contadores.  
Transações devem ser buscadas em `GET /api/v1/transacoes/listar?fatura_id=`.

## Rotas (`/api/v1/faturas`)

CRUD padrão + extras:

- `POST /upload-pdf` — `id`, `arquivo_pdf` (multipart), `processar_automatico` (bool)
- `POST /processar/{id}` — dispara `ProcessInvoicePdfJob`
- `GET /pdf/{id}` — visualiza/baixa o PDF original (Bearer)

Ao excluir uma fatura (`DELETE /excluir/{id}`), as transações vinculadas também são soft-deleted.

## Parsing PDF

Arquitetura em `App\Services\Pdf`:

1. `InvoicePdfParserService` extrai texto via Spatie PDF-to-Text
2. Seleciona parser (`Nubank`, `Itau`, `Inter`, `C6`, `Generico`)
3. Job cria `transacoes` e atualiza `valor_total` / `status`

Guia completo: [`docs/pdf-parsers.md`](../pdf-parsers.md).
