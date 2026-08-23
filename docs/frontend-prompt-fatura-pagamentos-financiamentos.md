# Prompt — Frontend: Pagamentos e Financiamentos no detalhe da fatura

Use este prompt no repositório do frontend para alinhar o **detalhe da fatura** à API do `controle_fatura_back`. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

---

## Objetivo

No detalhe da fatura (`/faturas/view/:id`):

1. Transações **sem final de cartão** (`cartao_numero_id` / `ultimos_digitos` nulos) entram no grupo **Pagamentos e Financiamentos** — não usar mais o rótulo “Sem cartão identificado”.
2. Esse grupo fica **depois dos cartões** (•••• 9627, •••• 0708…) e **acima da lista de operações** da seção: primeiro as compras do grupo, depois as operacionais.
3. Nome de pessoa na lista (ex.: `Thaís Araújo da Silva`) é **estabelecimento** (`tipo = purchase`). Máquina de cartão / Pix no crédito usam o nome próprio — tratar como compra, não como “não é loja”.
4. `Saldo restante da fatura anterior` é **operação** (`tipo = carryover`, label **Saldo anterior**), não compra.

---

## Por que existe o grupo

No PDF (Nubank e similares) a seção **Pagamentos e Financiamentos** junta lançamentos **sem máscara do cartão** (•••• 1234):

- pagamentos da fatura
- saldo restante da fatura anterior
- Pix / boleto no crédito / maquininha no nome da pessoa

O backend não inventa o final do cartão nessas linhas. O front agrupa pelo que a API já manda.

---

## API

### Detalhe da fatura

```http
GET /api/v1/faturas/listar/{id}
```

`grupos_por_cartao[]` agora traz `grupo_chave` + `label`:

```json
"grupos_por_cartao": [
  {
    "cartao_numero_id": 10,
    "ultimos_digitos": "9627",
    "tipo": "fisico",
    "apelido": null,
    "nome_no_cartao": "MAYSA A CONCEICAO",
    "grupo_chave": "cartao",
    "label": "•••• 9627 · MAYSA A CONCEICAO",
    "total_transacoes": 14,
    "valor_total": 1396.18
  },
  {
    "cartao_numero_id": null,
    "ultimos_digitos": null,
    "tipo": null,
    "apelido": null,
    "nome_no_cartao": null,
    "grupo_chave": "pagamentos_financiamentos",
    "label": "Pagamentos e Financiamentos",
    "total_transacoes": 9,
    "valor_total": 1648.96
  }
]
```

| Campo | Uso |
|-------|-----|
| `grupo_chave` | `"cartao"` ou `"pagamentos_financiamentos"` — chave estável (não comparar o `label`) |
| `label` | Título do grupo na UI |
| `cartao_numero_id` / `ultimos_digitos` | `null` só no grupo Pagamentos e Financiamentos |

Fallback se a API ainda não mandar `grupo_chave`: `ultimos_digitos == null` → tratar como `pagamentos_financiamentos` e usar o título **Pagamentos e Financiamentos**.

### Transações da fatura

```http
GET /api/v1/transacoes/listar?fatura_id={id}
```

Cada linha traz:

| Campo | Uso |
|-------|-----|
| `tipo` | `purchase` \| `payment` \| `refund` \| `advance` \| `fee` \| **`carryover`** |
| `tipo_label` | Compra, Pagamento, Estorno, Antecipação, Encargo, **Saldo anterior** |
| `operacional` | `true` se não for compra (`purchase`) |
| `grupo_chave` | `"cartao"` ou `"pagamentos_financiamentos"` |
| `estabelecimento` | Nome exibido. Em `purchase` é o estabelecimento (inclui nome próprio). Em operação é o texto do lançamento |
| `cartao_numero_id` / `ultimos_digitos` | `null` no grupo Pagamentos e Financiamentos |

Lookups (`GET /transacoes/lookups`): cada item de `tipos[]` tem `operacional: bool`.

---

## UI do detalhe

Ordem:

```
•••• 9627 · MAYSA …          subtotal
  Compras
    14/07  Atacadao *Super …

•••• 0708 · …                subtotal
  Compras
    01/08  Ifd*Igi Pizzaria …

Pagamentos e Financiamentos  subtotal   ← grupo novo (não “Sem cartão identificado”)
  Compras
    22/07  Thaís Araújo da Silva     R$ 52,96
    29/07  Guilherme Oliveira …      R$ 9,71
    05/08  INGRID MARIA TORRES …     R$ 5,95
  Operacionais                       ← abaixo das compras deste grupo
    15/07  Pagamento em 15 JUL       − R$ 217,99
    04/08  Pagamento em 04 AGO       − R$ 51,00
    21/07  Saldo restante da fatura anterior   R$ 0,00
    28/07  Estorno de Uber - NuPay   − R$ 8,20
```

Regras:

1. Percorrer `grupos_por_cartao` na ordem da API (finais primeiro; Pagamentos e Financiamentos por último).
2. Em **todo** grupo (cartão e Pagamentos e Financiamentos):
   - **Compras:** `tipo === 'purchase'` (ou `operacional === false`)
   - **Operacionais:** `operacional === true` (`payment`, `refund`, `advance`, `fee`, `carryover`)
3. **Compras acima de Operacionais** dentro do grupo.
4. Se um dos dois blocos estiver vazio, ocultar o subtítulo (não mostrar “Operacionais” vazio).
5. Não esconder `purchase` porque o nome “parece pessoa”. É estabelecimento.
6. `carryover` / “Saldo restante da fatura anterior”: só em Operacionais; badge `tipo_label` = **Saldo anterior**.
7. Não exigir `cartao_numero_id` para editar linhas deste grupo. Select de final continua opcional (corrigir se o usuário souber o cartão).

---

## Checklist

- [ ] Sumiu o rótulo “Sem cartão identificado”
- [ ] Grupo **Pagamentos e Financiamentos** depois dos finais de cartão
- [ ] Dentro do grupo: Compras (nomes próprios / Pix) **acima** de Operacionais
- [ ] `Thaís Araújo da Silva` (e equivalentes) aparece em **Compras** como estabelecimento
- [ ] `Saldo restante da fatura anterior` aparece em **Operacionais** (`carryover`)
- [ ] Pagamentos da fatura no mesmo bloco Operacionais desse grupo
- [ ] Lookups: `tipos[].operacional` e `tipo_label` nas linhas
