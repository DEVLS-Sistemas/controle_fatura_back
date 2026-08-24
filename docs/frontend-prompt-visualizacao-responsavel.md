# Prompt — Frontend: Visualização do Responsável

Use este prompt no repositório do frontend para implementar a tela de **visualizar responsável** — um hub somente leitura com **contadores (qtd + valor)** e atalhos para tudo que se relaciona a essa pessoa/empresa.

Backend já implementado.

Specs: [`modules/responsaveis.md`](modules/responsaveis.md) · complementa [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md), [`frontend-prompt-repasses-responsavel.md`](frontend-prompt-repasses-responsavel.md), [`frontend-prompt-visualizacao-compra.md`](frontend-prompt-visualizacao-compra.md), [`frontend-prompt-ranking-parceladas.md`](frontend-prompt-ranking-parceladas.md).

---

## Objetivo

Na tela de detalhe do responsável o usuário precisa ver, sem montar a conta no front:

| Pergunta | Bloco da API |
|----------|----------------|
| Quantas compras já fez? Quanto gastou? | `totais` |
| Quantas ainda estão em aberto (parcelas futuras)? | `em_aberto` |
| O que ainda tem a me pagar (repasse)? | `repasse` |
| Quanto cai **neste mês** (fatura virtual)? | `competencia` + atalho **Fatura do responsável** |
| Em quais cartões / categorias? | `por_cartao`, `por_categoria` |
| Abrir a fatura de verdade do cartão | `por_cartao[].fatura_id` / `faturas[]` |
| Compras recentes e parceladas ativas | `compras_recentes`, `parceladas_abertas` |
| É o padrão de alguma fatura de outro titular? | `pessoa`, `faturas_padrao` |

Não é tela de edição (editar continua no CRUD de Responsáveis).

---

## Conceitos (não misturar)

| Conceito | Significado | Onde |
|----------|-------------|------|
| **Responsável** | Quem **deve** a compra | esta tela |
| **Pessoa** | Titular do cartão/fatura | `pessoa` (pode ser null) |
| **Fatura do cartão** | 1 bandeira × competência | `por_cartao[].fatura_id` → tela de faturas |
| **Fatura do responsável** | 1 responsável × competência, **todos** os cartões | atalho `fatura_responsavel` |
| **Em aberto (parcelas)** | Ainda vai cair na fatura (`competência da parcela > referência`) | `em_aberto` — igual ao ranking |
| **A receber (repasse)** | O responsável ainda não devolveu o valor | `repasse` — igual à matriz de repasses |

Parcelado conta **1 compra** (`compra_grupo_id`). À vista = 1 linha. `ocorrencias` = lançamentos nas faturas (12 parcelas = 12 ocorrências, 1 compra).

---

## Entrada / rotas

Rota sugerida:

```
/responsaveis/:id
/responsaveis/:id?mes=8&ano=2026
```

Origens do clique (tornar o card/nome clicável):

| Origem | Params |
|--------|--------|
| Lista de Responsáveis | `id` |
| Projeção (linha do responsável) | `id` + `mes`/`ano` da referência |
| Fatura do responsável / repasses (voltar) | preservar `mes`/`ano` |
| Compra (`responsavel` no detalhe) | `id` |
| Pessoa (se tiver `responsavel_id`) | `id` |

Query `mes`/`ano` = competência de referência (default: mês atual). Afeta **em aberto**, **competência**, **repasse na referência** e os atalhos. **Não** filtra o histórico (`totais`).

---

## API (Bearer Sanctum)

```http
GET /api/v1/responsaveis/visualizar/{id}?mes=8&ano=2026
```

404 `{ "error": true, "message": "Responsável não encontrado" }` se não for do usuário.

`GET /responsaveis/listar/{id}` continua só com cadastro (`id`, `nome`, `tipo`, `ativo`) — **não** usar na tela de visualizar.

Envelope:

```json
{
  "data": { },
  "status": true,
  "message": "Responsável carregado com sucesso!"
}
```

### Query

| Param | Default | Uso |
|-------|---------|-----|
| `mes` / `ano` | atual | Referência para aberto / competência / atalhos |

---

## Shape de `data`

```json
{
  "id": 15,
  "nome": "Maysa Araujo da Conceicao",
  "tipo": "pessoal",
  "tipo_label": "Pessoal",
  "ativo": true,
  "eh_eu": false,
  "referencia": { "mes": 8, "ano": 2026, "label": "Ago/2026" },
  "pessoa": {
    "id": 2,
    "nome": "Maysa",
    "sobrenome": "Araujo da Conceicao",
    "nome_completo": "Maysa Araujo da Conceicao",
    "responsavel_id": 15,
    "eh_principal": false,
    "ativo": true
  },
  "totais": {
    "compras": 42,
    "ocorrencias": 68,
    "avista": 30,
    "parceladas": 12,
    "valor_total": 12500.4,
    "ticket_medio": 297.63,
    "primeira_compra": "2025-03-10",
    "ultima_compra": "2026-08-12"
  },
  "em_aberto": {
    "compras": 5,
    "parcelas_restantes": 28,
    "valor_total": 4800.0,
    "valor_pago": 700.0,
    "valor_aberto": 4100.0,
    "percentual_pago": 14.58
  },
  "repasse": {
    "valor_total_compras": 8000.0,
    "valor_pago": 3200.0,
    "valor_aberto": 4800.0,
    "compras_abertas": 8,
    "compras_pagas": 34,
    "parcelas_pendentes_na_referencia": 3,
    "valor_aberto_na_referencia": 450.0
  },
  "competencia": {
    "mes": 8,
    "ano": 2026,
    "label": "Ago/2026",
    "compras": 6,
    "ocorrencias": 8,
    "valor_total": 1250.4
  },
  "por_cartao": [
    {
      "cartao_id": 2,
      "cartao_nome": "Nubank",
      "cor_fundo": "#820ad1",
      "cor_texto": "#ffffff",
      "compras": 4,
      "ocorrencias": 6,
      "valor_total": 800.1,
      "fatura_id": 88,
      "faturas": [
        {
          "id": 88,
          "cartao_bandeira_id": 4,
          "bandeira": "Mastercard",
          "valor_total": 800.1,
          "ocorrencias": 6
        }
      ]
    }
  ],
  "por_categoria": [
    {
      "categoria_id": 1,
      "nome": "Casa",
      "cor": "#22c55e",
      "compras": 10,
      "valor_total": 3500.0
    }
  ],
  "faturas_padrao": [
    {
      "id": 88,
      "mes": 8,
      "ano": 2026,
      "competencia": "08/2026",
      "label": "Ago/2026",
      "valor_total": 1500.0,
      "status": "processada",
      "cartao_id": 2,
      "cartao_nome": "Nubank",
      "cartao_cor_fundo": "#820ad1",
      "cartao_cor_texto": "#ffffff",
      "pessoa_id": 2,
      "pessoa_nome": "Maysa Araujo da Conceicao"
    }
  ],
  "compras_recentes": [
    {
      "identificador": "uuid-ou-id",
      "compra_grupo_id": "uuid-ou-null",
      "transacao_id": 101,
      "titulo": "Geladeira Frost Free 400L",
      "data": "2026-08-10",
      "valor": 291.67,
      "valor_total": 3500.0,
      "parcelas_total": 12,
      "avista": false,
      "estabelecimento": "Magazine",
      "cartao_nome": "Nubank",
      "fatura_id": 88,
      "fatura_mes": 8,
      "fatura_ano": 2026
    }
  ],
  "parceladas_abertas": [],
  "atalhos": {
    "fatura_responsavel": { "responsavel_id": 15, "mes": 8, "ano": 2026 },
    "repasses": { "responsavel_id": 15, "mes": 8, "ano": 2026 },
    "ranking_parceladas": {
      "responsavel_id": 15,
      "mes": 8,
      "ano": 2026,
      "apenas_abertas": 1
    },
    "compras": { "responsavel_id": 15, "tipo": "purchase" }
  }
}
```

`pessoa` vem `null` se o responsável não estiver ligado a um titular (caso típico de `Eu` / `Empresa` cadastrados na mão).

`por_cartao` e `competencia` são **só da competência de referência**. `totais` e `por_categoria` são **histórico completo**.

`parceladas_abertas[]` reusa o shape do ranking (título, parcelas, valores, cartão, `identificador` = `compra_grupo_id`). Preview: até **5**, maior `valor_aberto` primeiro. Lista completa: atalho `ranking_parceladas`.

`compras_recentes[]`: até **8**. Clique → `GET /transacoes/visualizar/{identificador}` (ver prompt da compra). `valor` = parcela de referência; `valor_total` = soma do grupo.

---

## UX / Tela

### Cabeçalho

1. **Voltar** (histórico; fallback `/responsaveis`)
2. Título = `nome`
3. Chip `tipo_label` (`pessoal` / `empresa`)
4. Badge **Você** se `eh_eu`
5. Badge inativo se `ativo === false`
6. Se `pessoa`: linha “Titular: {pessoa.nome_completo}” clicável → `/pessoas/{pessoa.id}` (ou drawer da pessoa)
7. Seletor **competência** (mês/ano) — ao mudar, refetch com a query. Mostrar `referencia.label`

Ação secundária: **Editar** → form já existente do CRUD (`/responsaveis/:id/editar` ou modal). Não misturar edição nesta tela.

### Contadores (obrigatório)

Quatro cards no topo. Sempre mostrar **quantidade + valor** (moeda BRL). Card clicável quando houver atalho.

| Card | Qtd | Valor | Clique |
|------|-----|-------|--------|
| **Compras** | `totais.compras` | `totais.valor_total` | listagem `/transacoes?responsavel_id=` (`atalhos.compras`) |
| **Em aberto** | `em_aberto.compras` + sub `em_aberto.parcelas_restantes` parcelas | `em_aberto.valor_aberto` | ranking (`atalhos.ranking_parceladas`) |
| **A receber** | `repasse.compras_abertas` | `repasse.valor_aberto` | matriz de repasses (`atalhos.repasses`) |
| **Neste mês** | `competencia.compras` | `competencia.valor_total` | **Fatura do responsável** (`atalhos.fatura_responsavel`) |

Subtextos sugeridos:

- Compras: `{totais.avista} à vista · {totais.parceladas} parceladas · ticket {totais.ticket_medio}`
- Em aberto: `{em_aberto.percentual_pago}% já caiu na fatura`
- A receber: `R$ {repasse.valor_aberto_na_referencia} nesta competência`
- Neste mês: `{competencia.label}` · `{competencia.ocorrencias} lançamentos`

Se `eh_eu`, o card **A receber** pode ficar secundário/oculto (você não “se paga”). Continuar mostrando **Em aberto** (parcelas futuras).

Empty: `totais.compras === 0` → “Nenhuma compra deste responsável.” Manter o cadastro visível.

### Atalhos (barra)

Botões/links usando `atalhos` (não inventar query):

1. **Fatura do responsável** (primário) → tela já especificada em [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md)

```
/projecao/responsaveis/:id/fatura?mes={mes}&ano={ano}
```

2. **Controle de repasses** → [`frontend-prompt-repasses-responsavel.md`](frontend-prompt-repasses-responsavel.md)
3. **Parceladas em aberto** → ranking com `responsavel_id`
4. **Todas as compras** → listagem de transações filtrada

Navegação competência **anterior / próxima** no header (igual fatura do responsável): altera `mes`/`ano` e refetcha o visualizar.

### Por cartão (competência)

Lista/chips da competência atual. Chip com `cor_fundo` / `cor_texto`.

Cada linha:

```
[chip Nubank]   4 compras   R$ 800,10    → Abrir fatura do cartão
```

Clique no grupo → `/faturas/{fatura_id}` (`por_cartao[].fatura_id`, ou a única de `faturas[]`).

Se `faturas.length > 1` (mais de uma bandeira no mesmo grupo): um link por bandeira.

Empty: “Nenhuma compra deste responsável em {competencia.label}.” + CTA fatura do responsável mesmo assim.

### Por categoria (histórico)

Barras ou lista: pill com `cor` + `nome` + qtd + valor. `categoria_id === null` → “Sem categoria”.

Clique opcional → compras filtradas `responsavel_id` + `categoria_id`.

### Parceladas em aberto (preview)

Lista compacta (máx. 5):

| Coluna | Campo |
|--------|--------|
| Título | `titulo` |
| Progresso | `{parcela_atual}/{parcelas_total}` + barra `percentual_pago` |
| Aberto | `valor_aberto` |
| Termina | `estimativa_termino` |
| Cartão | chip |

Clique na linha → `/compras/{identificador}?mes=&ano=` ([visualização da compra](frontend-prompt-visualizacao-compra.md)).

“Ver todas” se `em_aberto.compras > parceladas_abertas.length`.

### Compras recentes

Lista (máx. 8): data, título, estabelecimento, chip do cartão, valor (`avista` ? `valor` : `{valor} · {parcelas_total}x`), link fatura se `fatura_id`.

Clique → visualizar compra com `identificador`.

### Faturas em que é o padrão

Só renderizar se `faturas_padrao.length > 0` (típico de outro titular, ex. Maysa).

Texto de ajuda:

> Este responsável é o **padrão** destas faturas (compras importadas já saem no nome dele). Continua diferente da fatura virtual (todos os cartões).

Cada item: chip do cartão + `label` + `pessoa_nome` + `valor_total` + status → `/faturas/{id}`.

### Estados

- Loading: skeleton header + 4 cards + 2 listas
- 404: “Responsável não encontrado” + voltar
- Responsivo: cards em 2×2 no mobile; listas viram cards

---

## O que **não** fazer

- Somar `/transacoes/listar` no front para os totais — usar este endpoint
- Tratar `em_aberto` e `repasse` como a mesma coisa
- Usar `GET /faturas/listar/{id}` como “fatura do responsável” (isso é bandeira)
- Pedir cadastro de responsável nesta tela
- Esconder o responsável `Eu`

---

## Checklist de aceite

- [ ] Rota `/responsaveis/:id` com `mes`/`ano` opcionais
- [ ] `GET /api/v1/responsaveis/visualizar/{id}`
- [ ] 4 cards: compras, em aberto, a receber, neste mês — **qtd + valor**
- [ ] Card “neste mês” abre a **fatura do responsável**
- [ ] Por cartão: link para a fatura real (`fatura_id`)
- [ ] Preview de parceladas e compras recentes abre a visualização da compra
- [ ] Atalhos: fatura do responsável, repasses, ranking, todas as compras
- [ ] Pessoa vinculada visível quando `pessoa !== null`
- [ ] Bloco `faturas_padrao` só se houver itens
- [ ] Trocar competência refetcha a tela
- [ ] Empty / 404 / loading / mobile
- [ ] Clique a partir da listagem de responsáveis (e, se já existir, da Projeção)

---

## Fora de escopo

- Editar compras, registrar repasse ou processar PDF nesta tela
- Filtro de período tipo estabelecimento (`data_inicio` / `data_fim`) — histórico é total; competência é o seletor de mês
- Totais na **listagem** de responsáveis (só nesta tela de visualizar)
