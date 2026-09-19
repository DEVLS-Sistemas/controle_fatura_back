# Prompt — Frontend: CTA único — cadastrar ou substituir fatura

Use este prompt no repositório do **frontend**. Backend **já implementado** neste card (CTLFAT-10). Complementa [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md) e [`frontend-prompt-fatura-anexo-duplicado.md`](frontend-prompt-fatura-anexo-duplicado.md).

Reprocessar transações do PDF novo: **CTLFAT-11** — fora daqui. PDF anexado no cartão errado: [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md).

---

## Problema

No cadastro/upload de PDF não faz sentido mostrar **Substituir anexo existente** e **Cadastrar fatura** ao mesmo tempo — o cadastrar não faz nada. Caso típico: o usuário anexou a fatura ainda **aberta** e depois manda o PDF **fechado** do mesmo mês.

| Situação | CTA |
|----------|-----|
| Competência **sem** fatura, ou stub **sem** anexo | só **Cadastrar fatura** |
| Competência já tem fatura **com** anexo | só **Substituir fatura** |
| Mesmo arquivo (mesmo hash) já gravado em **outra** fatura | modal `anexo_duplicado` — não este prompt |
| Outra pessoa no mesmo cartão/mês | `precisa_cartao_do_titular` — não este prompt |

Os dois botões **nunca** aparecem juntos. “Cadastrar” some de vez quando já tem anexo (nem desabilitado). “Substituir” some quando é stub / competência vazia.

Label: **Substituir fatura** (não “substituir anexo existente”).

---

## Regra de CTA

Leia `acao_sugerida` e `fatura_existente` no 422 `precisa_confirmar_metadados` **e** no 422 `fatura_ja_anexada`.

```
acao_sugerida === "substituir"  →  botão primário: Substituir fatura
acao_sugerida === "cadastrar"   →  botão primário: Cadastrar fatura
                                   (modo cadastrar_cartao: "Cadastrar cartão e fatura")
```

Fallback se a API antiga não mandar `acao_sugerida`:

- `fatura_existente.tem_anexo === true` (ou `tem_pdf` / `tem_csv`) → **Substituir fatura**
- senão → **Cadastrar fatura**

No modal de metadados **e** no modal de fatura já anexada: **um botão só**.

Cancelar **não** chama API; o arquivo fica no dropzone.

---

## Ordem dos 422 (atualizada)

```
1. precisa_senha_pdf
2. precisa_confirmar_metadados   ← CTA único (cadastrar | substituir)
3. precisa_confirmar_titular
4. precisa_cartao_do_titular     ← outra pessoa, PDF diferente, mesmo cartão/mês
5. anexo_duplicado               ← mesmo conteúdo (hash) já anexado em outra fatura
6. fatura_ja_anexada             ← ESTE PROMPT (outro arquivo, competência já tem anexo)
7. precisa_selecionar_bandeira / precisa_selecionar_final
8. 200 sucesso
```

`fatura_ja_anexada` **não** dispara no stub sem anexo. **Não** dispara se o hash for o da **própria** fatura (reprocesso). Se o hash for de **outra** fatura, vale `anexo_duplicado`.

---

## API 422 fatura_ja_anexada

Dispara em `POST /api/v1/faturas/cadastrar` e `POST /api/v1/faturas/upload-pdf` quando a competência já tem fatura **com** anexo, o arquivo é **outro** (hash diferente) e o request ainda **não** confirmou.

```http
422 Unprocessable Entity
```

```json
{
  "error": true,
  "message": "Já existe uma fatura com anexo nesta competência. Confirme para substituir a fatura.",
  "codigo": "fatura_ja_anexada",
  "fatura_ja_anexada": true,
  "acao_sugerida": "substituir",
  "fatura_existente_id": 591,
  "orientacao": "Já existe fatura com anexo nesta competência: Nubank 08/2026 (Leonardo). Substituir fatura usa este arquivo na mesma linha (não cria outra). Cancelar mantém o anexo atual.",
  "fatura_existente": {
    "id": 591,
    "cartao_id": 33,
    "cartao_nome": "Nubank",
    "bandeira": "Mastercard",
    "pessoa_id": 6,
    "pessoa_nome": "Leonardo Silva",
    "mes": 8,
    "ano": 2026,
    "competencia": "08/2026",
    "periodo_inicio": "2026-07-06",
    "periodo_fim": "2026-08-05",
    "data_vencimento": "2026-08-10",
    "valor_total": "1250.40",
    "status": "processada",
    "total_transacoes": 34,
    "tem_anexo": true,
    "tem_pdf": true,
    "tem_csv": false,
    "pdf_url": "http://host/api/v1/faturas/pdf/591",
    "processado_em": "2026-08-27T20:42:25.000000Z",
    "created_at": "2026-08-26 11:57:57"
  }
}
```

| Campo | Uso |
|-------|-----|
| `codigo === "fatura_ja_anexada"` **ou** `fatura_ja_anexada === true` | Abrir **modal** (não toast) |
| `message` / `orientacao` | Título e texto |
| `fatura_existente` | Card da fatura que já tem anexo — obrigatório na UI |
| `fatura_existente.id` | Retry + `fatura_existente_id` |
| `fatura_existente.tem_anexo` | Sempre `true` neste 422 |
| `acao_sugerida` | Sempre `substituir` neste 422 |

Se `fatura_existente.status === "processando"`: não oferecer Substituir; aviso “aguarde o processamento terminar”.

### Retry — Substituir fatura

Mesmo multipart (arquivo + cartão/mês/ano já confirmados). **Não cria outra linha.**

```http
POST /api/v1/faturas/cadastrar
Content-Type: multipart/form-data
```

| Campo | Valor |
|-------|--------|
| `arquivo_pdf` | o arquivo novo |
| `confirmar_substituir_fatura` | `true` |
| `fatura_existente_id` | `fatura_existente.id` |
| `cartao_id` / `mes` / `ano` | os já confirmados |
| `senha_pdf` | se já desbloqueou |

Alternativa na linha: `POST /upload-pdf` com `id` = `fatura_existente.id` (ou só `fatura_existente_id`) + `confirmar_substituir_fatura=true` + arquivo.

Resposta **200** com `data.id` **igual** ao `fatura_existente.id`. Refetch/poll **nesse** id. Não abrir outra fatura.

Cancelar: fecha o modal, **não** chama API, arquivo permanece no dropzone.

---

## Metadados (`precisa_confirmar_metadados`)

O 422 de metadados agora devolve a fatura do período **com ou sem** anexo (antes `fatura_existente_id` só saía para stub sem PDF).

Campos novos (raiz **e** `sugestao`):

| Campo | Tipo | Uso |
|-------|------|-----|
| `acao_sugerida` | `"cadastrar"` \| `"substituir"` | CTA único |
| `fatura_existente` | object \| null | card se já existe linha no período |
| `fatura_existente_id` | int \| null | id da linha (com **ou** sem anexo) |

`fatura_existente` traz `id`, `tem_anexo`, `tem_pdf`, `tem_csv`, `status`, `total_transacoes`, `valor_total`, `competencia`, cartão (`cartao_nome`) e titular (`pessoa_nome`).

### CTA no modal de metadados

- Stub / sem anexo (`acao_sugerida=cadastrar`): botão **Cadastrar fatura**. Retry **sem** `confirmar_substituir_fatura`. O back anexa na linha existente e devolve **200** (não dispara `fatura_ja_anexada`).
- Com anexo (`acao_sugerida=substituir`): botão **Substituir fatura**. Retry com `confirmar_substituir_fatura=true` + `fatura_existente_id` + arquivo + cartão/mês/ano. Se o front ainda não mandar a flag, o back devolve `fatura_ja_anexada` e abre o modal deste prompt — **não** cadastre em paralelo.

Se o PDF identificar um único cartão + competência e o stub **não** tiver anexo, o `POST /cadastrar` só com o arquivo continua **200** (sem modal).

---

## Fluxo

```
[PDF do mês que já tem fatura aberta]
        │
        ▼
POST /cadastrar  ou  POST /upload-pdf
        │
        ├─ stub sem anexo ──────────────► 200 mesma linha (Cadastrar)
        │
        ├─ outro hash, tem anexo, sem flag
        │         │
        │         ▼
        │   422 fatura_ja_anexada ──► modal (card da existente)
        │         │
        │         ├─ Substituir fatura
        │         │     retry flag + fatura_existente_id
        │         │            │
        │         │            ▼
        │         │      200 mesmo id
        │         │
        │         └─ Cancelar → dropzone intacto
        │
        └─ mesmo hash de outra fatura ──► 422 anexo_duplicado
```

---

## Anti-padrões (não fazer)

- ❌ Mostrar **Cadastrar fatura** e **Substituir fatura** juntos
- ❌ Deixar Cadastrar desabilitado “para não fazer nada” — o botão não deve existir
- ❌ Label “Substituir anexo existente”
- ❌ Toast para `fatura_ja_anexada` (é modal, com o card da existente)
- ❌ Tratar como `anexo_duplicado` (isso é o **mesmo** arquivo) ou como `precisa_cartao_do_titular` (outra pessoa)
- ❌ Criar outra fatura no retry; o `data.id` tem que ser o da existente
- ❌ Chamar `POST /remover-anexo` para este caso — a flag basta
- ❌ Poll/refetch das **transações** esperando recálculo (CTLFAT-11). Refetch da **listagem/detalhe** da mesma fatura (anexo novo) pode sim
- ❌ Apagar o arquivo do dropzone no Cancelar

---

## Checklist CTA

- [ ] Os dois botões **nunca** aparecem juntos (modal de metadados **e** modal `fatura_ja_anexada`)
- [ ] Cadastrar some quando `acao_sugerida=substituir` / `tem_anexo=true` (nem desabilitado)
- [ ] Substituir some quando é stub / competência vazia / `acao_sugerida=cadastrar`
- [ ] Label **Substituir fatura** (nunca “substituir anexo existente”)
- [ ] `codigo === "fatura_ja_anexada"` abre modal com o card da existente (não toast)
- [ ] Retry `confirmar_substituir_fatura=true` + `fatura_existente_id` + arquivo → **200** com o **mesmo** `data.id`
- [ ] Stub sem anexo: cadastro anexa sem esse 422; CTA é Cadastrar fatura
- [ ] Mesmo hash continua no fluxo `anexo_duplicado`
- [ ] Outro titular continua em `precisa_cartao_do_titular`
- [ ] Cancelar não chama API; arquivo fica no dropzone
- [ ] `status=processando`: Substituir indisponível
- [ ] Sem poll de transações neste card (CTLFAT-11)
