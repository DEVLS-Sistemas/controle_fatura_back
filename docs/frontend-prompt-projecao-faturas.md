# Prompt — Frontend: Projeção de Faturas

Use este prompt no repositório do frontend para implementar a tela de projeção de valores de cartão.

---

## Objetivo

Exibir **quanto o usuário deverá pagar nos próximos meses**, com base em:

1. **Compras já cadastradas** (manual ou importadas do PDF)
2. **Parcelas futuras projetadas** a partir de compras parceladas (`parcela_atual` / `parcelas_total`)

Quando uma fatura é processada via PDF, compras manuais pré-cadastradas são **consolidadas** (mesmo estabelecimento, valor e parcela) — evitando duplicidade.

---

## API

```http
GET /api/v1/dashboard/projecao-faturas?mes=7&ano=2026
Authorization: Bearer {token}
```

- `mes` / `ano`: mês de referência (default: mês atual)
- Colunas: **13 meses** — começa no **mês anterior** à referência + 12 meses à frente

### Exemplo de resposta (`data`)

```json
{
  "referencia": { "mes": 7, "ano": 2026 },
  "colunas": [
    { "mes": 6, "ano": 2026, "chave": "2026-06", "label": "Jun/2026", "referencia": false },
    { "mes": 7, "ano": 2026, "chave": "2026-07", "label": "Jul/2026", "referencia": true }
  ],
  "por_cartao": [
    {
      "cartao_id": 1,
      "nome": "Nubank",
      "bandeira": "Mastercard",
      "ultimos_digitos": "1234",
      "valores": [
        { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio" },
        { "realizado": 150.9, "projetado": 900, "total": 1050.9, "fonte": "parcial" }
      ],
      "total": 1050.9
    }
  ],
  "por_responsavel": [
    {
      "responsavel_id": 1,
      "nome": "Eu",
      "tipo": "pessoal",
      "valores": [
        { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio" },
        { "realizado": 150.9, "projetado": 900, "total": 1050.9, "fonte": "misto" }
      ],
      "total": 1050.9
    }
  ],
  "totais_por_coluna": [
    {
      "mes": 6,
      "ano": 2026,
      "chave": "2026-06",
      "cartoes": { "realizado": 0, "projetado": 0, "total": 0 },
      "responsaveis": { "realizado": 0, "projetado": 0, "total": 0 }
    }
  ]
}
```

### Semântica dos valores

| Campo | Significado |
|-------|-------------|
| `realizado` | Valor já registrado (fatura processada ou compras manuais na fatura) |
| `projetado` | Parcelas futuras calculadas a partir de compras parceladas ainda não lançadas |
| `total` | `realizado + projetado` |
| `fonte` | `fatura` \| `parcial` \| `projecao` \| `misto` \| `vazio` — hint visual |

**Por cartão:** mês com fatura `processada` usa `valor_total` da fatura como `realizado` (sem projetar de novo).

**Por responsável:** soma apenas **compras** (`tipo=purchase`) atribuídas ao responsável + parcelas projetadas dele.

---

## Layout sugerido

### Seletor de referência
- Mês/ano (default: atual)
- Ao mudar, recarregar a projeção

### Tabela 1 — Por cartão
- **Linhas:** um cartão ativo por linha
- **Colunas:** as 13 colunas de `colunas[].label`
- **Célula:** exibir `total` formatado em R$
- Destaque visual:
  - coluna `referencia: true` (mês atual)
  - valores com `projetado > 0`: cor/ícone diferente (ex.: tracejado ou badge “proj.”)
  - tooltip: `Realizado: R$ X | Projetado: R$ Y`
- Linha de totais no rodapé usando `totais_por_coluna[].cartoes.total`

### Tabela 2 — Por responsável (abaixo)
- Mesma estrutura de colunas
- Linhas = responsáveis ativos
- Totais via `totais_por_coluna[].responsaveis.total`

### Responsividade
- Em mobile: scroll horizontal na tabela; fixar coluna do nome do cartão/responsável

---

## Fluxo de cadastro de compra parcelada

Ao cadastrar compra manual (ex.: 10x de R$ 100 em Jul/2026):

```json
POST /api/v1/transacoes/cadastrar
{
  "fatura_id": 1,
  "estabelecimento_id": 10,
  "valor": 100,
  "valor_parcela": 100,
  "parcela_atual": 1,
  "parcelas_total": 10,
  "tipo": "purchase",
  "responsavel_id": 1
}
```

A projeção mostrará:
- **Jul:** R$ 100 realizado
- **Ago–Abr/2027:** R$ 100 projetado por mês (9 parcelas restantes)

Quando o PDF da fatura for processado, a compra manual é **mesclada** (mantém responsável, categoria, observações).

---

## Navegação

- Item de menu: **Projeção** ou **Previsão de faturas**
- Pode ficar junto ao Dashboard ou em Relatórios

---

## Checklist de aceite

- [ ] Tabela 13 meses por cartão
- [ ] Tabela 13 meses por responsável abaixo
- [ ] Destaque do mês de referência
- [ ] Diferenciação visual realizado vs projetado
- [ ] Seletor mês/ano de referência
- [ ] Totais por coluna
- [ ] Scroll horizontal em telas pequenas
