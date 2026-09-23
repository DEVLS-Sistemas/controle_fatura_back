# Prompt — Frontend: Total da fatura = valor do PDF

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Complementa o bloco financeiro de [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

Card: **CTLFAT-12**. Backend deste card implementa o contrato.

Não misturar com [`frontend-prompt-substituir-fatura-existente.md`](frontend-prompt-substituir-fatura-existente.md) (CTA / reprocessar) nem com [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md) (desfazer extrato).

---

## Problema (caso real)

Fatura `id=738` (competência 09/2026): o PDF diz **R$ 2.288,25** e a tela mostrava **R$ 2.150,68** (soma incompleta das linhas). Remover o PDF apaga as transações importadas (esperado) e, ao anexar de novo, o total **não pode** voltar a 2150.68.

O número grande **Total da fatura** é o valor **oficial do PDF**, não a soma de `transacoes[]` no browser.

---

## Contrato (`GET /api/v1/faturas/listar/{id}`)

Campos já existentes (não recalcular no client):

| Campo | Papel |
|-------|--------|
| `valor_total` | Total oficial gravado (cabeçalho do PDF) |
| `valor_extrato` | O mesmo valor do PDF quando a fatura está `processada` |
| `valor_total_com_pendencias` | `valor_extrato + valor_nao_conciliado` — **este** é o H1 “Total da fatura” |
| `valor_nao_conciliado` | Só compras **manuais** ainda abertas |

Novo objeto (só no detalhe; `null` se a fatura não estiver `processada` com `valor_fatura`):

```json
"conferencia": {
  "valor_cabecalho": 2288.25,
  "soma_transacoes": 2288.25,
  "bate": true,
  "diferenca": 0
}
```

| Campo | Significado |
|-------|-------------|
| `valor_cabecalho` | Total oficial do PDF (o que a API gravou) |
| `soma_transacoes` | Soma das linhas importadas (compras + encargos − estornos; sem pagamentos) |
| `bate` | `true` se os dois batem (tolerância de 5 centavos) |
| `diferenca` | `valor_cabecalho - soma_transacoes` |

Se o parser importar a linha que faltava, `bate === true`. Se ainda faltar linha, `bate === false` e o **número grande continua o do PDF** (`valor_total_com_pendencias` / `valor_extrato` = 2288.25, **não** 2150.68).

---

## UI obrigatória

### Número grande

```
Total da fatura   R$ {valor_total_com_pendencias}
```

- **Não** somar `transacoes[]` / `grupos_por_cartao` para obter esse número.
- Com compra manual aberta: o extra entra via `valor_nao_conciliado` (já especificado no prompt de faturas).
- Sem pendência: o H1 = `valor_extrato` = total do PDF.

Na **listagem** do mesmo cartão/competência, o `valor_total` da fatura deve ser o mesmo número (2288.25 no caso 738).

### Aviso de conferência (só se `conferencia && conferencia.bate === false`)

Bloco **abaixo** do total, distinto do aviso âmbar de compras manuais (`tem_compras_nao_conciliadas`). Não troca o H1.

```
┌─────────────────────────────────────────────────────────┐
│ Total no PDF: R$ {valor_cabecalho}                      │
│ Soma das linhas: R$ {soma_transacoes}                   │
│ Diferença: R$ {diferenca}                               │
└─────────────────────────────────────────────────────────┘
```

- Não renderizar se `conferencia` for `null` ou `bate === true`.
- Não usar esse aviso no lugar do bloco de compras não conciliadas. Os dois podem coexistir.

### Depois de remover + anexar o PDF

1. Poll / refetch `GET /faturas/listar/{id}` (status `processando` → `processada`).
2. Poll da lista de transações.
3. O H1 **não** pode voltar à soma antiga (2150.68 no caso 738). Esperado: **R$ 2.288,25**.

---

## O que não fazer

- Recalcular quitação (`valor_pago` / `valor_restante`) no client.
- Tratar `conferencia.bate === false` como “usar a soma das linhas”.
- Botões cadastrar vs substituir (CTLFAT-10).
- Mudar a regra de remoção do PDF.

---

## Checklist

- [ ] Detalhe 738: Total da fatura = R$ 2.288,25
- [ ] Listagem 09/2026 do mesmo cartão com o mesmo total
- [ ] Aviso de conferência **só** quando `bate === false`; não troca o número grande
- [ ] Remover anexo, enviar de novo, esperar o poll: total oficial permanece o do PDF
