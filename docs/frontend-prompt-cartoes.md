# Prompt — Frontend: Cartões (ciclo + cores duplas)

Use este prompt no repositório do frontend para alinhar a UI de cartões à API do `controle_fatura_back`.

---

## Contexto

Cada cartão define o **ciclo da fatura** e um **par de cores** (fundo + texto) para identificação visual — útil quando vários cartões têm tons parecidos.

- `dia_limite_fatura`: até este dia do mês as compras entram na fatura atual; depois, na seguinte.
- `dia_vencimento_fatura`: dia em que a fatura deve ser paga (informativo).
- `cor_fundo` / `cor_texto`: hex para chips/badges (`background-color` + `color`).

Exemplo com limite = 5 em `01/08/2026`:

- Compra até `05/08/2026` → fatura de agosto
- Compra a partir de `06/08/2026` → fatura de setembro (também para a 1ª parcela de compras parceladas)

---

## API

Base: `/api/v1/cartoes` (Bearer Sanctum)

CRUD padrão: `lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `cartoes-list`.

### Lookups (`GET /lookups`)

```json
{
  "bandeiras": ["Visa", "Mastercard", "Elo", "Amex", "Hipercard", "Outra"],
  "cores_fundo": ["#ef4444", "#f59e0b", "#22c55e", "#3b82f6", "#8b5cf6", "..."],
  "cores_texto": ["#ffffff", "#0f172a", "#111827", "#f8fafc", "..."],
  "pares_cores": [
    { "cor_fundo": "#8b5cf6", "cor_texto": "#ffffff", "label": "Roxo" },
    { "cor_fundo": "#22c55e", "cor_texto": "#052e16", "label": "Verde" }
  ],
  "dias": [{ "value": 1, "label": "01" }, { "value": 2, "label": "02" }]
}
```

### Payload create/edit

```json
{
  "nome": "Nubank Principal",
  "bandeira": "Mastercard",
  "banco": "Nubank",
  "ultimos_digitos": "1234",
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12,
  "cor_fundo": "#8b5cf6",
  "cor_texto": "#ffffff",
  "ativo": true
}
```

Campos obrigatórios no create: `nome`, `dia_limite_fatura`, `dia_vencimento_fatura`.

**Breaking:** o campo único `cor` foi removido. Use sempre `cor_fundo` + `cor_texto`.

### Listagem / async

Retornam `dia_limite_fatura`, `dia_vencimento_fatura`, `cor_fundo` e `cor_texto`.

---

## UI sugerida

1. Formulário de cartão com:
   - Nome, bandeira, banco, últimos dígitos, ativo
   - Select **Dia limite da fatura** (1–31) + texto de ajuda: “Compras até este dia entram na fatura do mês”
   - Select **Dia de vencimento** (1–31) + texto: “Data limite para pagamento”
   - **Par de cores**:
     - Atalhos com `pares_cores` (preview do chip: fundo + texto)
     - Ou seleção manual: swatch `cor_fundo` + swatch `cor_texto`
     - Preview ao vivo: badge com `background: cor_fundo; color: cor_texto`
2. Listagem: badge/chip com as duas cores; exibir “Fecha dia X · Vence dia Y”.
3. Em selects de cartão (compras, faturas, projeção): chip com fundo/texto ao lado do nome.

---

## Checklist

- [ ] CRUD de cartão com limite, vencimento, `cor_fundo` e `cor_texto`
- [ ] Lookups `pares_cores`, `cores_fundo`, `cores_texto` e `dias`
- [ ] Preview do chip com as duas cores
- [ ] Remover uso do antigo campo `cor`
- [ ] Texto de ajuda explicando o ciclo da fatura
