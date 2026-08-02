# Prompt — Frontend: Projeção de Faturas

Use este prompt no repositório do frontend para implementar a tela de projeção de valores de cartão.

---

## Objetivo

Exibir **quanto o usuário deverá pagar nos próximos meses**, com base em:

1. **Compras já cadastradas** (manual ou importadas do PDF)
2. **Parcelas futuras projetadas** a partir de compras parceladas (`parcela_atual` / `parcelas_total`)

Quando uma fatura é processada via PDF, compras manuais pré-cadastradas são **consolidadas** (mesmo estabelecimento, valor e parcela) — evitando duplicidade.

Também exibir o **% do limite de crédito utilizado** por cartão/mês (quando `limite_credito` estiver cadastrado).

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
      "limite_credito": 8000,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "valores": [
        {
          "realizado": 0,
          "projetado": 0,
          "total": 0,
          "fonte": "vazio",
          "percentual_utilizado": 0,
          "disponivel": 8000
        },
        {
          "realizado": 150.9,
          "projetado": 900,
          "total": 1050.9,
          "fonte": "parcial",
          "percentual_utilizado": 13.1,
          "disponivel": 6949.1
        }
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
  "por_cartao_responsavel": [
    {
      "cartao_id": 1,
      "nome": "Nubank",
      "bandeira": "Mastercard",
      "ultimos_digitos": "1234",
      "limite_credito": 8000,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "valores": [
        {
          "realizado": 0,
          "projetado": 0,
          "total": 0,
          "fonte": "vazio",
          "percentual_utilizado": 0,
          "disponivel": 8000
        },
        {
          "realizado": 150.9,
          "projetado": 900,
          "total": 1050.9,
          "fonte": "misto",
          "percentual_utilizado": 13.1,
          "disponivel": 6949.1
        }
      ],
      "total": 1050.9,
      "por_responsavel": [
        {
          "responsavel_id": 1,
          "nome": "Eu",
          "tipo": "pessoal",
          "valores": [
            { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio" },
            { "realizado": 100, "projetado": 500, "total": 600, "fonte": "misto" }
          ],
          "total": 600
        },
        {
          "responsavel_id": 2,
          "nome": "Outro",
          "tipo": "compartilhado",
          "valores": [
            { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio" },
            { "realizado": 50.9, "projetado": 400, "total": 450.9, "fonte": "misto" }
          ],
          "total": 450.9
        }
      ]
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
| `percentual_utilizado` | `(total / limite_credito) * 100` — `null` se o cartão não tem limite cadastrado |
| `disponivel` | `limite_credito - total` — `null` se sem limite |

**Limite de crédito:** vem em `por_cartao[].limite_credito` e `por_cartao_responsavel[].limite_credito` (nullable).  
`percentual_utilizado` / `disponivel` existem **só nas células das tabelas por cartão** (não na tabela por responsável).

**Por cartão:** mês com fatura `processada` usa `valor_total` da fatura como `realizado` (sem projetar de novo).

**Por responsável:** soma apenas **compras** (`tipo=purchase`) atribuídas ao responsável + parcelas projetadas dele.

**Por cartão × responsável (`por_cartao_responsavel`):** para cada cartão, quebra o valor por responsável (compras + projeção daquele cartão). Use para ver “quanto eu gastei neste cartão” vs “quanto os outros gastaram”. O `valores` do cartão neste bloco é a soma das compras dos responsáveis (pode diferir de `por_cartao` em fatura `processada`, que usa `valor_total` líquido).

---

## Layout sugerido

### Seletor de referência
- Mês/ano (default: atual)
- Ao mudar, recarregar a projeção

### Tabela 1 — Por cartão
- **Linhas:** um cartão ativo por linha
- **Colunas:** as 13 colunas de `colunas[].label`
- **Célula:** exibir `total` formatado em R$
- **Uso do limite** (quando `limite_credito` não for null):
  - Na coluna do nome do cartão: `Limite R$ X` + barra/% do mês de referência (`colunas[].referencia === true`)
  - Em cada célula: badge ou subtítulo com `percentual_utilizado` (ex.: `13%`)
  - Cor sugerida: verde (< 50%), âmbar (50–80%), vermelho (> 80%)
  - Tooltip: `Realizado: R$ X | Projetado: R$ Y | Limite: R$ Z | Disponível: R$ W (P%)`
- Destaque visual:
  - coluna `referencia: true` (mês atual)
  - valores com `projetado > 0`: cor/ícone diferente (ex.: tracejado ou badge “proj.”)
  - tooltip mínimo: `Realizado: R$ X | Projetado: R$ Y`
- Linha de totais no rodapé usando `totais_por_coluna[].cartoes.total`

### Tabela 2 — Por responsável (abaixo)
- Mesma estrutura de colunas
- Linhas = responsáveis ativos
- Totais via `totais_por_coluna[].responsaveis.total`

### Tabela 3 — Por cartão × responsável (opcional / detalhe)
- Expanda uma linha de cartão para ver `por_cartao_responsavel[].por_responsavel[]`
- Cada sublinha = um responsável naquele cartão
- Mesmas 13 colunas; célula = quanto aquele responsável gerou naquele cartão/mês
- No cabeçalho do cartão expandido, pode repetir limite/% do mês de referência

### Responsividade
- Em mobile: scroll horizontal na tabela; fixar coluna do nome do cartão/responsável

---

## Fluxo de cadastro de compra parcelada

Compras manuais parceladas agora **materializam N transações** (uma por mês), ligadas por `compra_grupo_id`:

```json
POST /api/v1/transacoes/cadastrar
{
  "cartao_id": 1,
  "estabelecimento_id": 10,
  "valor_compra": "1000,00",
  "data": "2026-07-15",
  "parcelas_total": 10,
  "parcelas": [
    { "parcela": 1, "valor": "100,00" },
    { "parcela": 2, "valor": "100,00" }
  ],
  "tipo": "purchase",
  "responsavel_id": 1
}
```

Com isso, a projeção mostra as parcelas futuras como **realizado** (já cadastradas nas faturas dos meses seguintes), não como `projetado`.

O import de PDF parcelado também **materializa** as parcelas futuras (faturas `pendente` sem anexo + transação da competência, ligadas por `compra_grupo_id`). A projeção virtual fica só para legado sem grupo.

Quando o PDF da fatura for processado, a compra manual do mês é **mesclada** (mantém responsável, categoria, observações).

---

## Navegação

- Item de menu: **Projeção** ou **Previsão de faturas**
- Pode ficar junto ao Dashboard ou em Relatórios

---

## Checklist de aceite

- [ ] Tabela 13 meses por cartão
- [ ] Tabela 13 meses por responsável abaixo
- [ ] Detalhe cartão × responsável (quanto cada um gastou em cada cartão)
- [ ] Destaque do mês de referência
- [ ] Diferenciação visual realizado vs projetado
- [ ] Seletor mês/ano de referência
- [ ] Totais por coluna
- [ ] Scroll horizontal em telas pequenas
- [ ] Exibir `limite_credito` e `% utilizado` / disponível nas tabelas por cartão
- [ ] Sem limite cadastrado: não mostrar barra/% (tratar `null`)
