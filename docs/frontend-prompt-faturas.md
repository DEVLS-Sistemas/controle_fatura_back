# Prompt — Frontend: Faturas (listagem, detalhe e quitação)

Use este prompt no repositório do frontend para alinhar a tela de faturas à API do `controle_fatura_back`.

---

## Objetivo

A tela de faturas deve:

1. **Listar faturas agrupadas por cartão (grupo)** (não uma lista plana misturada)
2. Na listagem, indicar **anexo** (ícone PDF e/ou CSV) — não usar bandeira como coluna principal
3. **Não exibir transações** na listagem — só resumo da fatura
4. Exibir o **intervalo do ciclo** (início/fim) e a competência, com base no ciclo do **grupo**
5. Mostrar **quitação** em cada fatura: total, pago, restante e se está paga
6. Abrir o **detalhe** (e as transações) só ao clicar em uma fatura
7. No detalhe, repetir o bloco financeiro e **agrupar transações pelo final do cartão** (`ultimos_digitos`)
8. No detalhe, navegar **fatura anterior / próxima** da mesma bandeira

Melhorias recentes (anexos, quitação, navegação): [`frontend-prompt-melhorias-faturas.md`](frontend-prompt-melhorias-faturas.md).  
Remover / trocar PDF (desfazer extrato errado, etapas 1–4): [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md).

Hierarquia de cartões: ver [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md).

A fatura pertence à **bandeira** (`cartao_bandeira_id`), não ao número físico. É criada manualmente ou automaticamente ao cadastrar compras.

---

## Ciclo do cartão → competência

| Campo | Uso |
|-------|-----|
| `dia_limite_fatura` | Fecha o ciclo. Compras até este dia entram na fatura do mês; depois, na seguinte |
| `dia_vencimento_fatura` | Data de pagamento (informativo) |

Exemplo com limite = 5 e vencimento = 12:

| Compra | Competência | `periodo_inicio` | `periodo_fim` | `data_vencimento` |
|--------|-------------|------------------|---------------|-------------------|
| 05/08/2026 | 08/2026 | 06/07/2026 | 05/08/2026 | 12/08/2026 |
| 06/08/2026 | 09/2026 | 06/08/2026 | 05/09/2026 | 12/09/2026 |

Se `dia_vencimento <= dia_limite` (ex.: fecha 25, vence 05), o vencimento cai no **mês seguinte** à competência.

---

## API

Base: `/api/v1/faturas` (Bearer Sanctum)

### Listagem

```http
GET /api/v1/faturas/listar?perPage=5&page=1
GET /api/v1/faturas/listar?cartao_id=1&mes=8&ano=2026&status=pendente
Authorization: Bearer {token}
```

**Ordenação fixa:** competência (`ano`/`mes` desc) → cartão (`nome` asc) → `status` asc.

**Paginação é por fatura** (`perPage` = faturas). A página vem reagrupada por cartão em `data[]` (um cartão pode aparecer só com as faturas daquela página).

Filtros: `cartao_id`, `cartao_bandeira_id`, `mes`, `ano`, `status`, `palavra_chave`, `page`, `perPage`.

#### Resposta (`data`)

```json
{
  "current_page": 1,
  "per_page": 5,
  "total": 2,
  "data": [
    {
      "cartao_id": 1,
      "nome": "Sofisa",
      "banco": "Sofisa",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "ativo": true,
      "total_faturas": 2,
      "valor_total": 450.9,
      "faturas": [
        {
          "id": 10,
          "cartao_bandeira_id": 1,
          "bandeira": "Mastercard",
          "mes": 8,
          "ano": 2026,
          "competencia": "08/2026",
          "periodo_inicio": "2026-07-06",
          "periodo_fim": "2026-08-05",
          "data_vencimento": "2026-08-12",
          "valor_total": "150.90",
          "pago": false,
          "valor_pago": 0,
          "valor_restante": 150.9,
          "arquivo_pdf": "faturas/1/....pdf",
          "tipo_arquivo": "pdf",
          "tem_pdf": true,
          "tem_csv": false,
          "status": "pendente",
          "erro_mensagem": null,
          "processado_em": null,
          "total_transacoes": 3,
          "transacoes_com_categoria": 2,
          "created_at": "...",
          "updated_at": "..."
        }
      ]
    }
  ]
}
```

**Regras da listagem:**

- `data[]` = grupos de cartão (não faturas soltas)
- Cada fatura traz `cartao_bandeira_id` + `bandeira` (chip discreto só se o grupo tiver > 1 bandeira)
- Cada fatura traz anexo: `tipo_arquivo`, `tem_pdf`, `tem_csv` (coluna de ícones na listagem)
- Cada fatura traz contadores (`total_transacoes`) — **não** o array de transações
- Cada fatura traz quitação: `pago`, `valor_pago`, `valor_restante`
- Use `cor_fundo` / `cor_texto` no chip do grupo
- Formate datas em `dd/MM/yyyy`

### Detalhe

```http
GET /api/v1/faturas/listar/{id}
```

```json
{
  "id": 73,
  "cartao_id": 1,
  "cartao_bandeira_id": 1,
  "cartao_nome": "Sofisa",
  "cartao_bandeira": "Mastercard",
  "cartao_cor_fundo": "#8b5cf6",
  "cartao_cor_texto": "#ffffff",
  "cartao_dia_limite_fatura": 5,
  "cartao_dia_vencimento_fatura": 12,
  "mes": 8,
  "ano": 2026,
  "competencia": "08/2026",
  "periodo_inicio": "2026-07-06",
  "periodo_fim": "2026-08-05",
  "data_vencimento": "2026-08-12",
  "valor_total": "3565.87",
  "valor_extrato": 3565.87,
  "valor_nao_conciliado": 177.48,
  "valor_total_com_pendencias": 3743.35,
  "tem_compras_nao_conciliadas": true,
  "compras_nao_conciliadas_label": "Compras ainda não conciliadas",
  "pago": false,
  "valor_pago": 0,
  "valor_restante": 3565.87,
  "pagamentos_total": 0,
  "pagamentos_abatido_anterior": 0,
  "pagamentos_antecipado": 0,
  "arquivo_pdf": "faturas/1/....pdf",
  "tipo_arquivo": "pdf",
  "tem_pdf": true,
  "tem_csv": false,
  "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/73",
  "pode_remover_anexo": true,
  "fatura_anterior_id": 72,
  "fatura_proxima_id": 74,
  "fatura_anterior_competencia": "05/2026",
  "fatura_proxima_competencia": "07/2026",
  "status": "processada",
  "erro_mensagem": null,
  "processado_em": "...",
  "total_transacoes": 12,
  "transacoes_com_categoria": 10,
  "grupos_por_cartao": [],
  "created_at": "...",
  "updated_at": "..."
}
```

**Não** inclui a lista de transações. Buscar em:

```http
GET /api/v1/transacoes/listar?fatura_id={id}&perPage=50
```

### Outras rotas

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/lookups` | status, cartões (grupos), meses |
| POST | `/cadastrar` | multipart: `cartao_id`, `cartao_bandeira_id`, `mes`, `ano`, `arquivo_pdf?` (PDF/CSV), `processar_automatico?`; retry modal: `bandeira`, `cartao_numero_id`, `ultimos_digitos` |
| PUT | `/editar` | altera período/status/valor |
| DELETE | `/excluir/{id}` | soft-delete fatura + transações |
| POST | `/upload-pdf` | anexa PDF ou CSV (+ mesmos campos do modal) |
| POST | `/processar/{id}` | reprocessa arquivo |
| GET | `/pdf/{id}` | visualiza/baixa o anexo |
| GET | `/impacto-remover-anexo/{id}` | etapa 1: preview ao remover/trocar PDF — [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md) |
| GET | `/faturas-list` | select assíncrono |

Bandeiras do cartão:

```http
GET /api/v1/cartoes/bandeiras-list?cartao_id={id}
```

---

## Quitação — total / pago / restante

### Conceito (não calcule no front)

No extrato do cartão, um lançamento de **pagamento** (`tipo = payment`) às vezes quita a fatura **anterior**, às vezes antecipa a **atual**:

1. Pagamentos da fatura **N** abatem primeiro o `valor_total` da fatura **N-1** (mesma bandeira, mês contíguo)
2. Se a soma dos pagamentos **igualar** o total da anterior → fatura anterior **paga**
3. Se **superar** → o excedente é **pagamento antecipado** da fatura atual (já embutido no `valor_total` de N)
4. Podem existir **vários** pagamentos; a API soma todos e aplica a regra

A quitação da fatura **F** vem dos pagamentos da competência **seguinte** (F+1). O backend já calcula — use os campos prontos.

### Campos (listagem e detalhe)

| Campo | Tipo | Uso no front |
|-------|------|----------------|
| `valor_total` | number/string | Quitação (pago / restante). **Não** é o número grande do detalhe quando há compra manual aberta |
| `valor_extrato` | number | Valor **descrito no PDF** / lançamentos da fatura (sem as manuais abertas) |
| `valor_nao_conciliado` | number | Soma das compras **manuais** ainda `nao_conciliada` ou `pendente` nesta fatura |
| `valor_total_com_pendencias` | number | `valor_extrato + valor_nao_conciliado` — **este** é o “Total da fatura” na tela |
| `tem_compras_nao_conciliadas` | bool | `true` só se `valor_nao_conciliado > 0` |
| `compras_nao_conciliadas_label` | string\|null | `Compras ainda não conciliadas` (null se não houver) |
| `valor_pago` | number | **Total pago** (quanto da fatura já foi quitado) |
| `valor_restante` | number | **Total restante** (`valor_total - valor_pago`, mínimo 0) |
| `pago` | bool | Badge **Paga** / **Em aberto** (`true` quando restante é 0) |

> `status` (`pendente` / `processando` / `processada` / `erro`) é o **processamento do PDF**, não a quitação. Nunca use `status` para dizer se a fatura está paga.

Faturas `pendente` **sem PDF** também nascem quando um extrato parcelado é processado (o back copia 1/N…N/N para as competências vizinhas). Essas linhas vêm com `compra_manual: false` e **não** pedem conciliação. Ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).

### Campos extras só no detalhe

Explicam os pagamentos **lançados nesta fatura** (extrato):

| Campo | Significado |
|-------|-------------|
| `pagamentos_total` | Soma dos `payment` nesta fatura |
| `pagamentos_abatido_anterior` | Parte que pagou a fatura anterior |
| `pagamentos_antecipado` | Parte que antecipou esta fatura |

### Exemplos de estado

| Situação | `valor_total` | `valor_pago` | `valor_restante` | `pago` |
|----------|---------------|--------------|------------------|--------|
| Em aberto, sem pagamento seguinte | 307.25 | 0 | 307.25 | `false` |
| Paga por completo | 307.25 | 307.25 | 0 | `true` |
| Pagamento parcial | 80.00 | 50.00 | 30.00 | `false` |
| Saldo zerado (antecipação já líquida) | 0 | 0 | 0 | `true` |

### UI obrigatória

**Listagem (card da fatura):**

```
Total      R$ 307,25
Pago       R$ 307,25
Restante   R$ 0,00
[Paga]
```

**Detalhe (bloco financeiro em destaque):**

```
Total da fatura   R$ {valor_total_com_pendencias}
Total pago        R$ {valor_pago}
Restante          R$ {valor_restante}
Status            Paga | Em aberto   ← usa `pago`, não `status`
```

`valor_pago` / `valor_restante` / `pago` continuam sendo a **quitação do extrato** (`valor_total`). Não recalcular no front.

Opcional no detalhe (ajuda a ler o extrato):

```
Dos pagamentos desta fatura (R$ {pagamentos_total}):
  · R$ {pagamentos_abatido_anterior} quitou a fatura anterior
  · R$ {pagamentos_antecipado} antecipou este ciclo
```

Formate valores em BRL (`R$ 1.234,56`).

---

## Totalizador — compras manuais ainda não conciliadas (obrigatório no detalhe)

Cenário: o usuário cadastrou uma compra à mão (ex.: R$ 177,48) e depois anexou o PDF da fatura (extrato R$ 3.565,87). Os dois valores **existem ao mesmo tempo** até a conciliação.

**Não some as linhas no front.** Use os campos do `GET /faturas/listar/{id}`.

| Campo | Exemplo | Papel |
|-------|---------|--------|
| `valor_extrato` | 3565.87 | O que o PDF/extrato descreve |
| `valor_nao_conciliado` | 177.48 | Soma das manuais abertas (`nao_conciliada` / `pendente`) |
| `valor_total_com_pendencias` | 3743.35 | O que a tela mostra como **Total da fatura** |
| `tem_compras_nao_conciliadas` | true | Liga/desliga o aviso |

### Número grande

Sempre:

```
Total da fatura   R$ {valor_total_com_pendencias}
```

- Com manual aberta: **R$ 3.743,35** (3565,87 + 177,48)
- Depois de conciliar essa compra: **R$ 3.565,87** (igual ao extrato)

À medida que o usuário concilia, `valor_nao_conciliado` diminui e `valor_total_com_pendencias` cai até ficar **igual** a `valor_extrato`.

### Aviso (só se `tem_compras_nao_conciliadas === true`)

Bloco extra **abaixo** do total, âmbar, no mesmo espírito do badge `precisa_conciliar`. **Não renderizar** se `valor_nao_conciliado` for 0 (esconder o bloco inteiro — não mostrar R$ 0,00).

```
┌─────────────────────────────────────────────────────────┐
│ ⚠  Compras ainda não conciliadas          R$ 177,48     │
│    {compras_nao_conciliadas_label}                      │
│    Extrato da fatura: R$ 3.565,87                       │
│    Esse extra some ao conciliar a compra com o PDF.     │
└─────────────────────────────────────────────────────────┘
```

- Título: `compras_nao_conciliadas_label` (não hardcodar se a API mandar)
- Valor em evidência: `valor_nao_conciliado`
- Linha de apoio: “Extrato da fatura: {valor_extrato}”
- Copy curta: avisar que o total da tela está **acima** do PDF porque ainda há compra lançada à mão

Se houver 2+ manuais abertas, o back já soma. Um bloco só. Não listar cada compra aqui — as linhas da fatura já têm o badge âmbar.

### Depois de conciliar / desvincular / rejeitar

Refetch **os dois**:

1. `GET /faturas/listar/{id}` — atualiza `valor_total_com_pendencias` / `valor_nao_conciliado`
2. `GET /transacoes/listar?fatura_id=` — some a manual da lista, badge no lançamento real

Quando `tem_compras_nao_conciliadas` virar `false`, o aviso **desaparece** e o total da tela = `valor_extrato`.

### O que não fazer

- Não somar `observacoes` / linhas visíveis para achar o total
- Não usar só `valor_total` no H1 do detalhe (ele é da quitação; com manual aberta fica atrás do extrato + pendências)
- Não mostrar o aviso na **listagem** de faturas (só no `/faturas/view/{id}`)
- Parcelas automáticas (`compra_manual === false`) **não** entram em `valor_nao_conciliado`

---

## UI sugerida

### Cadastro de fatura — anexo primeiro + seleção de bandeira

> Fluxo completo (detecção de cartão/mês/ano pelo PDF, modal de confirmação):  
> [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md).  
> Cartão sem parser testado: aviso de que o valor pode estar errado — [`frontend-prompt-fatura-parser-homologado.md`](frontend-prompt-fatura-parser-homologado.md).

1. Formulário inicial: **nada obrigatório** (cartão, mês, ano e anexo são opcionais).
2. **Sem anexo** → cartão + mês + ano passam a ser obrigatórios.
3. **Com anexo** e sem cartão/mês/ano → o back pode devolver 422 `precisa_confirmar_metadados` com sugestões; abrir modal e reenviar.
4. Select **Cartão** (grupo) via `cartoes-list` / lookups (`tem_numeros` / `qtd_numeros`)
5. Buscar bandeiras: `GET /cartoes/bandeiras-list?cartao_id=`
6. Regra quando o cartão **já tem finais** (`tem_numeros === true`):
   - **1 bandeira** → pré-selecionar `cartao_bandeira_id` e **não exibir** o campo
   - **2+ bandeiras** → select obrigatório “Bandeira da fatura”
7. Regra quando o cartão **não tem finais** (`tem_numeros === false`) e há PDF/CSV:
   - Abrir **modal** com select de bandeiras (proativo ou após 422)
   - PDF: só bandeira; CSV: bandeira + final (ver abaixo)
8. Enviar `cartao_id` + `cartao_bandeira_id` (ou `bandeira` no retry do modal) no `POST /cadastrar`

### Modal — cartão sem finais (PDF / CSV)

Espírito igual ao modal de senha do PDF: o back devolve **422** com `codigo` e opções; o front reenvia o multipart com os campos escolhidos.

**Bandeira** (`codigo = precisa_selecionar_bandeira`):

- Usar `bandeiras[]` da resposta no select (`value`/`label`)
- Itens com `criar: true` → enviar `bandeira` (label) no retry
- Itens com `value` numérico → enviar `cartao_bandeira_id`
- **PDF:** após escolher bandeira, reenviar o upload; finais vêm do parser
- **CSV:** após bandeira, se ainda faltar final → segundo 422 (abaixo)

**Final** (`codigo = precisa_selecionar_final`) — só CSV sem PDF vinculado:

- Select com `numeros[]` da resposta, **ou** input de 4 dígitos se a lista estiver vazia
- Retry: `cartao_numero_id` **ou** `ultimos_digitos` (+ `cartao_bandeira_id` / `bandeira` já escolhidos)
- Se a fatura **já tem PDF**, não pedir final no CSV

### Tela de listagem

1. Filtros: cartão (grupo), bandeira (opcional), mês, ano, status do arquivo, busca
2. Para cada grupo da página:
   - Cabeçalho com chip (`background: cor_fundo; color: cor_texto`), nome, “Fecha dia X · Vence dia Y”
   - Subtotal do grupo (`valor_total` do grupo)
   - Cards/linhas das faturas **sem** expandir compras:
     - **Anexo:** ícone PDF se `tem_pdf`, ícone CSV se `tem_csv` (coluna no lugar da bandeira)
     - Chip discreto de bandeira **só** se o grupo tem mais de uma
     - Competência (`08/2026`)
     - Período: `06/07/2026 – 05/08/2026`
     - Vencimento
     - **Total / pago / restante** (`valor_total`, `valor_pago`, `valor_restante`)
     - Badge de quitação (`pago` → “Paga” / “Em aberto”)
     - Status do arquivo (`status`) — pendente/processada/erro (separado da quitação)
     - Contador “N lançamentos” (`total_transacoes`)
3. Clique na fatura → tela/drawer de detalhe
4. Ações na linha: upload PDF/CSV, processar, excluir, ver anexo
5. Após cadastrar/processar: **refetch da listagem** (a competência anterior pode mudar `pago`)

### Tela de detalhe

1. **Topo:** botões **Anterior** / **Próxima** (`fatura_anterior_id` / `fatura_proxima_id`, mesma bandeira)
2. Cabeçalho do grupo + **bandeira** + competência + intervalo + vencimento
3. **Bloco financeiro:** `valor_total_com_pendencias` como **Total da fatura** + `valor_pago` / `valor_restante` + badge `pago`. Se `tem_compras_nao_conciliadas`, o aviso âmbar com `valor_nao_conciliado` (ver seção *Totalizador*)
4. Opcional: breakdown `pagamentos_total` / `pagamentos_abatido_anterior` / `pagamentos_antecipado`
5. Status de processamento do arquivo (`status`) — não confundir com `pago`
6. Bloco de anexo PDF/CSV (preview / reprocessar)
7. **Só aqui** carregar transações via `GET /transacoes/listar?fatura_id=`  
   (a API ordena por `ultimos_digitos` asc → `data` asc quando `fatura_id` é informado)
8. **Agrupar a exibição por final do cartão** — usar `grupos_por_cartao` do detalhe para cabeçalhos/subtotais; linhas vêm de `/transacoes/listar`
9. **Seções Compras × Operacionais** (por **final do cartão** — como já era):
   - **Compras:** `tipo = purchase` daquele `cartao_numero_id`
   - **Operacionais:** `payment`, `refund`, `advance`, `fee`, `carryover` **daquele mesmo final**
10. **Pagamentos e Financiamentos** é seção **própria** (não envolve Operacionais): só `purchase` sem final (`grupo_chave = pagamentos_financiamentos`). Fica **depois dos cartões e antes** da Operacionais sem cartão. Prompt: [`frontend-prompt-fatura-pagamentos-financiamentos.md`](frontend-prompt-fatura-pagamentos-financiamentos.md).

```json
"grupos_por_cartao": [
  {
    "cartao_numero_id": 10,
    "ultimos_digitos": "7025",
    "tipo": "fisico",
    "apelido": null,
    "nome_no_cartao": "LEONARDO S FERREIRA",
    "grupo_chave": "cartao",
    "label": "•••• 7025 · LEONARDO S FERREIRA",
    "total_transacoes": 1,
    "valor_total": 1530.27
  },
  {
    "cartao_numero_id": 11,
    "ultimos_digitos": "7033",
    "tipo": "fisico",
    "apelido": null,
    "nome_no_cartao": "LEONARDO S FERREIRA",
    "grupo_chave": "cartao",
    "label": "•••• 7033 · LEONARDO S FERREIRA",
    "total_transacoes": 6,
    "valor_total": 1081.47
  },
  {
    "cartao_numero_id": null,
    "ultimos_digitos": null,
    "grupo_chave": "pagamentos_financiamentos",
    "label": "Pagamentos e Financiamentos",
    "total_transacoes": 1,
    "valor_total": 15.0
  }
]
```

UI sugerida dos grupos:

```
•••• 7025 · LEONARDO S FERREIRA    subtotal R$ …
  10/06  PAGAMENTO DE FATURA …

•••• 7033 · LEONARDO S FERREIRA    subtotal R$ …
  01/06  MP *ALIEXPRESS …

Pagamentos e Financiamentos        subtotal R$ …
  22/07  Thaís Araújo da Silva  R$ 52,96

Operacionais
  15/07  Pagamento em 15 JUL    − R$ 217,99
  21/07  Saldo restante da fatura anterior  R$ 0,00
```

Cada linha de transação traz `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_tipo`, `cartao_numero_apelido`, `cartao_numero_nome_no_cartao`.

**Título da linha de compra:** usar `texto_compra` / `observacoes` (o que foi comprado). **Não** usar `estabelecimento` como título se houver observação.

- `estabelecimento` `null` → mostrar **—**
- `precisa_conciliar === true` → destaque âmbar + badge `precisa_conciliar_label` (`Compra manual · conciliar com a fatura`). **Só** a compra que o usuário cadastrou. Parcelas automáticas (`compra_manual === false`) em faturas `pendente` (esperando anexo) **não** usam esse destaque
- `tem_sugestao_conciliacao === true` → no lançamento do PDF (ou na parcela automática, se o back achar o casamento), badge `sugestao_conciliacao_label` + botão **Confirmar** (`POST /transacoes/conciliar` com `compra_manual_vinculada.id` e o `id` da linha)
- `conciliada_com_manual === true` → badge `conciliada_com_manual_label`; clique abre `/compras/{compra_manual_vinculada.id}` (editar, anexos, desvincular)
- `conta_no_total === false` → exibir a linha, **não** incluir no subtotal do grupo

Não some as linhas no front para obter o total da fatura — use `valor_total_com_pendencias` (número grande) e, se houver, o aviso `valor_nao_conciliado`. `valor_extrato` é o valor do PDF.

Fluxo completo: [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).

Filtro opcional: `GET /transacoes/listar?fatura_id=&cartao_numero_id=` ou `&ultimos_digitos=1234`.

Ao **adicionar compra** nesta tela: select de final via `GET /cartoes/numeros-list?fatura_id=` (só finais da bandeira da fatura) e enviar `cartao_numero_id`.

### Atribuir / corrigir final na edição (obrigatório)

Linhas sem `cartao_numero_id` caem em **Pagamentos e Financiamentos** (compras) ou **Operacionais** (operações) — nunca aninhar Operacionais dentro de Pagamentos e Financiamentos.

1. Na edição da transação (detalhe da fatura **e** tela global de compras), sempre exibir o select **Final do cartão**
2. Opções: `GET /cartoes/numeros-list?fatura_id={id}` (só finais da **bandeira da fatura**)
3. Label sugerido: `•••• 7025 · LEONARDO S FERREIRA` (usar `label` da API)
4. Salvar:

```http
PUT /api/v1/transacoes/editar
```

```json
{
  "id": 123,
  "cartao_numero_id": 10
}
```

5. Após sucesso: a linha muda de grupo; refetch do detalhe (atualiza `grupos_por_cartao`)
6. Parceladas (`compra_grupo_id`): default altera só esta parcela; opção “Aplicar a todas” → `propagar_grupo: true`
7. Atalho no grupo “Sem cartão identificado”: botão “Definir final” na linha

> O backend valida que o final pertence à bandeira da fatura.

### Empty states

- Sem cartões com fatura → CTA para cadastrar compra ou importar PDF
- Cartão sem fatura no filtro de mês/ano → “Nenhuma fatura neste período”

---

## Checklist de aceite

- [ ] Listagem agrupa por cartão/grupo (não lista plana)
- [ ] Listagem: coluna de anexo com ícones PDF/CSV (`tem_pdf` / `tem_csv`); bandeira só como chip se houver > 1
- [ ] Upload aceita apenas PDF e CSV
- [ ] Cadastro: formulário inicial sem obrigatoriedade; sem anexo → cartão/mês/ano obrigatórios
- [ ] Cadastro só com PDF: modal `precisa_confirmar_metadados` (ver `frontend-prompt-cadastro-fatura-metadados.md`)
- [ ] Cadastro: select de bandeira **só** quando o cartão tem finais e mais de uma bandeira
- [ ] Cadastro: com 1 bandeira e finais, envia `cartao_bandeira_id` automaticamente
- [ ] Cartão sem finais + PDF/CSV: modal `precisa_selecionar_bandeira` (select com `bandeiras[]`)
- [ ] Cartão sem finais + CSV sem PDF: modal `precisa_selecionar_final` (`cartao_numero_id` ou `ultimos_digitos`)
- [ ] Transações **não** aparecem na listagem
- [ ] Listagem e detalhe exibem **pago / restante** (`valor_pago`, `valor_restante`); no detalhe o **Total da fatura** é `valor_total_com_pendencias`
- [ ] Detalhe: se `tem_compras_nao_conciliadas`, bloco âmbar com `valor_nao_conciliado` + “Extrato da fatura: {valor_extrato}”; some o bloco ao conciliar tudo
- [ ] Após `POST /transacoes/conciliar` (ou desvincular/rejeitar), refetch do detalhe da fatura **e** da lista de transações
- [ ] Badge “Paga” / “Em aberto” usa o campo `pago` (nunca o `status` do arquivo)
- [ ] Após processar fatura, listagem é refetchada (quitação da anterior atualiza)
- [ ] Detalhe: botões Anterior / Próxima (`fatura_anterior_id` / `fatura_proxima_id`)
- [ ] Detalhe pode mostrar `pagamentos_abatido_anterior` / `pagamentos_antecipado`
- [ ] Detalhe usa `grupos_por_cartao` + lista de transações agrupada por final
- [ ] Grupo “Sem cartão identificado” para transações sem `cartao_numero_id`
- [ ] Edição permite escolher/alterar `cartao_numero_id`
- [ ] Parceladas: opção de propagar o final com `propagar_grupo: true`
- [ ] Cadastro de compra na fatura envia `cartao_numero_id`
- [ ] Cada fatura mostra competência, intervalo e vencimento
- [ ] Chip usa `cor_fundo` + `cor_texto`
- [ ] Ordenação: competência → cartão → status
- [ ] `perPage` = quantidade de **faturas** (resposta agrupada por cartão)
- [ ] Detalhe busca transações só sob demanda (`fatura_id`)
- [ ] Filtros `cartao_id`, `mes`, `ano`, `status` funcionam
- [ ] Upload/processamento de anexo continua acessível a partir da fatura
- [ ] Remover/trocar PDF: ver [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md)
