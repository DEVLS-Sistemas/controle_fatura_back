# Prompt — Frontend: Pagamentos e Financiamentos no detalhe da fatura

Use este prompt no repositório do frontend para alinhar o **detalhe da fatura** à API do `controle_fatura_back`. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

---

## Objetivo

Corrigir o detalhe (`/faturas/view/:id`):

1. **Operacionais continua como já era** — seção irmã, **não** fica dentro de outra seção.
2. **Pagamentos e Financiamentos** é uma **seção própria**, entre os cartões e Operacionais.
3. Nome de pessoa na lista (ex.: `Thaís Araújo da Silva`) é **estabelecimento** (`tipo = purchase`).
4. `Saldo restante da fatura anterior` é **operação** (`tipo = carryover`) e fica em **Operacionais**.

### Errado (o que está na tela hoje)

```
Pagamentos e Financiamentos
  └── Operacionais          ← NÃO. Operacionais não é filha desta seção
        └── pagamentos, saldo anterior…
  └── compras / nomes
```

### Certo

```
•••• 9627 · …
  Compras
  Operacionais              ← como já era, dentro do cartão

•••• 0708 · …
  Compras
  Operacionais              ← como já era, dentro do cartão

Pagamentos e Financiamentos ← seção NOVA, só compras sem final (Pix / nome próprio)
  22/07  Thaís Araújo da Silva
  29/07  Guilherme Oliveira …
  05/08  INGRID MARIA TORRES …

Operacionais                ← seção Irmã, DEPOIS de Pagamentos e Financiamentos
  15/07  Pagamento em 15 JUL
  04/08  Pagamento em 04 AGO
  21/07  Saldo restante da fatura anterior
  28/07  Estorno de Uber - NuPay
```

Não aninhar Operacionais dentro de Pagamentos e Financiamentos. São dois blocos no mesmo nível (depois dos grupos `••••`).

---

## API

### Detalhe da fatura

```http
GET /api/v1/faturas/listar/{id}
```

`grupos_por_cartao[]`:

- `grupo_chave = "cartao"` → um item por final (`•••• 9627`…)
- `grupo_chave = "pagamentos_financiamentos"` → **só compras sem final** (subtotal só dessas linhas). **Não inclui** pagamentos nem saldo anterior.

```json
"grupos_por_cartao": [
  {
    "cartao_numero_id": 10,
    "ultimos_digitos": "9627",
    "grupo_chave": "cartao",
    "label": "•••• 9627 · MAYSA A CONCEICAO",
    "total_transacoes": 14,
    "valor_total": 1396.18
  },
  {
    "cartao_numero_id": null,
    "ultimos_digitos": null,
    "grupo_chave": "pagamentos_financiamentos",
    "label": "Pagamentos e Financiamentos",
    "total_transacoes": 3,
    "valor_total": 68.62
  }
]
```

Não existe grupo `operacionais` em `grupos_por_cartao`. Operacionais sai da **lista de transações**.

Fallback: `ultimos_digitos == null` + `grupo_chave` ausente → tratar como Pagamentos e Financiamentos **somente as linhas `purchase`**.

### Transações

```http
GET /api/v1/transacoes/listar?fatura_id={id}
```

| Campo | Uso |
|-------|-----|
| `tipo` | `purchase` \| `payment` \| `refund` \| `advance` \| `fee` \| `carryover` |
| `tipo_label` | Compra, Pagamento, Estorno, Antecipação, Encargo, **Saldo anterior** |
| `operacional` | `true` se não for `purchase` |
| `grupo_chave` | `"cartao"` \| `"pagamentos_financiamentos"` \| `"operacionais"` |
| `estabelecimento` | Nome da linha (em compra, inclui nome próprio) |

`grupo_chave` nas linhas:

| Condição | `grupo_chave` | Seção na UI |
|----------|---------------|-------------|
| Tem `cartao_numero_id` / `ultimos_digitos` | `cartao` | Grupo `••••` (Compras ou Operacionais **dentro do cartão**, como já era) |
| Sem cartão + `purchase` | `pagamentos_financiamentos` | Seção **Pagamentos e Financiamentos** |
| Sem cartão + `operacional` | `operacionais` | Seção **Operacionais** (irmã, depois da nova) |

Lookups: `tipos[].operacional`.

---

## UI — como montar

Três faixas, nesta ordem:

1. **Cartões** (`grupos_por_cartao` com `grupo_chave === "cartao"`)  
   Igual hoje: Compras do final, depois Operacionais **daquele final** (`payment` / `refund` / `fee` / `carryover` **com** o mesmo `cartao_numero_id`).

2. **Pagamentos e Financiamentos** (`grupo_chave === "pagamentos_financiamentos"`)  
   Só linhas `tipo === "purchase"` sem cartão.  
   **Sem subtítulo Compras/Operacionais.** Sem bloco Operacionais dentro.  
   Título da seção = `label` da API (`Pagamentos e Financiamentos`).  
   Se não houver nenhuma linha, ocultar a seção.

3. **Operacionais** (como já era — bloco de operações **sem cartão**)  
   Linhas com `grupo_chave === "operacionais"` (pagamentos, saldo anterior, estornos sem final, encargos).  
   **Fora** de Pagamentos e Financiamentos, **depois** dela.  
   Se não houver nenhuma, ocultar.

Não usar o rótulo “Sem cartão identificado”.

Não esconder `purchase` porque o nome “parece pessoa”.

---

## Checklist

- [ ] Operacionais **não** está aninhada em Pagamentos e Financiamentos
- [ ] Ordem: cartões (`••••`) → **Pagamentos e Financiamentos** → **Operacionais**
- [ ] Pagamentos e Financiamentos só tem compras sem final (Thaís, Guilherme, Ingrid, Pix…)
- [ ] Pagamentos da fatura e “Saldo restante da fatura anterior” estão em **Operacionais**
- [ ] Dentro de cada `••••`, Compras + Operacionais seguem como já eram
- [ ] Sumiu “Sem cartão identificado”
