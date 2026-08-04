# Prompt — Frontend: Faturas (listagem, detalhe e quitação)

Use este prompt no repositório do frontend para alinhar a tela de faturas à API do `controle_fatura_back`.

---

## Objetivo

A tela de faturas deve:

1. **Listar faturas agrupadas por cartão (grupo)** (não uma lista plana misturada)
2. Dentro do grupo, identificar a **bandeira** da fatura (Visa/Master/…) — cada bandeira tem fatura própria
3. **Não exibir transações** na listagem — só resumo da fatura
4. Exibir o **intervalo do ciclo** (início/fim) e a competência, com base no ciclo do **grupo**
5. Mostrar **quitação** em cada fatura: total, pago, restante e se está paga
6. Abrir o **detalhe** (e as transações) só ao clicar em uma fatura
7. No detalhe, repetir o bloco financeiro e **agrupar transações pelo final do cartão** (`ultimos_digitos`)

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
          "tem_pdf": true,
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
- Cada fatura traz `cartao_bandeira_id` + `bandeira`
- Cada fatura traz contadores (`total_transacoes`) — **não** o array de transações
- Cada fatura traz quitação: `pago`, `valor_pago`, `valor_restante`
- Use `cor_fundo` / `cor_texto` no chip do grupo
- Formate datas em `dd/MM/yyyy`
- Mostre a bandeira quando o grupo tiver mais de uma

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
  "valor_total": "307.25",
  "pago": true,
  "valor_pago": 307.25,
  "valor_restante": 0,
  "pagamentos_total": 733.88,
  "pagamentos_abatido_anterior": 257.6,
  "pagamentos_antecipado": 476.28,
  "arquivo_pdf": "faturas/1/....pdf",
  "tem_pdf": true,
  "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/73",
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
| POST | `/cadastrar` | multipart: `cartao_id`, `cartao_bandeira_id`, `mes`, `ano`, `arquivo_pdf?`, `processar_automatico?` |
| PUT | `/editar` | altera período/status/valor |
| DELETE | `/excluir/{id}` | soft-delete fatura + transações |
| POST | `/upload-pdf` | anexa PDF/CSV/XML |
| POST | `/processar/{id}` | reprocessa arquivo |
| GET | `/pdf/{id}` | visualiza arquivo |
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
| `valor_total` | number/string | **Total da fatura** |
| `valor_pago` | number | **Total pago** (quanto da fatura já foi quitado) |
| `valor_restante` | number | **Total restante** (`valor_total - valor_pago`, mínimo 0) |
| `pago` | bool | Badge **Paga** / **Em aberto** (`true` quando restante é 0) |

> `status` (`pendente` / `processando` / `processada` / `erro`) é o **processamento do PDF**, não a quitação. Nunca use `status` para dizer se a fatura está paga.

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
Total da fatura   R$ {valor_total}
Total pago        R$ {valor_pago}
Restante          R$ {valor_restante}
Status            Paga | Em aberto   ← usa `pago`, não `status`
```

Opcional no detalhe (ajuda a ler o extrato):

```
Dos pagamentos desta fatura (R$ {pagamentos_total}):
  · R$ {pagamentos_abatido_anterior} quitou a fatura anterior
  · R$ {pagamentos_antecipado} antecipou este ciclo
```

Formate valores em BRL (`R$ 1.234,56`).

---

## UI sugerida

### Cadastro de fatura — seleção de bandeira

1. Select **Cartão** (grupo) via `cartoes-list` / lookups
2. Buscar bandeiras: `GET /cartoes/bandeiras-list?cartao_id=`
3. Regra:
   - **0 bandeiras** → bloquear cadastro com CTA “Cadastre uma bandeira/número neste cartão”
   - **1 bandeira** → pré-selecionar `cartao_bandeira_id` e **não exibir** o campo
   - **2+ bandeiras** → select obrigatório “Bandeira da fatura”
4. Enviar sempre `cartao_id` + `cartao_bandeira_id` no `POST /cadastrar`

### Tela de listagem

1. Filtros: cartão (grupo), bandeira (opcional), mês, ano, status do PDF, busca
2. Para cada grupo da página:
   - Cabeçalho com chip (`background: cor_fundo; color: cor_texto`), nome, “Fecha dia X · Vence dia Y”
   - Subtotal do grupo (`valor_total` do grupo)
   - Cards/linhas das faturas **sem** expandir compras:
     - Badge da **bandeira** (obrigatório visualmente se o grupo tem mais de uma)
     - Competência (`08/2026`)
     - Período: `06/07/2026 – 05/08/2026`
     - Vencimento
     - **Total / pago / restante** (`valor_total`, `valor_pago`, `valor_restante`)
     - Badge de quitação (`pago` → “Paga” / “Em aberto”)
     - Status do PDF (`status`) — pendente/processada/erro (separado da quitação)
     - Contador “N lançamentos” (`total_transacoes`)
     - Ícone se `tem_pdf`
3. Clique na fatura → tela/drawer de detalhe
4. Ações na linha: upload PDF, processar, excluir, ver PDF

### Tela de detalhe

1. Cabeçalho do grupo + **bandeira** + competência + intervalo + vencimento
2. **Bloco financeiro:** `valor_total` / `valor_pago` / `valor_restante` + badge `pago`
3. Opcional: breakdown `pagamentos_total` / `pagamentos_abatido_anterior` / `pagamentos_antecipado`
4. Status de processamento do PDF (`status`) — não confundir com `pago`
5. Bloco de PDF (preview / reprocessar)
6. **Só aqui** carregar transações via `GET /transacoes/listar?fatura_id=`  
   (a API ordena por `ultimos_digitos` asc → `data` asc quando `fatura_id` é informado)
7. **Agrupar a exibição por final do cartão** — usar `grupos_por_cartao` do detalhe para cabeçalhos/subtotais; linhas vêm de `/transacoes/listar`

```json
"grupos_por_cartao": [
  {
    "cartao_numero_id": 10,
    "ultimos_digitos": "7025",
    "tipo": "fisico",
    "apelido": null,
    "nome_no_cartao": "LEONARDO S FERREIRA",
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
    "label": "•••• 7033 · LEONARDO S FERREIRA",
    "total_transacoes": 6,
    "valor_total": 1081.47
  },
  {
    "cartao_numero_id": null,
    "ultimos_digitos": null,
    "label": "Sem cartão identificado",
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

Sem cartão identificado            subtotal R$ …
  04/08  Estabelecimento  R$ 15,00
```

Cada linha de transação traz `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_tipo`, `cartao_numero_apelido`, `cartao_numero_nome_no_cartao`.

Filtro opcional: `GET /transacoes/listar?fatura_id=&cartao_numero_id=` ou `&ultimos_digitos=1234`.

Ao **adicionar compra** nesta tela: select de final via `GET /cartoes/numeros-list?fatura_id=` (só finais da bandeira da fatura) e enviar `cartao_numero_id`.

### Atribuir / corrigir final na edição (obrigatório)

Linhas sem `cartao_numero_id` caem em **“Sem cartão identificado”**.

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
- [ ] Cada fatura exibe a bandeira (`bandeira` / `cartao_bandeira_id`)
- [ ] Cadastro: select de bandeira **só** quando o cartão tem mais de uma
- [ ] Cadastro: com 1 bandeira, envia `cartao_bandeira_id` automaticamente
- [ ] Transações **não** aparecem na listagem
- [ ] Listagem e detalhe exibem **total / pago / restante** (`valor_total`, `valor_pago`, `valor_restante`)
- [ ] Badge “Paga” / “Em aberto” usa o campo `pago` (nunca o `status` do PDF)
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
- [ ] Upload/processamento de PDF continua acessível a partir da fatura
