# Especificação — Assinaturas (detector de cobranças recorrentes)

Identifica automaticamente compras à vista que se repetem no mesmo estabelecimento/loja (Netflix, Spotify, Google One, sistemas, etc.), estima o gasto anual e permite confirmar a origem como **pagamento de serviços**.

## Conceito

Não é um cadastro manual de “planos”. A assinatura é **derivada das transações**:

| Estado | Significado |
|--------|-------------|
| **Candidata** | Padrão recorrente (valor parecido + intervalo regular). Ainda não está majoritariamente marcada como serviço. |
| **Confirmada** | Maioria das cobranças com `origem_compra = PAGAMENTO_SERVICOS`. |
| **Ignorada** | Usuário disse que não é assinatura (ex.: mercado com ticket estável). Some da lista principal. |

Confirmar **não cria linha nova**: grava `origem_compra = PAGAMENTO_SERVICOS` nas compras à vista daquele grupo.

Parceladas (`compra_grupo_id` / `parcelas_total > 1`) **não entram** — são financiamento, não assinatura.

`PAGAMENTO_FATURA` também fica de fora.

## Agrupamento

1. Tenta agrupar pela **loja** (nome fantasia), quando o estabelecimento tem `loja_id`.
2. Se os valores do grupo da loja **não** forem parecidos (ex.: Google One + Google Play), **parte por estabelecimento**.
3. Identificador estável na API:
   - `loja-{id}` — ex.: `loja-12`
   - `estabelecimento-{id}` — ex.: `estabelecimento-45`

Título: nome da loja se o grupo for de loja; senão nome do estabelecimento.

## Regras de detecção

Unidade = uma compra à vista (`tipo=purchase`, sem `compra_grupo_id`).

**Valores parecidos** se a amplitude (`max − min`) ≤ R$ 20 **ou** ≤ 25% da mediana. Reajuste de plano (R$ 55,90 → R$ 59,90) continua assinatura; supermercado com tickets 32 / 187 / 94 não.

**Periodicidade** pela mediana dos intervalos (dias):

| Intervalo | Periodicidade |
|-----------|----------------|
| 5–9 | semanal |
| 13–17 | quinzenal |
| 25–40 | mensal |
| 80–105 | trimestral |
| 160–200 | semestral |
| 330–400 | anual |
| demais | irregular |

Mínimo de cobranças para **candidata**:

- semanal: 4
- quinzenal: 3
- mensal / trimestral / semestral / anual: 2
- irregular: nunca (só entra se já for confirmada)

**Confirmada** com 1 cobrança já marcada como serviço: assume periodicidade **mensal** (`periodicidade_assumida: true`, confiança baixa) para não subestimar o anual.

## Estimativa anual

| Periodicidade | Fórmula |
|---------------|---------|
| semanal | `valor_medio × 52` |
| quinzenal | `valor_medio × 26` |
| mensal | `valor_medio × 12` |
| trimestral | `valor_medio × 4` |
| semestral | `valor_medio × 2` |
| anual | `valor_medio × 1` |
| irregular | `gasto_12_meses` (soma real) |

`gasto_12_meses` = soma das cobranças com `data` nos últimos 12 meses a partir de hoje.

`estimativa_mensal` = `estimativa_anual / 12`.

## Tabela `assinaturas_ignoradas`

Só persiste o que o usuário **escondeu**. Detecção em si não grava linhas.

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | isolamento |
| tipo_chave | string | `loja` \| `estabelecimento` |
| referencia_id | uint | id da loja ou do estabelecimento |
| timestamps + SoftDeletes | | unique `(user_id, tipo_chave, referencia_id)` |

## Rotas (`/api/v1/assinaturas`)

CRUD padrão + `assinaturas-list`. Semântica:

| Rota | Ação real |
|------|-----------|
| `GET /lookups` | status, periodicidades, confianças, ações, ordenar |
| `GET /listar` | detector + totais (não é paginação de cadastro) |
| `GET /listar/{identificador}` | detalhe + `cobrancas_recentes[]` |
| `POST /cadastrar` | **confirmar** (marca `PAGAMENTO_SERVICOS`) |
| `PUT /editar` | `acao`: `confirmar` \| `ignorar` \| `restaurar` \| `desfazer_confirmacao` |
| `DELETE /excluir/{identificador}` | **ignorar** |
| `GET /assinaturas-list` | async (`id` = identificador, `nome` = título) |

Bearer Sanctum. Isolado por `user_id`.

### Query de `/listar`

| Param | Default | Obs |
|-------|---------|-----|
| `status` | `todas` | `todas` \| `confirmada` \| `candidata` \| `ignorada` |
| `periodicidade` | — | valor do lookup |
| `palavra_chave` | — | título / loja / estabelecimento |
| `ordenar` | `anual_desc` | ver lookups |
| `cartao_id` / `responsavel_id` / `categoria_id` | — | filtra as cobranças **antes** de detectar |
| `incluir_ignoradas` | `0` | `status=ignorada` já inclui |

`totais` **sempre** considera confirmadas + candidatas (ignora as ignoradas), mesmo se a lista estiver filtrada. O card “gasto no ano” não muda ao trocar a aba.

### Resposta `/listar`

```json
{
  "data": {
    "referencia": { "hoje": "2026-08-24" },
    "ordenar_aplicada": "anual_desc",
    "status_aplicado": "todas",
    "totais": {
      "assinaturas": 5,
      "confirmadas": 3,
      "candidatas": 2,
      "gasto_12_meses": 1840.5,
      "estimativa_mensal": 210.4,
      "estimativa_anual": 2524.8,
      "gasto_12_meses_confirmadas": 1200.0,
      "estimativa_anual_confirmadas": 1800.0,
      "estimativa_anual_candidatas": 724.8
    },
    "itens": []
  },
  "status": true,
  "message": "Assinaturas carregadas com sucesso!"
}
```

Campos por item: `identificador`, `titulo`, `status`, `periodicidade`, `confianca` (+ labels), `cobrancas`, `cobrancas_confirmadas`, `cobrancas_pendentes`, `valor_medio`, `valor_ultima`, `gasto_12_meses`, `estimativa_mensal`, `estimativa_anual`, `primeira_cobranca`, `ultima_cobranca`, `proxima_estimada`, `loja_*`, `estabelecimento_*`, `estabelecimentos[]`, `categoria_*`, `responsavel_*`, `origem_compra_predominante`, `ignorada`, `periodicidade_assumida`.

### Confirmar

```json
{ "identificador": "estabelecimento-45" }
```

Aceita também `loja_id` ou `estabelecimento_id`. Atualiza todas as compras à vista do grupo para `PAGAMENTO_SERVICOS` e tira de ignoradas. Resposta inclui `transacoes_afetadas`.

### Editar

```json
{ "identificador": "loja-12", "acao": "ignorar" }
```

`desfazer_confirmacao` zera `origem_compra` (volta `null`) só nas linhas que estavam `PAGAMENTO_SERVICOS`. O usuário pode recategorizar na tela de compra.

Prompt do front: [`docs/frontend-prompt-assinaturas.md`](../frontend-prompt-assinaturas.md).
