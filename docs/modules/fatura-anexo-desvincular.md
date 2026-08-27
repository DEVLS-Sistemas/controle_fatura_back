# Especificação — Remover / trocar anexo da fatura

Prompt do front (mesmas etapas): [`docs/frontend-prompt-remover-pdf-fatura.md`](../frontend-prompt-remover-pdf-fatura.md).

Complementa [`faturas.md`](faturas.md) e [`transacoes.md`](transacoes.md).

Implementar **uma etapa por vez**, no mesmo número que o front.

| Etapa | Tema | Back | Front |
|-------|------|------|-------|
| **1** | Rastreio + preview | `fatura_origem_id`, `criada_como_manual`, `GET /impacto-remover-anexo/{id}`, flag `pode_remover_anexo` | Botão Remover PDF + modal de motivo + lista de impacto (somente leitura) |
| **2** | Remover anexo | `POST /remover-anexo` (`motivo=remover`) | Confirmar remoção + tela/modal das compras que voltaram a precisar conciliar |
| **3** | Trocar PDF | `POST /remover-anexo` (`motivo=trocar_pdf`) + upload do novo arquivo | File picker + preview do PDF novo + processar |
| **4** | Reconciliar na fatura certa | Payload pós-troca com `compras_para_conciliar` (reusa `POST /transacoes/conciliar`) | Modal para conciliar as compras da fatura errada na fatura correta |

---

## Por que o campo não fica em `faturas`

Uma fatura com PDF gera **N transações** em **N competências** (parcelas anteriores e futuras). Um FK na tabela `faturas` não rastreia cada linha.

O apontamento fica em **`transacoes.fatura_origem_id`**: qual fatura (cujo PDF/CSV foi processado) **criou** aquela transação.

| Linha | `fatura_id` | `fatura_origem_id` |
|-------|-------------|--------------------|
| Lançamento importado do PDF de ago/2026 | fatura ago | fatura ago |
| Parcela 6/10 materializada em set/2026 (stub, sem PDF próprio) | fatura set | fatura ago |
| Mesma parcela depois que o PDF de set/2026 foi processado e casou | fatura set | fatura set (a fatura nova **assume** a linha) |
| Compra cadastrada pelo usuário | fatura da competência | `null` |

Assim, ao desfazer o PDF de ago, apagamos só o que **ainda pertence** a ago (`fatura_origem_id = ago`). Parcelas já confirmadas pelo PDF de set permanecem.

`criada_como_manual` nunca muda depois do create: permite restaurar a identidade da compra se o PDF tiver “engolido” a linha no match exato (`compra_manual` vira `false`, `importada_pdf` vira `true`).

---

## Campos novos (`transacoes`)

| Campo | Tipo | Obs |
|-------|------|-----|
| `fatura_origem_id` | FK nullable → `faturas` | Fatura cujo processamento gerou a linha. `nullOnDelete`. |
| `criada_como_manual` | boolean default false | `true` só no `POST /transacoes/cadastrar` (e parcelas materializadas a partir de uma compra manual). **Não** é limpo no match exato do PDF. |

---

# Etapa 1 — Rastreio + preview de impacto

## Popular os campos

- Import PDF (`ProcessInvoicePdfJob`): linhas criadas/atualizadas pelo arquivo recebem `fatura_origem_id = fatura atual` e `criada_como_manual = false` (exceto se a linha já era manual — aí `criada_como_manual` permanece `true`).
- `materializarParcelasFuturas`: stubs novos recebem `fatura_origem_id = fatura da linha-fonte` e copiam `criada_como_manual` da fonte.
- Create manual: `criada_como_manual = true`, `fatura_origem_id = null`.
- Backfill na migration: `criada_como_manual = compra_manual`; `importada_pdf=true` → `fatura_origem_id = fatura_id`; stubs do mesmo `compra_grupo_id` herdam a fatura da primeira linha importada do grupo.

## Flag no detalhe / listagem / resposta de processamento

| Campo | Tipo | Uso |
|-------|------|-----|
| `pode_remover_anexo` | bool | `true` se tem PDF ou CSV **e** `status !== processando` |

## Preview (somente leitura)

```http
GET /api/v1/faturas/impacto-remover-anexo/{id}
Authorization: Bearer {token}
```

404 se a fatura não for do usuário.  
422 se não houver anexo (`tem_pdf` e `tem_csv` falsos) ou se `status = processando`.

### Sucesso (200)

```json
{
  "status": true,
  "message": "Impacto da remoção do anexo",
  "data": {
    "fatura_id": 73,
    "competencia": "08/2026",
    "cartao_nome": "Sofisa",
    "bandeira": "Mastercard",
    "tem_pdf": true,
    "tem_csv": false,
    "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/73",
    "pode_remover": true,
    "motivos": [
      { "value": "remover", "label": "Apenas remover o PDF" },
      { "value": "trocar_pdf", "label": "PDF incorreto — quero trocar" }
    ],
    "lancamentos_deste_anexo": {
      "quantidade": 42,
      "valor_total": 3565.87
    },
    "parcelas_geradas_outras_faturas": {
      "quantidade": 18,
      "valor_total": 2140.50,
      "faturas_afetadas": [
        {
          "id": 70,
          "competencia": "05/2026",
          "quantidade": 3,
          "valor_total": 450.00,
          "ficara_vazia": false
        },
        {
          "id": 74,
          "competencia": "09/2026",
          "quantidade": 5,
          "valor_total": 890.00,
          "ficara_vazia": true
        }
      ]
    },
    "compras_que_voltam_a_conciliar": [
      {
        "id": 901,
        "texto_compra": "Mouse Logitech",
        "valor": 249.90,
        "data": "2026-08-23",
        "parcela_atual": 1,
        "parcelas_total": 1,
        "fatura_id": 73,
        "competencia": "08/2026",
        "status_conciliacao_atual": "conciliada",
        "status_conciliacao_depois": "nao_conciliada",
        "origem_restauracao": "desvinculo"
      }
    ],
    "faturas_stub_que_serao_excluidas": [
      { "id": 74, "competencia": "09/2026" }
    ],
    "avisos": [
      "42 lançamentos importados deste PDF serão apagados nesta fatura.",
      "18 parcelas automáticas em faturas anteriores/futuras serão apagadas.",
      "A fatura 09/2026 ficará vazia e será removida.",
      "1 compra manual volta a precisar de conciliação."
    ]
  }
}
```

### `origem_restauracao`

| Valor | Significado |
|-------|-------------|
| `desvinculo` | Compra manual em linha própria (`lancamento_id` apontava para o PDF). A linha manual permanece; só desfaz a conciliação. |
| `match_exato` | O PDF tinha mesclado a compra na linha importada (`criada_como_manual=true` + `importada_pdf=true`). A linha volta a ser compra manual aberta. |
| `sugestao` | Estava `pendente` (sugestão). Volta para `nao_conciliada`. |

### O que **não** entra no impacto

- Parcelas de outras faturas com `fatura_origem_id` **diferente** (já confirmadas pelo PDF daquela competência).
- Compras manuais que **nunca** foram conciliadas com este anexo (continuam como estão).
- Faturas vizinhas que têm PDF/CSV próprio ou outras transações (não são excluídas, só perdem as parcelas geradas por este anexo).

---

# Etapa 2 — Remover anexo de verdade

```http
POST /api/v1/faturas/remover-anexo
Authorization: Bearer {token}
Content-Type: application/json
```

```json
{
  "id": 73,
  "motivo": "remover",
  "tipo": "pdf"
}
```

| Campo | Obrigatório | Obs |
|-------|-------------|-----|
| `id` | sim | Fatura |
| `motivo` | sim | `remover` nesta etapa. `trocar_pdf` entra na etapa 3 |
| `tipo` | não | `pdf` (default se `tem_pdf`), `csv`, `ambos` |

422 se `motivo` inválido, sem anexo, ou `status=processando`.

### Efeito (transação DB)

1. Desfaz conciliações das compras listadas no preview (`status_conciliacao=nao_conciliada`, `lancamento_id=null`, `ignorar_no_total=false`). Histórico: `desvinculada` (motivo: anexo removido).
2. Linhas `match_exato`: restaura `compra_manual=true`, `importada_pdf=false`, `fatura_origem_id=null`, limpa `estabelecimento_id` se tinha vindo só do PDF, **preserva** `observacoes` / categoria / responsável / anexos da compra.
3. Soft-delete dos lançamentos deste anexo **nesta** fatura (`fatura_origem_id = id` e `criada_como_manual=false`).
4. Soft-delete das parcelas geradas em **outras** faturas (`fatura_origem_id = id` e `fatura_id != id` e `criada_como_manual=false`).
5. Soft-delete de faturas stub que ficarem sem transações, sem PDF, sem CSV e `status=pendente`.
6. Apaga o arquivo no storage; zera `arquivo_pdf` e/ou `arquivo_csv`.
7. Fatura alvo: `status=pendente`, `processado_em=null`, `erro_*=null`. Recalcula `valor_total` desta e das vizinhas afetadas.

A fatura **não** é excluída — só perde o anexo e os lançamentos gerados por ele. Compras manuais da competência continuam nela.

### Sucesso (200)

Mesmo envelope do preview + resultado:

```json
{
  "status": true,
  "message": "Anexo removido. 1 compra voltou a precisar de conciliação.",
  "data": {
    "fatura_id": 73,
    "motivo": "remover",
    "anexo_removido": true,
    "tem_pdf": false,
    "tem_csv": false,
    "lancamentos_apagados": 42,
    "parcelas_apagadas_outras_faturas": 18,
    "faturas_stub_excluidas": [74],
    "compras_que_voltaram_a_conciliar": [ { "id": 901, "texto_compra": "Mouse Logitech", "...": "..." } ],
    "avisos": ["..."]
  }
}
```

`DELETE /faturas/excluir/{id}` passa a usar a **mesma reversão** antes de apagar a fatura (parcelas geradas em vizinhas não ficam órfãs).

---

# Etapa 3 — Trocar PDF (arquivo incorreto)

Mesmo `POST /remover-anexo`, com `motivo=trocar_pdf`.

Dois jeitos (o front usa o **B**):

### A) Dois requests

1. `POST /remover-anexo` `{ "id", "motivo": "trocar_pdf" }` — igual à etapa 2 (limpa o errado, devolve compras restauradas).
2. `POST /upload-pdf` com o arquivo novo (`processar_automatico=true`).

### B) Um request (preferido)

```http
POST /api/v1/faturas/remover-anexo
Content-Type: multipart/form-data
```

| Campo | Obs |
|-------|-----|
| `id` | Fatura |
| `motivo` | `trocar_pdf` |
| `arquivo_pdf` | **obrigatório** quando `motivo=trocar_pdf` neste modo |
| `processar_automatico` | default `true` |
| `senha_pdf` / `salvar_senha_pdf` | iguais ao upload atual |

Fluxo interno: reversão da etapa 2 → `attachPdfToFatura` → dispara `ProcessInvoicePdfJob`.

422 se `motivo=trocar_pdf` sem arquivo.

Preview do PDF **novo** é 100% front (`URL.createObjectURL`); o back não tem endpoint de preview.

### Sucesso (200) — processamento assíncrono

```json
{
  "status": true,
  "message": "PDF substituído. A fatura está sendo processada.",
  "data": {
    "fatura_id": 73,
    "motivo": "trocar_pdf",
    "anexo_removido": true,
    "tem_pdf": true,
    "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/73",
    "status": "pendente",
    "compras_que_voltaram_a_conciliar": [ { "id": 901, "...": "..." } ],
    "aguardando_processamento": true
  }
}
```

O front faz poll em `GET /faturas/listar/{id}` até `status=processada` (ou `erro`) e então abre a etapa 4.

Senha: mesmos 422 `precisa_senha_pdf` do upload atual.

---

# Etapa 4 — Conciliar compras na fatura correta

Não há entidade nova. Depois do PDF certo processar:

```http
GET /api/v1/faturas/compras-para-reconcilia/{id}
```

(alias aceito: `compras-para-reconcilia` / query `GET /impacto-remover-anexo/{id}` já devolveu a lista; este GET é o recorte **depois** do novo PDF.)

```json
{
  "status": true,
  "data": {
    "fatura_id": 73,
    "status": "processada",
    "compras_para_conciliar": [
      {
        "id": 901,
        "texto_compra": "Mouse Logitech",
        "valor": 249.90,
        "data": "2026-08-23",
        "precisa_conciliar": true,
        "candidatos": [
          {
            "id": 1204,
            "estabelecimento": "PAG*LOJA XYZ",
            "valor": 249.90,
            "data": "2026-08-23",
            "score": 0.92,
            "sugestao": true
          }
        ]
      }
    ]
  }
}
```

`candidatos` reusa `ConciliacaoService` / `GET /transacoes/candidatos-conciliacao/{id}`.

Confirmar:

```http
POST /api/v1/transacoes/conciliar
{ "compra_id": 901, "lancamento_id": 1204 }
```

Se o match exato do job já conciliou sozinho, a compra **não** entra em `compras_para_conciliar`.

---

## Regras de negócio (todas as etapas)

1. Não apagar parcela de vizinha se `fatura_origem_id` já for a vizinha (PDF dela assumiu a linha).
2. Não apagar compra `criada_como_manual` — restaurar.
3. Stub vazio (sem anexo, `pendente`, 0 transações restantes) → soft-delete.
4. Recalcular `valor_total` de todas as faturas tocadas.
5. Isolamento por `user_id`.
6. `status=processando` bloqueia remover/trocar.

---

## Rotas novas (`/api/v1/faturas`)

| Etapa | Método | Rota |
|-------|--------|------|
| 1 | GET | `/impacto-remover-anexo/{id}` |
| 2–3 | POST | `/remover-anexo` |
| 4 | GET | `/compras-para-reconcilia/{id}` |
