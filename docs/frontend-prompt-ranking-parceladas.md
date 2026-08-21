# Prompt — Frontend: Ranking de Compras Parceladas

Use este prompt no repositório do frontend para implementar a tela de **ranking / acompanhamento de compras parceladas**.

---

## Objetivo

Permitir que o usuário veja **compras parceladas ativas**, em forma de **lista tipo ranking**, e opcionalmente em **visão por competências (meses)** para estimar **quando cada compra acaba**.

Perguntas que a tela responde:

- Quais compras ainda estão parceladas?
- Quais vão demorar mais / têm maior valor em aberto?
- Quantas parcelas faltam? Quanto pago vs aberto?
- **Quando termina?** (mês da última parcela)
- Como o progresso se encaixa no calendário de competências?

---

## Conceito de produto

No backend, uma compra parcelada são N linhas em `transacoes` com o mesmo `compra_grupo_id`.

- Título: **`observacoes`** se existir; senão **estabelecimento**
- À vista não entra
- Ativa na referência enquanto `ultima_parcela` ≥ mês filtrado (última no mês atual **aparece**; no mês anterior **some**)

### Ordenação (sempre)

1. Compras **não quitadas** primeiro; **`quitada: true` (100%) sempre no final**
2. Depois, no default (`restantes_desc`):
   - mais `parcelas_restantes`
   - maior `valor_aberto`
   - menor `percentual_pago`

---

## API

```http
GET /api/v1/dashboard/ranking-parceladas?mes=8&ano=2026
Authorization: Bearer {token}
```

### Query params

| Param | Default | Descrição |
|-------|---------|-----------|
| `mes` / `ano` | atual | Competência de referência (**centro** da janela de 13 meses) |
| `cartao_id`, `responsavel_id`, `categoria_id` | — | Filtros |
| `apenas_abertas` | `1` | Ativas na ref (última ≥ mês); `0` inclui encerradas antes |
| `ordenar` | `restantes_desc` | Ver tabela |
| `palavra_chave` | — | Observação / estabelecimento |

### Ordenações

| Valor | Comportamento (após empurrar `quitada` para o fim) |
|-------|-----------------------------------------------------|
| `restantes_desc` | **Default.** restantes ↓ → valor aberto ↓ → % pago ↑ |
| `restantes_asc` | restantes ↑ |
| `percentual_asc` / `percentual_desc` | por % |
| `valor_aberto_desc` | maior aberto |
| `data_compra_desc` | compra mais recente |

### Resposta (`data`) — campos novos importantes

```json
{
  "referencia": { "mes": 8, "ano": 2026 },
  "colunas": [
    { "mes": 2, "ano": 2026, "chave": "2026-02", "label": "Fev/2026", "centro": false, "indice": 0 },
    { "mes": 8, "ano": 2026, "chave": "2026-08", "label": "Ago/2026", "centro": true, "indice": 6 },
    { "mes": 2, "ano": 2027, "chave": "2027-02", "label": "Fev/2027", "centro": false, "indice": 12 }
  ],
  "totais": {
    "compras": 2,
    "valor_total": 4800.0,
    "valor_pago": 700.0,
    "valor_aberto": 4100.0,
    "percentual_pago": 14.58
  },
  "itens": [
    {
      "compra_grupo_id": "…",
      "titulo": "Geladeira Frost Free 400L",
      "titulo_origem": "observacoes",
      "parcelas_total": 12,
      "parcela_atual": 1,
      "parcelas_pagas": 1,
      "parcelas_restantes": 11,
      "valor_pago": 291.67,
      "valor_aberto": 3208.33,
      "valor_total": 3500.0,
      "percentual_pago": 8.33,
      "quitada": false,
      "estimativa_termino": "Jul/2027",
      "primeira_parcela": { "parcela_atual": 1, "mes": 8, "ano": 2026, "valor": 291.67, "fatura_id": 80 },
      "competencia_atual": { "parcela_atual": 1, "mes": 8, "ano": 2026, "valor": 291.67, "fatura_id": 80 },
      "ultima_parcela": { "parcela_atual": 12, "mes": 7, "ano": 2027, "valor": 291.63, "fatura_id": 100 },
      "proxima_parcela": { "parcela_atual": 2, "mes": 9, "ano": 2026, "valor": 291.67, "fatura_id": 88 },
      "timeline": {
        "inicio_chave": "2026-08",
        "fim_chave": "2027-07",
        "progresso_chave": "2026-08",
        "indice_inicio": 6,
        "indice_fim": 12,
        "indice_progresso": 6,
        "fora_da_janela": false
      },
      "cartao_cor_fundo": "#8b5cf6",
      "cartao_cor_texto": "#ffffff"
    }
  ]
}
```

### `colunas` (janela de 13 meses)

- Sempre **13** competências
- Mês filtrado (`mes`/`ano`) é sempre o **centro** (`centro: true`, `indice: 6`)
- 6 meses antes + centro + 6 depois
- Ao clicar **anterior / próximo** no front: alterar `mes`/`ano` e **refazer o GET** (a API recentraliza)

### `timeline` (por item)

Usar na visão por competências para desenhar a barra:

| Campo | Uso na UI |
|-------|-----------|
| `indice_inicio` … `indice_fim` | Trecho **cinza** (duração total da compra na grade) |
| `indice_inicio` … `indice_progresso` | Trecho **azul** (status já alcançado) |
| `fora_da_janela` | `true` se não há overlap com a janela → não desenhar barra (ou mostrar só texto) |
| `estimativa_termino` | Texto “Termina em Jul/2027” |

Índices já vêm **clipados** na janela (0–12) quando o período atravessa a borda.

### Quitada

- `quitada: true` quando `percentual_pago >= 100` (ou sem aberto e sem restantes)
- **Sempre no final** da lista, em qualquer `ordenar`
- Na timeline: barra azul preenche o cinza inteiro (`indice_progresso === indice_fim`)

---

## UX / Tela

### Menu / rota

Sugestão: **Dashboard / Relatórios → Parceladas**  
Rota: `/parceladas` ou `/dashboard/parceladas`

### Controles comuns (ambas as visões)

1. Título: “Compras parceladas”
2. Filtros: cartão, responsável, categoria, busca, ordenação
3. Resumo: qtd, pago, aberto, % global
4. **Toggle de visualização** (obrigatório):
   - **Lista** (padrão) — tela atual / cards
   - **Por competências** — grade de meses + barra início/fim

Persistir a escolha em `localStorage` (ex.: `ranking_parceladas_view = lista | competencias`).

---

### Visão A — Lista (padrão)

Mantém a UX de ranking em cards:

- Posição, título, estabelecimento (se título veio de observação)
- Badge `parcela_atual/parcelas_total`, “Faltam X”
- Pago / aberto / total
- **Progress bar simples** (0–100%) — pode continuar 1 cor
- Texto: **Termina em {estimativa_termino}**
- Quitadas: badge “Quitada” / “100%” e visualmente no fim da lista

---

### Visão B — Por competências

Ao clicar no botão **“Por competências”**:

#### Navegação da janela (13 meses)

```
[ ← ]   Fev/2026 … [ Ago/2026 ] … Fev/2027   [ → ]
```

- Cabeçalho com as **13 colunas** de `data.colunas` (`label`)
- Coluna central (`centro: true`) destacada (borda/fundo leve)
- **←** = referência − 1 mês → `GET` com novo `mes`/`ano`
- **→** = referência + 1 mês → `GET`
- Opcional: botão “Hoje” volta para mês/ano atuais

Não tentar scroll infinito de dezenas de meses: sempre só os 13 da API.

#### Linha por compra

Layout sugerido (desktop): sticky à esquerda com info da compra; à direita a grade alinhada às colunas.

```
| Geladeira · 1/12 · Termina Jul/2027 |  · · · · · · [████▒▒▒▒▒▒▒] · · |
| Impressora · 2/12 · Termina Jun/2027|  · · · · · [██████▒▒▒▒▒] · · · |
```

#### Barra de progresso na grade (duas cores)

Cada linha tem um track alinhado às 13 colunas:

1. **Cinza** (`#D1D5DB` / token `neutral`): do `indice_inicio` ao `indice_fim`  
   → mostra **onde a compra começa e onde acaba**
2. **Azul** (`#2563EB` / primary): do `indice_inicio` ao `indice_progresso`  
   → mostra **o status atual** (quanto já andou no tempo)

Regras de desenho:

- Largura da grade = 13 colunas iguais
- Barra cinza: `left = (indice_inicio / 13) * 100%`, `width = ((indice_fim - indice_inicio + 1) / 13) * 100%`
- Barra azul: mesma `left`, `width` até `indice_progresso` (incluído)
- Azul fica **por cima** do cinza (absolute)
- Se `fora_da_janela`, não desenhar barras; mostrar só “Fora da janela · Termina em …”
- Compra quitada: azul = 100% do cinza
- Tooltip / label: `primeira → ultima`, `%`, “Faltam X”, `estimativa_termino`

Mobile: horizontal scroll na grade **ou** stack (info em cima, barra full-width embaixo com labels início/fim).

#### Voltar à lista

Botão **“Lista”** no mesmo toggle — esconde a grade e volta aos cards, **sem perder** filtros/`mes`/`ano`.

---

## Critérios de aceite

- [ ] Toggle **Lista** ↔ **Por competências**
- [ ] Lista padrão preservada; competências é a visão alternativa
- [ ] Janela sempre 13 meses com mês filtrado no **centro**
- [ ] Botões anterior/próximo mudam `mes`/`ano` e refetch
- [ ] Barra cinza = início→fim da compra; azul = progresso atual
- [ ] Texto de estimativa de término (`estimativa_termino`)
- [ ] Itens com `quitada: true` / 100% **sempre no final** do ranking
- [ ] Última parcela no mês atual aparece; no mês anterior some
- [ ] Ordenação default: restantes → valor aberto → % (após quitadas no fim)
- [ ] Título via observação ou estabelecimento
- [ ] Empty / loading / erro / responsivo

---

## Fora de escopo

- Editar compra nesta tela
- Mais de 13 colunas visíveis ao mesmo tempo
- Drag da barra / edição de parcelas

---

## Backend (já implementado)

```http
GET /api/v1/dashboard/ranking-parceladas
```

Service: `App\Services\Dashboard\RankingParceladasService`  
Docs: [`docs/modules/dashboard.md`](modules/dashboard.md)
