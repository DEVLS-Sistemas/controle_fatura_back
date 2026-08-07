# Especificação — Faturas

## Tabela `faturas`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| cartao_id | FK cartoes | Grupo (ciclo, cores, listagem) |
| cartao_bandeira_id | FK cartao_bandeiras | **Fatura é da bandeira** (Visa/Master separados) |
| mes | tinyint 1-12 | Competência |
| ano | smallint | Competência |
| valor_total | decimal | atualizado no parsing do PDF e ao criar/editar/excluir transações |
| arquivo_pdf | string nullable | path em `storage/app/faturas/{user_id}` |
| status | enum | pendente, processando, processada, erro |
| erro_mensagem | text nullable | |
| erro_codigo | string nullable | Ex.: `pdf_senha_necessaria`, `pdf_senha_incorreta` |
| processado_em | timestamp nullable | |

SoftDeletes + timestamps. Índice único lógico `(user_id, cartao_bandeira_id, mes, ano)`.

O intervalo do ciclo (`periodo_inicio` / `periodo_fim` / `data_vencimento`) **não é coluna** — é calculado a partir de `mes`/`ano` + ciclo do **grupo** (`Cartao::intervaloPeriodoFatura`).

Hierarquia de cartões: [`cartoes.md`](cartoes.md).

## Criação automática via compra

O detalhe (`GET /listar/{id}`) inclui `grupos_por_cartao[]` com subtotais por final (`cartao_numero_id` / `ultimos_digitos`), além dos contadores. As linhas continuam em `GET /transacoes/listar?fatura_id=`.

Ao cadastrar transação com `cartao_id` / `cartao_numero_id` / `cartao_bandeira_id` + `data` (sem `fatura_id`), o backend usa o
`dia_limite_fatura` do grupo para calcular o período (mês/ano), chama
`FaturaService::findOrCreateByCartaoPeriodo` (agora por **bandeira**) e cria a fatura se ainda não existir (`status=pendente`).

No processamento de PDF, compras parceladas também disparam `findOrCreateByCartaoPeriodo` para as competências futuras das parcelas restantes — faturas criadas **sem** `arquivo_pdf`, apenas com a transação da parcela. Transações importadas podem receber `cartao_numero_id` quando o parser identificar o final.

`POST /cadastrar` exige `cartao_id` + `cartao_bandeira_id` (quando o cartão já tem finais). Com PDF/CSV: se já existir fatura da bandeira/período, o endpoint anexa/substitui o arquivo e processa (não retorna 422). Sem arquivo no request, continua bloqueando com “Já existe fatura…”.

Quando o cartão **não tem finais** (`cartao_numeros`) e o request traz PDF/CSV, o backend exige seleção via modal (422 estruturado) — ver seção abaixo.

## Listagem (`GET /listar`) — agrupada por cartão

**Breaking:** a resposta deixa de ser uma lista plana de faturas.

- Ordenação: **competência** (`ano`/`mes` desc) → **cartão** (`nome`) → **status**
- Paginação é por **fatura** (`perPage`); a página é reagrupada por cartão em `data[]`
- Cada item de `data` é um grupo: dados do cartão + array `faturas`
- Faturas **não** incluem o array de transações (apenas `total_transacoes` / `transacoes_com_categoria`)
- Cada fatura traz `competencia`, `periodo_inicio`, `periodo_fim`, `data_vencimento`
- Cada fatura traz anexo: `tipo_arquivo` (`pdf`\|`csv`\|null), `tem_pdf`, `tem_csv`
- Cada fatura traz quitação: `pago`, `valor_pago`, `valor_restante` (ver regra abaixo)

Filtros: `cartao_id`, `mes`, `ano`, `status`, `palavra_chave`, `page`, `perPage`.

## Quitação da fatura (pagamentos)

Transações `tipo = payment` na fatura **N** abatem primeiro o `valor_total` da fatura **N-1** (mesma bandeira, competência contígua). O excedente antecipa o ciclo atual (já refletido no `valor_total` de N).

Residual da fatura anterior no `valor_total` só entra se a anterior estiver **`processada`**. Faturas `pendente` criadas por materialização de parcelas **não** geram residual — evita inflar o total e impedir a marcação de paga.

Portanto, a fatura **F** é considerada paga pelos pagamentos da competência **seguinte** (F+1):

| Campo | Significado |
|-------|-------------|
| `valor_total` | Total da fatura (saldo do ciclo, já líquido de antecipações na própria fatura) |
| `valor_pago` | Soma dos `payment` de F+1 aplicada a F (`min(pagamentos_F+1, valor_total)`) |
| `valor_restante` | `max(valor_total - valor_pago, 0)` |
| `pago` | `true` quando `valor_restante <= 0` |

Sem fatura F+1 (ou sem pagamentos nela): `valor_pago = 0`, `pago = false` (exceto se `valor_total = 0`).

No detalhe (`GET /listar/{id}`) também vêm os lançamentos de pagamento **desta** fatura:

| Campo | Significado |
|-------|-------------|
| `pagamentos_total` | Soma dos `payment` nesta fatura |
| `pagamentos_abatido_anterior` | Parte que quitou a fatura anterior |
| `pagamentos_antecipado` | Parte que antecipou o ciclo atual |

## Excluir todas (reset de testes)

```http
DELETE /api/v1/faturas/excluir-todas
{ "confirmar": true }
```

Soft-delete de **todas** as faturas e transações do usuário autenticado; remove arquivos PDF do storage. Não apaga cartões nem cadastros auxiliares. Exige `confirmar=true` (body ou query). Ver prompt: [`frontend-prompt-limpar-faturas.md`](../frontend-prompt-limpar-faturas.md).

Prompt do front: [`docs/frontend-prompt-faturas.md`](../frontend-prompt-faturas.md).  
Melhorias (anexos PDF/CSV, quitação, navegação): [`docs/frontend-prompt-melhorias-faturas.md`](../frontend-prompt-melhorias-faturas.md).

## Detalhe (`GET /listar/{id}`)

Inclui chip do cartão, intervalo do ciclo, anexo (`tipo_arquivo`, `tem_pdf`, `tem_csv`, `pdf_url`), contadores, quitação (`pago`, `valor_pago`, `valor_restante` + breakdown `pagamentos_*`) e navegação (`fatura_anterior_id`, `fatura_proxima_id`, competências vizinhas da mesma bandeira).  
Transações devem ser buscadas em `GET /api/v1/transacoes/listar?fatura_id=`.

## Rotas (`/api/v1/faturas`)

CRUD padrão + extras:

- `POST /upload-pdf` — `id`, `arquivo_pdf` (multipart PDF/CSV), `processar_automatico` (bool), opcional `senha_pdf`, `salvar_senha_pdf`, e campos do modal (`cartao_bandeira_id` / `bandeira`, `cartao_numero_id` / `ultimos_digitos`)
- `POST /processar/{id}` — dispara `ProcessInvoicePdfJob`; body opcional `{ "senha_pdf", "salvar_senha_pdf" }`. Em erro de senha retorna **422** com `codigo` + objeto `senha_pdf`.
- `GET /pdf/{id}` — visualiza/baixa o anexo (PDF ou CSV) (Bearer)

Ao excluir uma fatura (`DELETE /excluir/{id}`), as transações vinculadas também são soft-deleted.

## Modal bandeira / final (cartão sem finais)

Dispara em `POST /cadastrar` e `POST /upload-pdf` quando o cartão **não tem nenhum `cartao_numeros` ativo** e há arquivo PDF ou CSV.

### PDF

Sem `cartao_bandeira_id` / `bandeira` → **422**:

```json
{
  "error": true,
  "message": "Selecione a bandeira da fatura",
  "codigo": "precisa_selecionar_bandeira",
  "precisa_selecionar_bandeira": true,
  "bandeiras": [
    { "value": 1, "label": "Mastercard", "qtd_numeros": 0 }
  ]
}
```

- Cartão com bandeiras → lista existentes (`value` = id).
- Cartão sem bandeiras → lookups (`Visa`, `Mastercard`, …) com `criar: true` e `value: null`.
- Retry: `cartao_bandeira_id` **ou** `bandeira` (nome do lookup; cria/reusa no cartão).
- Finais detectados no PDF são criados na bandeira escolhida.

### CSV

1. Mesma exigência de bandeira.
2. Se a fatura **não tem** `arquivo_pdf` e o request **não traz** `cartao_numero_id` nem `ultimos_digitos` → **422**:

```json
{
  "error": true,
  "message": "Selecione o final do cartão",
  "codigo": "precisa_selecionar_final",
  "precisa_selecionar_final": true,
  "cartao_bandeira_id": 1,
  "numeros": []
}
```

- Retry: `cartao_numero_id` **ou** `ultimos_digitos` (4 dígitos; cria o final na bandeira).
- Se a fatura já tem PDF vinculado, o final **não** é exigido no CSV.
- O job aplica o final padrão a todas as linhas importadas sem `ultimos_digitos`.

## Senha de PDF

A senha fica no **cartão** (`cartoes.senha_pdf`, criptografada). O job usa, nesta ordem: senha do request → senha do cartão.

Se o PDF estiver protegido e a senha faltar ou estiver errada:

- `status=erro`
- `erro_codigo` = `pdf_senha_necessaria` | `pdf_senha_incorreta`
- Respostas incluem `precisa_senha_pdf` e `senha_pdf` (orientação da regra, sem a senha em claro)

`salvar_senha_pdf=true` grava a senha no cartão **após** desbloqueio bem-sucedido.

Prompt do front: [`frontend-prompt-senha-pdf-fatura.md`](../frontend-prompt-senha-pdf-fatura.md).

## Parsing PDF

Arquitetura em `App\Services\Pdf`:

1. `InvoicePdfParserService` extrai texto via Spatie PDF-to-Text (`-upw` quando há senha)
2. Seleciona parser (`Nubank`, `Itau`, `Inter`, `C6`, `PicPay`, `Sofisa`, `Generico`)
3. Job cria `transacoes` e atualiza `valor_total` / `status`

Guia completo: [`docs/pdf-parsers.md`](../pdf-parsers.md).
