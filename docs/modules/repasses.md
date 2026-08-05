# Especificação — Repasses do Responsável

## Problema

Compras atribuídas a um responsável (ex.: empresa, cônjuge) entram na fatura do cartão do usuário. O responsável devolve o valor mês a mês (especialmente em parcelados). Hoje o sistema só sabe o **devido** (projeção / fatura do responsável); **não** registra o que já foi repassado.

A quitação existente (`tipo=payment` na fatura da **bandeira**) é outra coisa: pagamento do cartão ao banco. Não misturar.

| Conceito | Escopo | Significado |
|----------|--------|-------------|
| Quitação da fatura (bandeira) | `Fatura` + txs `payment` | Usuário pagou o banco |
| **Repasse do responsável** | Parcela (`Transacao`) + `repasses` | Responsável pagou o usuário |

## Decisão de modelo

### Grão: 1 parcela = 1 `transacao`

Cada parcela já existe como linha em `transacoes` com `fatura.mes` / `fatura.ano`. O repasse aponta para essa linha.

- Matriz UI: **linha = compra** (`compra_grupo_id` ou id à vista), **coluna = competência** → célula = aquela parcela.
- Pagamento parcial: vários registros de repasse na mesma `transacao_id`.

### Tabela `repasses`

| Campo | Tipo | Obs |
|-------|------|-----|
| id | PK | |
| user_id | FK | isolamento |
| transacao_id | FK → `transacoes` | parcela sendo quitada; deve ser `tipo=purchase` |
| valor | decimal(12,2) | valor deste pagamento (> 0) |
| data_pagamento | date | quando o responsável pagou |
| observacoes | text nullable | |
| timestamps | | |
| SoftDeletes | | |

Índices: `(user_id, transacao_id)`, `(transacao_id)`.

### Status (sempre calculado — não persistir)

Sobre a **parcela** (`transacao`):

| Campo | Regra |
|-------|--------|
| `valor_devido` | `transacao.valor` |
| `valor_pago` | `sum(repasses.valor)` ativos |
| `valor_aberto` | `max(valor_devido - valor_pago, 0)` |
| `status_repasse` | `pendente` se pago=0; `parcial` se 0 < pago < devido; `pago` se pago ≥ devido |

Sobre a **compra** (grupo):

| Campo | Regra |
|-------|--------|
| `valor_total` | soma dos `valor` das parcelas do grupo (ou da linha à vista) |
| `valor_pago` | soma dos repasses de todas as parcelas |
| `valor_aberto` | `max(valor_total - valor_pago, 0)` |
| `parcelas_total` | `parcelas_total` / count |
| `parcelas_pagas` | qtd de parcelas com `status_repasse = pago` |
| `parcelas_parciais` | qtd com `parcial` |
| `parcelas_pendentes` | restante |
| `status_repasse` | `pago` se aberto=0; `parcial` se algum pagamento; senão `pendente` |

À vista: `compra_grupo_id` null → tratar a própria `transacao` como “grupo” de 1.

### Regras de negócio

1. Só `tipo=purchase` recebe repasse.
2. `valor` do repasse > 0.
3. Permitir `valor_pago` total **acima** do devido? **Não** no MVP — rejeitar 422 se `sum + novo > valor_devido + 0.01` (tol. centavo). Alternativa futura: “crédito”.
4. Excluir parcela / grupo: soft-delete dos repasses vinculados (ou impedir exclusão se houver repasse — preferir soft-delete em cascata lógica no service).
5. Trocar `responsavel_id` da compra: repasses permanecem na parcela (ainda fazem sentido); a matriz filtra pelo responsável atual.
6. Não alterar `tipo=payment` da bandeira.

## API (`/api/v1/repasses`) — **implementada**

Prefixo + `routerFiles/repassesRouter.php`. CRUD padrão + endpoints de matriz.

### Lookups

```http
GET /api/v1/repasses/lookups
```

- `status_repasse`: pendente | parcial | pago
- `responsaveis` (ativos)

### Matriz (tela principal)

```http
GET /api/v1/repasses/matriz?responsavel_id=2&mes=8&ano=2026&janela=13
```

- `mes`/`ano`: referência (igual projeção).
- `janela`: colunas de competência (default 13 = mês anterior + 12 à frente).
- Retorna só compras do responsável que tenham **pelo menos uma parcela** na janela (ou saldo em aberto fora da janela — ver `incluir_abertos`).

Query opcional:

| Param | Default | Uso |
|-------|---------|-----|
| `incluir_abertos` | true | inclui compras com `valor_aberto > 0` mesmo se parcela atual fora da janela |
| `somente_abertos` | false | esconde compras já 100% pagas |
| `cartao_id` | — | filtro |

#### Shape

```json
{
  "responsavel_id": 2,
  "responsavel_nome": "Empresa",
  "responsavel_tipo": "empresa",
  "referencia": { "mes": 8, "ano": 2026 },
  "colunas": [
    { "mes": 7, "ano": 2026, "chave": "2026-07", "label": "Jul/2026", "referencia": false },
    { "mes": 8, "ano": 2026, "chave": "2026-08", "label": "Ago/2026", "referencia": true }
  ],
  "resumo": {
    "valor_total_compras": 5000.00,
    "valor_pago": 1200.00,
    "valor_aberto": 3800.00,
    "compras_abertas": 3,
    "compras_pagas": 1,
    "parcelas_pendentes_na_referencia": 2,
    "valor_aberto_na_referencia": 450.00
  },
  "compras": [
    {
      "chave_compra": "uuid-do-grupo",
      "compra_grupo_id": "uuid-do-grupo",
      "transacao_id_avista": null,
      "estabelecimento": "Magazine",
      "observacoes": "TV 55\"",
      "data_compra": "2026-03-15",
      "cartao_id": 1,
      "cartao_nome": "Nubank",
      "cartao_cor_fundo": "#820ad1",
      "cartao_cor_texto": "#ffffff",
      "ultimos_digitos": "1234",
      "parcelas_total": 10,
      "valor_total": 3000.00,
      "valor_pago": 900.00,
      "valor_aberto": 2100.00,
      "parcelas_pagas": 3,
      "parcelas_parciais": 0,
      "parcelas_pendentes": 7,
      "status_repasse": "parcial",
      "celulas": {
        "2026-07": {
          "transacao_id": 101,
          "fatura_id": 50,
          "parcela_atual": 4,
          "parcelas_total": 10,
          "valor_devido": 300.00,
          "valor_pago": 300.00,
          "valor_aberto": 0,
          "status_repasse": "pago",
          "data_ultimo_pagamento": "2026-07-20",
          "qtd_repasses": 1
        },
        "2026-08": {
          "transacao_id": 102,
          "fatura_id": 55,
          "parcela_atual": 5,
          "parcelas_total": 10,
          "valor_devido": 300.00,
          "valor_pago": 0,
          "valor_aberto": 300.00,
          "status_repasse": "pendente",
          "data_ultimo_pagamento": null,
          "qtd_repasses": 0
        }
      }
    }
  ]
}
```

`chave_compra`: `compra_grupo_id` ou `"t:{transacao_id}"` se à vista.

Células ausentes = sem parcela naquela competência (não renderizar ou “—”).

### CRUD de repasse

```http
POST   /api/v1/repasses/cadastrar
PUT    /api/v1/repasses/editar
DELETE /api/v1/repasses/excluir/{id}
GET    /api/v1/repasses/listar?transacao_id=
GET    /api/v1/repasses/listar/{id}
```

#### Create

```json
{
  "transacao_id": 102,
  "valor": "300,00",
  "data_pagamento": "2026-08-05",
  "observacoes": "PIX"
}
```

Atalho **quitar parcela**:

```json
{
  "transacao_id": 102,
  "quitar": true,
  "data_pagamento": "2026-08-05"
}
```

→ `valor = valor_aberto` atual.

Atalho **quitar competência do responsável** (todas as parcelas pendentes/parciais do mês):

```http
POST /api/v1/repasses/quitar-competencia
```

```json
{
  "responsavel_id": 2,
  "mes": 8,
  "ano": 2026,
  "data_pagamento": "2026-08-05"
}
```

Cria um repasse por parcela com aberto > 0, valor = aberto.

### Enriquecimento em listagens existentes

Em `GET /transacoes/listar` (e fatura do responsável), incluir por linha purchase:

- `valor_pago_repasse`
- `valor_aberto_repasse`
- `status_repasse`
- `data_ultimo_repasse`

Assim a tela de fatura do responsável mostra badge sem chamar a matriz.

## Relação com Projeção / Fatura do responsável

```
Projeção
  ├─ clique responsável → Fatura do responsável (competência)
  │     └─ badge status_repasse + CTA “Registrar repasse”
  └─ CTA “Controle de repasses” → Matriz compra × mês
        └─ clique célula → modal cadastrar/editar/quitar
```

A projeção **não** precisa mudar totais devidos; opcionalmente (fase 2) células de `por_responsavel` podem expor `valor_aberto_repasse` na competência.

## Fora de escopo (MVP)

- Repasse sem vincular a parcela (pagamento “avulso” / conta corrente do responsável)
- Juros / desconto negociado
- Notificações / lembretes
- Alterar `projecao-faturas` (só navegação no front)
