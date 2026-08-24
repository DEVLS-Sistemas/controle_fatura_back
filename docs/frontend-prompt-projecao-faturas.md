# Prompt — Frontend: Projeção de Faturas

Use este prompt no repositório do frontend para implementar a tela de projeção de valores de cartão.

---

## Objetivo

Exibir **quanto o usuário deverá pagar nos próximos meses**, com base em:

1. **Compras já cadastradas** (manual ou importadas do PDF)
2. **Parcelas futuras projetadas** a partir de compras parceladas (`parcela_atual` / `parcelas_total`)

Quando uma fatura é processada via PDF, compras manuais pré-cadastradas são **consolidadas** (mesmo estabelecimento, valor e parcela) — evitando duplicidade.

Também exibir, por cartão:

1. **Limite** (valor)
2. **Total em uso** (valor + %)
3. **Total livre** (valor + %)

E, nas visões por responsável / cartão × responsável: **quanto é meu** vs **quanto é dos outros** (valor + % do total gasto e, quando houver limite, % do limite).

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
  "responsavel_eu_id": 1,
  "por_cartao": [
    {
      "cartao_id": 1,
      "nome": "Nubank",
      "qtd_bandeiras": 1,
      "limite_credito": 8000,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "uso_limite": {
        "limite": 8000,
        "em_uso": 1050.9,
        "percentual_em_uso": 13.1,
        "livre": 6949.1,
        "percentual_livre": 86.9,
        "meu": {
          "realizado": 100,
          "projetado": 500,
          "total": 600,
          "percentual": 57.1,
          "percentual_do_limite": 7.5
        },
        "outros": {
          "realizado": 50.9,
          "projetado": 400,
          "total": 450.9,
          "percentual": 42.9,
          "percentual_do_limite": 5.6
        }
      },
      "valores": [
        {
          "realizado": 0,
          "projetado": 0,
          "total": 0,
          "fonte": "vazio",
          "em_uso": 0,
          "livre": 8000,
          "percentual_utilizado": 0,
          "percentual_livre": 100,
          "disponivel": 8000,
          "meu": {
            "realizado": 0,
            "projetado": 0,
            "total": 0,
            "percentual": null,
            "percentual_do_limite": 0
          },
          "outros": {
            "realizado": 0,
            "projetado": 0,
            "total": 0,
            "percentual": null,
            "percentual_do_limite": 0
          }
        },
        {
          "realizado": 150.9,
          "projetado": 900,
          "total": 1050.9,
          "fonte": "parcial",
          "em_uso": 1050.9,
          "livre": 6949.1,
          "percentual_utilizado": 13.1,
          "percentual_livre": 86.9,
          "disponivel": 6949.1,
          "meu": {
            "realizado": 100,
            "projetado": 500,
            "total": 600,
            "percentual": 57.1,
            "percentual_do_limite": 7.5
          },
          "outros": {
            "realizado": 50.9,
            "projetado": 400,
            "total": 450.9,
            "percentual": 42.9,
            "percentual_do_limite": 5.6
          }
        }
      ],
      "total": 1050.9,
      "resumo_eu_outros": [
        {
          "meu": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": 0 },
          "outros": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": 0 },
          "total": 0
        },
        {
          "meu": { "realizado": 100, "projetado": 500, "total": 600, "percentual": 57.1, "percentual_do_limite": 7.5 },
          "outros": { "realizado": 50.9, "projetado": 400, "total": 450.9, "percentual": 42.9, "percentual_do_limite": 5.6 },
          "total": 1050.9
        }
      ]
    }
  ],
  "por_responsavel": [
    {
      "responsavel_id": 1,
      "nome": "Eu",
      "tipo": "pessoal",
      "eh_eu": true,
      "valores": [
        { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio", "percentual_participacao": null },
        { "realizado": 100, "projetado": 500, "total": 600, "fonte": "misto", "percentual_participacao": 57.1 }
      ],
      "total": 600
    },
    {
      "responsavel_id": 2,
      "nome": "Empresa",
      "tipo": "empresa",
      "eh_eu": false,
      "valores": [
        { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio", "percentual_participacao": null },
        { "realizado": 50.9, "projetado": 400, "total": 450.9, "fonte": "misto", "percentual_participacao": 42.9 }
      ],
      "total": 450.9
    }
  ],
  "resumo_eu_outros": [
    {
      "meu": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": null },
      "outros": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": null },
      "total": 0
    },
    {
      "meu": { "realizado": 100, "projetado": 500, "total": 600, "percentual": 57.1, "percentual_do_limite": null },
      "outros": { "realizado": 50.9, "projetado": 400, "total": 450.9, "percentual": 42.9, "percentual_do_limite": null },
      "total": 1050.9
    }
  ],
  "por_cartao_responsavel": [
    {
      "cartao_id": 1,
      "nome": "Nubank",
      "qtd_bandeiras": 1,
      "limite_credito": 8000,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "uso_limite": {
        "limite": 8000,
        "em_uso": 1050.9,
        "percentual_em_uso": 13.1,
        "livre": 6949.1,
        "percentual_livre": 86.9,
        "meu": {
          "realizado": 100,
          "projetado": 500,
          "total": 600,
          "percentual": 57.1,
          "percentual_do_limite": 7.5
        },
        "outros": {
          "realizado": 50.9,
          "projetado": 400,
          "total": 450.9,
          "percentual": 42.9,
          "percentual_do_limite": 5.6
        }
      },
      "valores": [
        {
          "realizado": 0,
          "projetado": 0,
          "total": 0,
          "fonte": "vazio",
          "em_uso": 0,
          "livre": 8000,
          "percentual_utilizado": 0,
          "percentual_livre": 100,
          "disponivel": 8000,
          "meu": {
            "realizado": 0,
            "projetado": 0,
            "total": 0,
            "percentual": null,
            "percentual_do_limite": 0
          },
          "outros": {
            "realizado": 0,
            "projetado": 0,
            "total": 0,
            "percentual": null,
            "percentual_do_limite": 0
          }
        },
        {
          "realizado": 150.9,
          "projetado": 900,
          "total": 1050.9,
          "fonte": "misto",
          "em_uso": 1050.9,
          "livre": 6949.1,
          "percentual_utilizado": 13.1,
          "percentual_livre": 86.9,
          "disponivel": 6949.1,
          "meu": {
            "realizado": 100,
            "projetado": 500,
            "total": 600,
            "percentual": 57.1,
            "percentual_do_limite": 7.5
          },
          "outros": {
            "realizado": 50.9,
            "projetado": 400,
            "total": 450.9,
            "percentual": 42.9,
            "percentual_do_limite": 5.6
          }
        }
      ],
      "total": 1050.9,
      "resumo_eu_outros": [
        {
          "meu": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": 0 },
          "outros": { "realizado": 0, "projetado": 0, "total": 0, "percentual": null, "percentual_do_limite": 0 },
          "total": 0
        },
        {
          "meu": { "realizado": 100, "projetado": 500, "total": 600, "percentual": 57.1, "percentual_do_limite": 7.5 },
          "outros": { "realizado": 50.9, "projetado": 400, "total": 450.9, "percentual": 42.9, "percentual_do_limite": 5.6 },
          "total": 1050.9
        }
      ],
      "por_responsavel": [
        {
          "responsavel_id": 1,
          "nome": "Eu",
          "tipo": "pessoal",
          "eh_eu": true,
          "valores": [
            { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio", "percentual_participacao": null },
            { "realizado": 100, "projetado": 500, "total": 600, "fonte": "misto", "percentual_participacao": 57.1 }
          ],
          "total": 600
        },
        {
          "responsavel_id": 2,
          "nome": "Empresa",
          "tipo": "empresa",
          "eh_eu": false,
          "valores": [
            { "realizado": 0, "projetado": 0, "total": 0, "fonte": "vazio", "percentual_participacao": null },
            { "realizado": 50.9, "projetado": 400, "total": 450.9, "fonte": "misto", "percentual_participacao": 42.9 }
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
| `em_uso` | Alias de `total` (quanto do limite está comprometido naquele mês) |
| `livre` | `limite_credito - em_uso` — `null` se sem limite |
| `percentual_utilizado` | `(em_uso / limite_credito) * 100` — `null` se sem limite |
| `percentual_livre` | `(livre / limite_credito) * 100` — `null` se sem limite |
| `disponivel` | Alias legado de `livre` (manter compatibilidade) |
| `meu` / `outros` | Split do consumo: valores + `% do total gasto` (`percentual`) + `% do limite` (`percentual_do_limite`) |
| `percentual_participacao` | Na linha do responsável: `(total do responsável / total do mês) * 100` |
| `eh_eu` | `true` se a linha é o responsável seed `"Eu"` |
| `uso_limite` | Snapshot do **mês de referência** (`colunas[].referencia === true`): limite / em uso / livre + meu/outros |
| `resumo_eu_outros` | Array alinhado às 13 colunas com totais Eu vs Outros |

**Limite de crédito:** soma dos `limite_credito` das bandeiras ativas do grupo — vem em `por_cartao[].limite_credito` e `por_cartao_responsavel[].limite_credito` (nullable).

**Quem é “Eu”:** responsável com nome `"Eu"` (case-insensitive). ID em `responsavel_eu_id` (`null` se o usuário renomeou/excluiu). Todo o restante entra em `outros`.

**Por cartão:** mês com fatura `processada` usa `valor_total` da fatura como `realizado`/`em_uso` (sem projetar de novo). O split `meu`/`outros` continua baseado nas **compras por responsável** (pode somar diferente do `valor_total` líquido).

**Por responsável:** soma apenas **compras** (`tipo=purchase`) + parcelas projetadas. Use `data.resumo_eu_outros` para o card/resumo “Eu vs Outros” acima da tabela. Em cada célula, `percentual_participacao` mostra a fatia daquele responsável no mês.

**Por cartão × responsável:** igual, porém o denominador do % é o total **daquele cartão** no mês. Use `por_cartao_responsavel[].resumo_eu_outros` / `uso_limite` no cabeçalho do cartão expandido.

---

## Layout sugerido

### Seletor de referência
- Mês/ano (default: atual)
- Ao mudar, recarregar a projeção

### Tabela 1 — Por cartão
- **Linhas:** um cartão ativo por linha
- **Colunas:** as 13 colunas de `colunas[].label`
- **Célula:** exibir `total` / `em_uso` formatado em R$
- **Uso do limite** (quando `limite_credito` / `uso_limite.limite` não for null) — preferir `uso_limite` no cabeçalho da linha:
  - **Limite:** `R$ X`
  - **Em uso:** `R$ Y (P%)` — `uso_limite.em_uso` / `percentual_em_uso`
  - **Livre:** `R$ W (Q%)` — `uso_limite.livre` / `percentual_livre`
  - Cor sugerida do % em uso: verde (< 50%), âmbar (50–80%), vermelho (> 80%)
  - Em cada célula: badge com `percentual_utilizado` + opcional stacked “Eu | Outros” usando `valores[].meu` / `valores[].outros`
  - Tooltip: `Limite: R$ Z | Em uso: R$ Y (P%) | Livre: R$ W (Q%) | Eu: R$ A (B% do uso · C% do limite) | Outros: R$ D (E% · F%)`
- Destaque visual:
  - coluna `referencia: true` (mês atual)
  - valores com `projetado > 0`: cor/ícone diferente (ex.: tracejado ou badge “proj.”)
- Linha de totais no rodapé usando `totais_por_coluna[].cartoes.total`

### Tabela 2 — Por responsável
- Mesma estrutura de colunas
- Linhas = responsáveis ativos (`eh_eu` pode destacar a linha “Eu”)
- **Resumo Eu vs Outros** (acima ou abaixo da tabela), alinhado às colunas ou focado no mês de referência:
  - Usar `data.resumo_eu_outros[i]`
  - Exibir: `Eu: R$ X (P%)` · `Outros: R$ Y (Q%)`
  - No mês de referência, um card compacto com os dois totais basta
- Em cada célula: valor + `percentual_participacao` (ex.: `R$ 600 · 57%`)
- Totais via `totais_por_coluna[].responsaveis.total`

### Tabela 3 — Por cartão × responsável
- Expanda uma linha de cartão para ver `por_cartao_responsavel[].por_responsavel[]`
- No cabeçalho do cartão: repetir os 3 indicadores de `uso_limite` (limite / em uso / livre) **e** o split Eu vs Outros (`uso_limite.meu` / `uso_limite.outros` ou `resumo_eu_outros` do mês referência)
- Cada sublinha = um responsável naquele cartão; célula = valor + `percentual_participacao` daquele cartão/mês

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

O import de PDF parcelado também **materializa** as parcelas restantes — anteriores e futuras — (faturas `pendente` sem anexo + transação da competência, ligadas por `compra_grupo_id`). A projeção virtual fica só para legado sem grupo.

Quando o PDF da fatura for processado, a compra manual do mês é **mesclada** (mantém responsável, categoria, observações).

---

## Navegação

- Item de menu: **Projeção** ou **Previsão de faturas**
- Pode ficar junto ao Dashboard ou em Relatórios
- Clique no responsável (linha ou célula do mês) → tela **Fatura do Responsável** (todas as compras daquele responsável na competência, em todos os cartões). Prompt: [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md)
- **Simular uma compra** (menu separado, reusa esta matriz): [`frontend-prompt-simulador-compra.md`](frontend-prompt-simulador-compra.md). Deep-link sugerido a partir desta tela: `/simulador?cartao_id=&responsavel_id=&pessoa_id=`

---

## Checklist de aceite

- [ ] Tabela 13 meses por cartão
- [ ] Por cartão: exibir **Limite**, **Em uso (R$ + %)**, **Livre (R$ + %)** via `uso_limite` (mês referência)
- [ ] Células por cartão com `em_uso` / `livre` / `percentual_utilizado` / `percentual_livre`
- [ ] Sem limite cadastrado: não mostrar barra/% (tratar `null`)
- [ ] Tabela 13 meses por responsável com `percentual_participacao`
- [ ] Resumo **Eu vs Outros** (valor + %) em `resumo_eu_outros` (visão responsável)
- [ ] Detalhe cartão × responsável com o mesmo split por cartão (`por_cartao_responsavel[].resumo_eu_outros` / `uso_limite.meu|outros`)
- [ ] Destacar linha `eh_eu === true`
- [ ] Destaque do mês de referência
- [ ] Diferenciação visual realizado vs projetado
- [ ] Seletor mês/ano de referência
- [ ] Totais por coluna
- [ ] Scroll horizontal em telas pequenas
