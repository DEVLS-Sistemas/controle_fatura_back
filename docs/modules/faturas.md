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
| arquivo_csv | string nullable | path CSV/XML |
| anexo_hash | char(64) nullable | SHA-256 do conteúdo do anexo; usado para detectar PDF/CSV duplicado |
| status | enum | pendente, processando, processada, erro |
| erro_mensagem | text nullable | |
| erro_codigo | string nullable | Ex.: `pdf_senha_necessaria`, `pdf_senha_incorreta` |
| processado_em | timestamp nullable | |

SoftDeletes + timestamps. Índice único `(user_id, cartao_id, bandeira_periodo_key, mes, ano)` — `bandeira_periodo_key` é `IFNULL(cartao_bandeira_id, 0)`. Cadastro, upload e `findOrCreateByCartaoPeriodo` reusam a linha existente (incluindo stub sem anexo / sem bandeira). Duplicatas ativas do mesmo período são unificadas na listagem (fica a que tem PDF; transações que não batem com o extrato são movidas).

O intervalo do ciclo (`periodo_inicio` / `periodo_fim` / `data_vencimento`) **não é coluna** — é calculado a partir de `mes`/`ano` + ciclo do **grupo** (`Cartao::intervaloPeriodoFatura`).

Hierarquia de cartões: [`cartoes.md`](cartoes.md).

## Criação automática via compra

O detalhe (`GET /listar/{id}`) inclui `grupos_por_cartao[]` com subtotais por final (`cartao_numero_id` / `ultimos_digitos`) e o grupo `pagamentos_financiamentos` (**só compras** sem final). Operações sem final não entram nesse grupo — o front lista em **Operacionais**, seção irmã depois de Pagamentos e Financiamentos. As linhas continuam em `GET /transacoes/listar?fatura_id=`.

Ao cadastrar transação com `cartao_id` / `cartao_numero_id` / `cartao_bandeira_id` + `data` (sem `fatura_id`), o backend usa o
`dia_limite_fatura` do grupo para calcular o período (mês/ano), chama
`FaturaService::findOrCreateByCartaoPeriodo` (agora por **bandeira**) e cria a fatura se ainda não existir (`status=pendente`).

No processamento de PDF, compras parceladas também disparam `findOrCreateByCartaoPeriodo` para as competências futuras das parcelas restantes — faturas criadas **sem** `arquivo_pdf`, apenas com a transação da parcela. Se a competência vizinha existia apagada (soft delete), a restauração **não** herda PDF/`processada` — fica stub `pendente`. Quitação (`pago`) da anterior continua vindo dos pagamentos de F+1; isso não implica `tem_pdf`.

`POST /cadastrar` exige `cartao_id` + `cartao_bandeira_id` (quando o cartão já tem finais). Com PDF/CSV: se já existir fatura da bandeira/período, o endpoint anexa/substitui o arquivo e processa (não retorna 422). Sem arquivo no request, continua bloqueando com “Já existe fatura…”.

Com anexo, a competência efetiva é a **lida no arquivo** (mês **e** ano). Um PDF de 07/2024 não é vinculado ao stub de 07/2026. `POST /upload-pdf` pode devolver outro `data.id` do que foi enviado. Prompt do front: [`frontend-prompt-pdf-competencia-ano.md`](../frontend-prompt-pdf-competencia-ano.md).

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

Filtros: `cartao_id`, `cartao_bandeira_id`, `mes`, `ano`, `mes_atual`, `status`, `palavra_chave`, `page`, `perPage`.

`mes_atual=1` aplica a competência calendário de hoje (preenche `mes`/`ano`). Sem `mes`/`ano`/`mes_atual`, a lista **não** recorta competência — o front usa isso ao desmarcar **Ir para Mês Atual**. Lookups expõem `competencia_atual` (`mes`, `ano`, `label`) e `anos[]`. A resposta da listagem inclui `competencia_atual` e `filtros` (`mes`, `ano`, `mes_atual_ativo`). Prompt: [`frontend-prompt-fatura-mes-atual.md`](../frontend-prompt-fatura-mes-atual.md).

## Quitação da fatura (pagamentos)

Transações `tipo = payment` na fatura **N** abatem primeiro o `valor_total` da fatura **N-1** (mesma bandeira, competência contígua). O excedente antecipa o ciclo atual (já refletido no `valor_total` de N).

Residual da fatura anterior no `valor_total` só entra se a anterior estiver **`processada`**. Faturas `pendente` criadas por materialização de parcelas **não** geram residual — evita inflar o total e impedir a marcação de paga.

Sem anterior processada, o total da fatura **anexada** ainda honra o PDF: pagamentos com data **no mês da competência** antecipam o ciclo atual; pagamentos de meses anteriores não zerariam o extrato (ficam para quando a anterior for importada).

Portanto, a fatura **F** é considerada paga pelos pagamentos da competência **seguinte** (F+1):

| Campo | Significado |
|-------|-------------|
| `valor_total` | Total da fatura (saldo do ciclo, já líquido de antecipações na própria fatura) |
| `valor_pago` | Soma dos `payment` de F+1 aplicada a F (`min(pagamentos_F+1, valor_total)`) |
| `valor_restante` | `max(valor_total - valor_pago, 0)` |
| `pago` | `true` quando `valor_restante <= 0` |

Sem fatura F+1 (ou sem pagamentos nela): `valor_pago = 0`, `pago = false` (exceto se `valor_total = 0`).

Exceção — **stub anterior a uma fatura processada**: faturas `pendente` sem anexo (parcelas materializadas pelo PDF de um mês seguinte) são `pago = true` quando existe fatura **processada posterior** da mesma bandeira. O PDF importado já prova que aquele ciclo histórico foi quitado; o stub não é boleto em aberto. Stubs **futuros** (depois da última processada) continuam em aberto.

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
Ir para Mês Atual (listagem): [`docs/frontend-prompt-fatura-mes-atual.md`](../frontend-prompt-fatura-mes-atual.md).  
Melhorias (anexos PDF/CSV, quitação, navegação): [`docs/frontend-prompt-melhorias-faturas.md`](../frontend-prompt-melhorias-faturas.md).  
Cadastro com detecção de cartão/mês/ano pelo anexo: [`docs/frontend-prompt-cadastro-fatura-metadados.md`](../frontend-prompt-cadastro-fatura-metadados.md).  
Mesmo arquivo já anexado (hash → substituir ou manter): [`docs/frontend-prompt-fatura-anexo-duplicado.md`](../frontend-prompt-fatura-anexo-duplicado.md).  
Remover / trocar PDF (desfaz parcelas geradas + restaura compras conciliadas): [`fatura-anexo-desvincular.md`](fatura-anexo-desvincular.md) · [`docs/frontend-prompt-remover-pdf-fatura.md`](../frontend-prompt-remover-pdf-fatura.md).

## Cadastro (`POST /cadastrar`)

| Situação | `cartao_id` / `mes` / `ano` | Anexo |
|----------|-----------------------------|-------|
| Sem arquivo | **obrigatórios** | opcional |
| Com PDF/CSV e período completo | usados como hoje | processa |
| Com PDF/CSV **sem** período completo, arquivo casa com **um** stub (mesma competência/cartão, sem anexo) | preenchidos pelo back | anexa na existente (**200**, sem modal) |
| Com PDF/CSV **sem** período completo e sem stub único | opcionais | back tenta detectar → **422** `precisa_confirmar_metadados` |

Resposta de confirmação (resumo):

- `modo = confirmar_cartao` — cartão já existe; confirmar `cartao_id` + mês/ano (+ bandeira).
- `modo = cadastrar_cartao` — cartão **não** está na conta; UI cadastra **nome + bandeira na mesma tela** (não redirecionar para /cartoes).

```json
{
  "error": true,
  "codigo": "precisa_confirmar_metadados",
  "modo": "cadastrar_cartao",
  "pode_cadastrar_cartao": true,
  "precisa_selecionar_bandeira": true,
  "orientacao": "O cartão desta fatura ainda não está na sua conta. Informe o nome e a bandeira aqui no modal...",
  "sugestao": {
    "cartao_id": null,
    "cartao_nome_sugerido": "Inter",
    "mes": 7,
    "ano": 2026,
    "bandeira_sugerida": "Mastercard",
    "confianca": "baixa"
  },
  "bandeiras": [{ "value": null, "label": "Visa", "criar": true }]
}
```

Retry confirmar: `cartao_id` + `mes` + `ano` + arquivo (+ bandeira).  
Retry cadastrar: `cartao_nome` + `bandeira` + `mes` + `ano` + arquivo (cria cartão inline e a fatura). Preferir também `cadastrar_cartao=true`.  
Se o arquivo não permitir detecção → 422 pedindo preenchimento manual.

Se o PDF identificar **um** cartão + mês/ano e já existir fatura desse período **sem anexo**, o `POST /cadastrar` anexa nela e devolve **200** (não abre o modal). O 422 `precisa_confirmar_metadados` pode trazer `fatura_existente_id` quando ainda for preciso confirmar.

Se o arquivo tiver o **mesmo conteúdo** (SHA-256) de um anexo já gravado em outra fatura da conta → **422** `anexo_duplicado`. Retry: `confirmar_anexo_duplicado=substituir` (reprocessa na existente) ou `manter` (não cria outra). Não dispara ao reenviar o mesmo arquivo na **própria** fatura. Prompt: [`frontend-prompt-fatura-anexo-duplicado.md`](../frontend-prompt-fatura-anexo-duplicado.md).

## Detalhe (`GET /listar/{id}`)

Inclui chip do cartão, intervalo do ciclo, anexo (`tipo_arquivo`, `tem_pdf`, `tem_csv`, `pdf_url`), contadores, quitação (`pago`, `valor_pago`, `valor_restante` + breakdown `pagamentos_*`), totais de conciliação (`valor_extrato`, `valor_nao_conciliado`, `valor_total_com_pendencias`, `tem_compras_nao_conciliadas`) e navegação (`fatura_anterior_id`, `fatura_proxima_id`, competências vizinhas da mesma bandeira).  
Transações devem ser buscadas em `GET /api/v1/transacoes/listar?fatura_id=`.

Com compras manuais ainda abertas, `valor_total_com_pendencias` = extrato + manuais; o aviso só existe se `tem_compras_nao_conciliadas`. Prompt: [`frontend-prompt-faturas.md`](../frontend-prompt-faturas.md).

## Rotas (`/api/v1/faturas`)

CRUD padrão + extras:

- `POST /upload-pdf` — `id`, `arquivo_pdf` (multipart PDF/CSV), `processar_automatico` (bool), opcional `senha_pdf`, `salvar_senha_pdf`, e campos do modal (`cartao_bandeira_id` / `bandeira`, `cartao_numero_id` / `ultimos_digitos`)
- `POST /processar/{id}` — dispara `ProcessInvoicePdfJob`; body opcional `{ "senha_pdf", "salvar_senha_pdf" }`. Em erro de senha retorna **422** com `codigo` + objeto `senha_pdf`.
- `GET /pdf/{id}` — visualiza/baixa o anexo (PDF ou CSV) (Bearer)
- `GET /impacto-remover-anexo/{id}` — etapa 1: preview do que a remoção/troca do PDF desfaz (parcelas em vizinhas + compras que voltam a conciliar). Spec: [`fatura-anexo-desvincular.md`](fatura-anexo-desvincular.md)
- `POST /remover-anexo` — etapa 2: `{ id, motivo: "remover", tipo?: "pdf"|"csv"|"ambos" }`. Desfaz lançamentos, parcelas geradas em vizinhas e restaura compras manuais. Etapa 3: `motivo=trocar_pdf` + multipart `arquivo_pdf` (desfaz o errado e processa o certo).
- `GET /compras-para-reconcilia/{id}` — etapa 4: compras manuais ainda abertas nesta fatura + `candidatos` do extrato novo (reusa a conciliação). Lista vazia se o match exato do job já conciliou tudo.

Ao excluir uma fatura (`DELETE /excluir/{id}`), as transações vinculadas também são soft-deleted. A partir da etapa 2 de remover-anexo, a exclusão também desfaz parcelas que **este** PDF gerou em outras competências.

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

O genérico é fallback: **não homologado**. PDF de banco não testado em geral **não dá 500** — pode gravar total/compras errados. Front avisa e pede confirmação: [`docs/frontend-prompt-fatura-parser-homologado.md`](../frontend-prompt-fatura-parser-homologado.md).

Guia completo: [`docs/pdf-parsers.md`](../pdf-parsers.md).
