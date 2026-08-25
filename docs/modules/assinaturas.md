# Especificação — Assinaturas (detector de cobranças recorrentes)

Identifica automaticamente compras à vista que se repetem e permite **marcar na mão** (`transacoes.eh_assinatura`).

Na listagem a API **separa**:

- `data.assinaturas` — lista **oficial** (pelo menos uma cobrança com `eh_assinatura = true`)
- `data.candidatas` — sugestões do detector, **fora** da oficial, até o usuário clicar em Confirmar

## Conceito

| Estado | Significado |
|--------|-------------|
| **Candidata** | Padrão recorrente detectado. **Nenhuma** cobrança com `eh_assinatura`. Precisa do botão Confirmar. |
| **Confirmada (oficial)** | Alguma cobrança do grupo tem `eh_assinatura = true` (form da compra, `PUT /transacoes/editar`, ou Confirmar nesta tela). |
| **Ignorada** | Usuário disse que não é assinatura. Some das candidatas. |

Confirmar grava `eh_assinatura = true` e `origem_compra = PAGAMENTO_SERVICOS` nas à vista do grupo.

Marcar uma compra: `PUT /transacoes/editar` `{ id, eh_assinatura: true }` ou `POST /assinaturas/cadastrar` `{ transacao_id }`. Entra na oficial mesmo com 1 cobrança.

No **create** de transação, se `origem_compra = PAGAMENTO_SERVICOS` e `eh_assinatura` não vier, o backend assume `true`.

Parceladas não entram. `PAGAMENTO_FATURA` também não.

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

**Confirmada** com 1 cobrança já com `eh_assinatura`: assume periodicidade **mensal** (`periodicidade_assumida: true`) para não subestimar o anual.

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

`totais.estimativa_anual` = **só oficiais**. `pendentes_confirmacao` = qtd de candidatas.

### Resposta `/listar`

```json
{
  "data": {
    "totais": {
      "assinaturas": 3,
      "confirmadas": 3,
      "candidatas": 2,
      "pendentes_confirmacao": 2,
      "estimativa_anual": 1800.0,
      "estimativa_anual_candidatas": 724.8
    },
    "assinaturas": [],
    "candidatas": [],
    "itens": [],
    "ignoradas": []
  }
}
```

Default: `assinaturas` = oficiais, `candidatas` = para confirmar, `itens` = oficiais (atalho).  
Item: além dos campos anteriores, `pode_confirmar`, `acoes_disponiveis`.

### Confirmar

```json
{ "identificador": "estabelecimento-45" }
```

Ou uma compra: `{ "transacao_id": 123 }`.

Aceita `loja_id` / `estabelecimento_id`. Marca `eh_assinatura` + `PAGAMENTO_SERVICOS` nas à vista do grupo.

### Editar

```json
{ "identificador": "loja-12", "acao": "ignorar" }
```

`desfazer_confirmacao` zera `eh_assinatura` (a origem da compra não é limpa).

Prompt do front: [`docs/frontend-prompt-assinaturas.md`](../frontend-prompt-assinaturas.md).
