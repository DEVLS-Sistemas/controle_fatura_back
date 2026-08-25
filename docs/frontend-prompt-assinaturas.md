# Prompt — Frontend: Detector de assinaturas

Use este prompt no repositório do frontend para criar a tela de **assinaturas** (cobranças recorrentes) alinhada à API do `controle_fatura_back`.

---

## Objetivo

Responder:

- Quais cobranças parecem **assinatura** (Netflix, Spotify, Google, sistemas, etc.)?
- **Quanto gasto por ano** (e por mês) com isso?
- Quais ainda não foram classificadas como **pagamento de serviços**?

A tela **detecta**; não é um CRUD de “planos” digitados na mão.

---

## Conceito de produto

Cada item é um **grupo de compras à vista** do mesmo estabelecimento (maquininha) ou da mesma **loja** (nome fantasia), quando os valores são parecidos.

| Status | Badge | O que fazer |
|--------|-------|-------------|
| `candidata` | “Possível assinatura” | Confirmar ou ignorar |
| `confirmada` | “Pagamento de serviços” | Só acompanhar; opcional desfazer |
| `ignorada` | “Ignorada” | Só na aba Ignoradas; restaurar se errou |

**Confirmar** grava `origem_compra = PAGAMENTO_SERVICOS` em todas as compras à vista daquele grupo (mesmo valor usado no formulário de compra). Não chama `PUT /transacoes/editar` uma a uma.

Parceladas **não aparecem** aqui (vão no ranking de parceladas).

Clique na cobrança do detalhe abre a [visualização da compra](frontend-prompt-visualizacao-compra.md) (`GET /transacoes/visualizar/{id}`).

---

## Menu / rota

Sugestão: item de menu **Assinaturas** (junto de Compras / Parceladas / Dashboard).  
Rota: `/assinaturas`  
Detalhe: `/assinaturas/{identificador}` (ex.: `estabelecimento-45` ou `loja-12`) — página ou drawer.

---

## APIs (Bearer Sanctum)

Base: `/api/v1/assinaturas`

```http
GET  /api/v1/assinaturas/lookups
GET  /api/v1/assinaturas/listar
GET  /api/v1/assinaturas/listar/{identificador}
POST /api/v1/assinaturas/cadastrar
PUT  /api/v1/assinaturas/editar
DELETE /api/v1/assinaturas/excluir/{identificador}
GET  /api/v1/assinaturas/assinaturas-list?palavra_chave=
```

`identificador` é string (`loja-12`, `estabelecimento-45`) — **não** é id numérico. Usar nas rotas da API e nas rotas do front **sem** parseInt.

### Lookups

Usar `value` / `label` de `status`, `periodicidades`, `confiancas`, `acoes`, `ordenar`.  
`origem_confirmacao` = `{ value: "PAGAMENTO_SERVICOS", label: "Pagamento de serviços" }` (texto da confirmação).

### Listagem

```http
GET /api/v1/assinaturas/listar?status=todas&ordenar=anual_desc
```

| Query | Default | Uso na UI |
|-------|---------|-----------|
| `status` | `todas` | Abas: Todas / Candidatas (`candidata`) / Confirmadas (`confirmada`) / Ignoradas (`ignorada`) |
| `periodicidade` | — | filtro opcional |
| `palavra_chave` | — | busca |
| `ordenar` | `anual_desc` | select |
| `cartao_id`, `responsavel_id`, `categoria_id` | — | filtros extras |

**Não paginar no front.** A API devolve a lista completa em `data.itens`. Não reordenar — respeitar `ordenar_aplicada`.

`data.totais` é o gasto **de todas as confirmadas + candidatas**, mesmo com aba filtrada. O card “por ano” **não** muda ao clicar em Candidatas.

### Exemplo resumido da resposta

```json
{
  "data": {
    "referencia": { "hoje": "2026-08-24" },
    "ordenar_aplicada": "anual_desc",
    "status_aplicado": "todas",
    "totais": {
      "assinaturas": 4,
      "confirmadas": 2,
      "candidatas": 2,
      "gasto_12_meses": 980.4,
      "estimativa_mensal": 112.45,
      "estimativa_anual": 1349.4,
      "estimativa_anual_confirmadas": 670.8,
      "estimativa_anual_candidatas": 678.6
    },
    "itens": [
      {
        "identificador": "estabelecimento-45",
        "titulo": "Netflix",
        "status": "candidata",
        "status_label": "Candidata",
        "periodicidade": "mensal",
        "periodicidade_label": "Mensal",
        "periodicidade_assumida": false,
        "confianca": "alta",
        "confianca_label": "Alta",
        "cobrancas": 8,
        "cobrancas_confirmadas": 0,
        "cobrancas_pendentes": 8,
        "valor_medio": 55.9,
        "valor_ultima": 55.9,
        "gasto_12_meses": 447.2,
        "estimativa_mensal": 55.9,
        "estimativa_anual": 670.8,
        "primeira_cobranca": "2025-12-10",
        "ultima_cobranca": "2026-07-10",
        "proxima_estimada": "2026-08-09",
        "loja_id": 3,
        "loja_nome": "Netflix",
        "estabelecimento_id": 45,
        "estabelecimento_nome": "NETFLIX.COM",
        "categoria_nome": "Lazer",
        "categoria_cor": "#8b5cf6",
        "origem_compra_predominante": "COMPRAS_ONLINE",
        "ignorada": false
      }
    ]
  },
  "status": true
}
```

Valores monetários já vêm number (não string BR). Formatá-los no front (`R$ 670,80`).

### Confirmar (obrigatório)

```http
POST /api/v1/assinaturas/cadastrar
```

```json
{ "identificador": "estabelecimento-45" }
```

Equivalente: `PUT /editar` com `{ "identificador": "…", "acao": "confirmar" }`.

Depois do sucesso: refetch da listagem (e do detalhe se estiver aberto). Atualizar `origem_compra` nas linhas de compra se a tela de transações estiver em cache.

### Ignorar / restaurar / desfazer

```http
PUT /api/v1/assinaturas/editar
```

```json
{ "identificador": "estabelecimento-45", "acao": "ignorar" }
```

| `acao` | Efeito |
|--------|--------|
| `confirmar` | marca cobranças como pagamento de serviços |
| `ignorar` | some da lista principal (`DELETE /excluir/{identificador}` faz o mesmo) |
| `restaurar` | volta a aparecer (só faz sentido na aba Ignoradas) |
| `desfazer_confirmacao` | limpa `origem_compra` das que estavam como serviço |

Pedir confirmação em **desfazer_confirmacao** (“As cobranças deixam de ser pagamento de serviços”).

---

## UX da tela

### 1) Hero (sempre visível)

Três números de `data.totais`:

1. **Por ano** — `estimativa_anual` (destaque principal)
2. **Por mês** — `estimativa_mensal`
3. **Assinaturas** — `assinaturas` · texto secundário `{confirmadas} confirmadas · {candidatas} a revisar`

Opcional: linha “Nos últimos 12 meses você já pagou `gasto_12_meses`” (realizado vs estimado).

Se `candidatas > 0`, chip/CTA “{n} para revisar” foca a aba Candidatas.

### 2) Filtros

- Busca (`palavra_chave`)
- Ordenar (labels de `lookups.ordenar`)
- Periodicidade (opcional)
- Cartão / responsável / categoria (opcional, mesmo espírito das outras listagens)

Abas de status **acima** da lista. Trocar aba = novo GET com `status=`.

### 3) Lista (cards)

Por item:

- Título (`titulo`) — se houver `loja_nome` e `estabelecimento_nome` diferentes, subtítulo com a maquininha
- Badge periodicidade (`periodicidade_label`) + badge status
- `R$ {estimativa_anual}/ano` · `R$ {valor_medio}/cobrança`
- “Última: {ultima_cobranca}” · se `proxima_estimada`: “Próxima (est.): {proxima_estimada}”
- `{cobrancas} cobranças` — se `cobrancas_pendentes > 0` e status candidata: “{n} ainda sem origem de serviço”
- Confiança discreta (`confianca_label`) — não precisa ser o elemento principal
- Se `periodicidade_assumida`: tooltip “Assumimos mensal porque só há 1 cobrança já marcada como serviço”

Ações no card:

| Status | Primária | Secundária |
|--------|----------|------------|
| candidata | **Confirmar** | Ignorar · Ver cobranças |
| confirmada | Ver cobranças | Desfazer (menu) |
| ignorada | **Restaurar** | — |

Card inteiro clicável → detalhe. Botões com `stopPropagation`.

Empty:

- Nenhuma transação recorrente: “Ainda não encontramos assinaturas. Elas aparecem quando a mesma cobrança se repete (ex.: Netflix todo mês).”
- Aba candidatas vazia: “Nada para revisar.”
- Aba ignoradas vazia: “Você não ignorou nenhuma.”

### 4) Detalhe

`GET /listar/{identificador}`

Além dos campos da lista:

- `estabelecimentos[]` se o grupo for uma loja com várias maquininhas
- Categoria / responsável (chips)
- Timeline simples: primeira → última
- Tabela **Cobranças recentes** (`cobrancas_recentes[]`, máx. 24, mais nova primeiro):

| Coluna | Campo |
|--------|--------|
| Data | `data` |
| Valor | `valor` |
| Origem | `origem_compra_label` (vazio = “Sem origem”) |
| Estabelecimento | `estabelecimento_nome` |
| Fatura | `fatura_mes`/`fatura_ano` |

Linha clicável → `/compras/{id}` (visualizar transação).  
Badge na linha se `confirmada: true`.

Mesmos botões Confirmar / Ignorar / Restaurar / Desfazer do card.

### 5) Relação com a tela de Compra

Não mudar o formulário de nova compra. Só garantir:

- `origem_compra = PAGAMENTO_SERVICOS` continua sendo “Pagamento de serviços” (assinatura / débito automático) — já está no prompt de compras
- Depois de **Confirmar** nesta tela, a listagem de transações daquele estabelecimento deve mostrar o badge de origem atualizado (invalidar cache / refetch)

Atalho opcional (não obrigatório): na listagem de compras, filtro `origem_compra=PAGAMENTO_SERVICOS` e link “Ver assinaturas”.

---

## Checklist de aceite

- [ ] Tela nova no menu (não só um card no dashboard)
- [ ] Hero com gasto **anual**, mensal e quantidade
- [ ] Lista de assinaturas encontradas (candidatas + confirmadas)
- [ ] Confirmar marca as compras como **pagamento de serviços** (`POST /cadastrar` ou `PUT /editar`)
- [ ] Ignorar esconde falso positivo; restaurar na aba Ignoradas
- [ ] Identificador string na URL (`loja-12` / `estabelecimento-45`)
- [ ] Totais do hero **não** mudam ao filtrar a aba (usar `data.totais` da resposta completa / `status=todas` se precisar)
- [ ] Clique na cobrança abre visualização da compra
- [ ] Empty / loading / erro / responsivo
- [ ] Não enviar `parcela` / não misturar com ranking de parceladas

---

## Fora de escopo

- Cadastrar assinatura futura que ainda não caiu na fatura
- Cancelar a assinatura no Netflix/Spotify
- Editar valor da cobrança nesta tela (isso é edição de transação)

---

## Backend (já implementado)

```http
GET /api/v1/assinaturas/listar
```

Spec: [`docs/modules/assinaturas.md`](modules/assinaturas.md)  
Origem na compra: [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
