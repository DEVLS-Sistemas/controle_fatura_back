# Prompt — Frontend: substituir só a fatura escolhida no form

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: **CTLFAT-22**. Complementa o CTA de cadastrar/substituir ([`frontend-prompt-substituir-fatura-existente.md`](frontend-prompt-substituir-fatura-existente.md)). Não muda o contrato de casar transações (CTLFAT-11) nem o total do PDF (CTLFAT-12).

Base: `POST /api/v1/faturas/cadastrar` (Bearer Sanctum, multipart).

---

## Problema

**Adicionar fatura** a partir do detalhe de uma fatura (ex.: PicPay 738) é um **cadastro novo**. O alvo é o **cartão + bandeira + competência do formulário** (ex.: Sofisa 09/2026), não a fatura que estava aberta.

Caso real: detalhe da PicPay `/faturas/view/738` → Adicionar fatura → Sofisa 09/2026 (já anexada). O retry ia com `fatura_existente_id` da PicPay. A API respondia 422 genérico e o modal não era o da Sofisa.

---

## O que o front faz

1. No primeiro envio de **Adicionar fatura**, **não** mandar `fatura_existente_id` da fatura aberta na tela.
2. Mandar `cartao_id`, `cartao_bandeira_id`, `mes` e `ano` da escolha do form.
3. `fatura_existente_id` só no retry do modal, copiado de `fatura_existente.id` da API.
4. Modal `fatura_ja_anexada`: aviso + **Substituir** + cancelar. O card é `fatura_existente` (a fatura escolhida no form).
5. Retry de substituir: `confirmar_substituir_fatura=true`, `fatura_existente_id` = `fatura_existente.id`, e os mesmos `cartao_id` / `cartao_bandeira_id` / `mes` / `ano` do form (iguais aos de `fatura_existente`).
6. Se a API recusar o substituir porque o arquivo não é o mesmo cartão, bandeira e competência: modal para **cadastrar**. Não repetir `confirmar_substituir_fatura`.

Cancelar fecha o modal e não chama a API. O arquivo permanece no dropzone.

---

## Modal de metadados — bandeira na competência que já existe

Se o 422 for `precisa_confirmar_metadados` e `faturas_periodo` não estiver vazio, o cartão sugerido **já tem** fatura naquele mês. O select de **bandeira** é obrigatório (`precisa_selecionar_bandeira: true`).

- Mostrar `bandeiras[]` inteira. Pré-selecionar `sugestao.cartao_bandeira_id`.
- `fatura_existente` é a fatura **da bandeira sugerida**, não “qualquer uma” do mês. `faturas_periodo` lista as outras (cada uma com `cartao_bandeira_id` e `bandeira`).
- Trocar a bandeira para uma que não é a de `fatura_existente`: botão **Cadastrar**. Retry com `cartao_id` + `cartao_bandeira_id` (ou `bandeira` se `criar: true`) + `mes`/`ano`. Sem `fatura_existente_id` e sem `confirmar_substituir_fatura`.
- Manter a bandeira da fatura que já tem anexo: botão **Substituir**, como na seção abaixo.

Visa e Mastercard do mesmo cartão no mesmo mês são faturas diferentes. O modal não pode sugerir só o cartão.

---

## 422 `fatura_ja_anexada`

Competência escolhida **já tem anexo**. O `fatura_existente.id` é o da fatura do form (Sofisa), nunca o da tela de origem (PicPay).

```json
{
  "error": true,
  "codigo": "fatura_ja_anexada",
  "fatura_ja_anexada": true,
  "acao_sugerida": "substituir",
  "fatura_existente_id": 777,
  "fatura_existente": {
    "id": 777,
    "cartao_id": 0,
    "cartao_nome": "Sofisa",
    "mes": 9,
    "ano": 2026,
    "competencia": "09/2026",
    "tem_anexo": true
  }
}
```

| Campo | Uso |
|-------|-----|
| `codigo === "fatura_ja_anexada"` | Abrir modal (não toast) |
| `fatura_existente` | Card da fatura **escolhida** |
| `fatura_existente.id` | Único `fatura_existente_id` do retry |
| `acao_sugerida === "substituir"` | Botão **Substituir** |

Não usar o id da rota (`/faturas/view/738`) nesse campo.

### Retry — Substituir

```http
POST /api/v1/faturas/cadastrar
Content-Type: multipart/form-data
```

| Campo | Valor |
|-------|--------|
| `arquivo_pdf` | arquivo novo |
| `confirmar_substituir_fatura` | `true` |
| `fatura_existente_id` | `fatura_existente.id` (777) |
| `cartao_id` | o do form |
| `cartao_bandeira_id` | o do form |
| `mes` / `ano` | os do form |

**200** com `data.id` igual a `fatura_existente.id`. Poll e refetch nesse id. Não navegar para a fatura da tela de origem.

---

## 422 `arquivo_diverge_alvo`

O substituir só grava por cima se o arquivo for válido **e** bater cartão, bandeira e competência do alvo. Se não bater, a API **não** altera a fatura alvo.

```json
{
  "error": true,
  "codigo": "arquivo_diverge_alvo",
  "arquivo_diverge_alvo": true,
  "acao_sugerida": "cadastrar",
  "message": "Este arquivo não é do mesmo cartão, bandeira e competência. Confirme para cadastrar em vez de substituir.",
  "fatura_existente_id": 777,
  "fatura_existente": { "id": 777, "tem_anexo": true },
  "sugestao": {
    "mes": 8,
    "ano": 2026,
    "parser": "picpay",
    "bandeira_sugerida": null,
    "cartao_nome_sugerido": "PicPay"
  }
}
```

Nesse 422:

- Abrir modal de **cadastrar** (não o de Substituir). A fatura em `fatura_existente` não foi alterada.
- Não reenviar `confirmar_substituir_fatura=true`.
- Não reenviar o `fatura_existente_id` do alvo recusado.
- O cadastro seguinte usa a `sugestao` (cartão/mês do arquivo), não a competência escolhida que já tem anexo.
- Sem essa confirmação, não cadastra em silêncio.

---

## Critérios

- [ ] Na PicPay, adicionar Sofisa já anexada: modal da Sofisa, não da PicPay
- [ ] Primeiro POST sem `fatura_existente_id` da fatura aberta
- [ ] Botão Substituir só nesse aviso; o retry substitui a Sofisa (`data.id` da Sofisa)
- [ ] Arquivo de outro cartão ou outro mês: 422 `arquivo_diverge_alvo` (`acao_sugerida: cadastrar`); a fatura escolhida não muda
- [ ] Cancelar não chama a API
