# Prompt — Frontend: Controle de Repasses do Responsável

Use este prompt no repositório do frontend para criar a tela **Controle de Repasses** (quitação do que o responsável deve ao usuário), acessível a partir da **Projeção** e da **Fatura do Responsável**.

Referências: [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md), [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md), [`modules/repasses.md`](modules/repasses.md).

---

## Conceito (ler antes de implementar)

| O que **não** é | O que **é** |
|-----------------|-------------|
| Quitação da fatura do cartão (`pago` / `valor_pago` da bandeira) | Registro de que o **responsável te pagou** o valor de uma compra/parcela |
| Transação `tipo=payment` do PDF | Novo recurso: **repasses** ligados à parcela (`transacao` purchase) |

Fluxo mental do usuário:

1. Comprou algo no cartão e atribuiu ao responsável (ex.: Empresa) — parcelado 10×, com observação “TV 55”.
2. Todo mês o responsável te paga a parcela.
3. Você marca o que foi pago → vê o que falta por compra, quantas parcelas restam e o total em aberto.

---

## Objetivo da tela

Uma matriz:

- **Linhas** = compras do responsável (agrupadas por `compra_grupo_id`; à vista = 1 linha)
- **Colunas** = competências (meses), mesma janela da Projeção (13 meses)
- **Célula** = parcela daquele mês: devido / pago / aberto + status visual

Ações:

- Clicar na célula → registrar / editar / quitar repasse (valor, data, obs)
- Ver resumo: total pago, total em aberto, compras abertas
- Opcional: quitar todas as parcelas da competência de uma vez

---

## Entrada / rotas

### A partir da Projeção

| Origem | Destino |
|--------|---------|
| Botão/CTA na linha do responsável (Tabela 2) | Matriz com `responsavel_id` + `mes`/`ano` da referência |
| Menu da Fatura do Responsável | “Controle de repasses” com mesmos params |
| Badge “em aberto” na fatura do responsável | Matriz filtrando `somente_abertos` ou scroll até a compra |

Rota sugerida:

```
/projecao/responsaveis/:responsavelId/repasses?mes=8&ano=2026
```

Drawer full-screen também serve (mesmo contrato de dados).

---

## API (contrato alvo)

> **Status:** API implementada em `/api/v1/repasses` (ver [`modules/repasses.md`](modules/repasses.md)).

Base: Bearer Sanctum `/api/v1`.

### 1. Matriz (fonte principal)

```http
GET /api/v1/repasses/matriz?responsavel_id={id}&mes={m}&ano={a}&janela=13
```

Opcionais: `somente_abertos=1`, `incluir_abertos=1`, `cartao_id=`.

#### Campos usados na UI

**Header / resumo**

| Campo | Uso |
|-------|-----|
| `responsavel_nome`, `responsavel_tipo` | título |
| `colunas[]` | cabeçalho da grade (`label`, `chave`, `referencia`) |
| `resumo.valor_aberto` | destaque principal |
| `resumo.valor_pago` | secundário |
| `resumo.compras_abertas` | chip |
| `resumo.valor_aberto_na_referencia` | “A receber neste mês” |

**Por compra (linha)**

| Campo | Uso |
|-------|-----|
| `estabelecimento` + `observacoes` | título da linha (obs = o que foi comprado) |
| `data_compra`, `parcelas_total` | subtítulo (`15/03 · 10x`) |
| `cartao_nome` + cores + `ultimos_digitos` | chip |
| `valor_total`, `valor_pago`, `valor_aberto` | colunas fixas à esquerda (sticky) |
| `parcelas_pagas` / `parcelas_total` | “3/10 pagas” |
| `status_repasse` | cor da linha |

**Por célula**

| Campo | Uso |
|-------|-----|
| `valor_devido` | valor da parcela |
| `valor_pago` / `valor_aberto` | breakdown |
| `status_repasse` | `pendente` \| `parcial` \| `pago` |
| `parcela_atual`/`parcelas_total` | badge `5/10` |
| `transacao_id` | id para POST do repasse |
| `data_ultimo_pagamento` | tooltip |

Célula sem chave na `celulas` → mês sem parcela dessa compra (vazio / “—”).

### 2. Registrar / editar / excluir repasse

```http
POST /api/v1/repasses/cadastrar
PUT  /api/v1/repasses/editar
DELETE /api/v1/repasses/excluir/{id}
GET  /api/v1/repasses/listar?transacao_id={id}
```

**Create (exemplo)**

```json
{
  "transacao_id": 102,
  "valor": "150,00",
  "data_pagamento": "2026-08-05",
  "observacoes": "PIX parcial"
}
```

**Quitar parcela inteira**

```json
{
  "transacao_id": 102,
  "quitar": true,
  "data_pagamento": "2026-08-05"
}
```

### 3. Quitar competência (atalho)

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

Confirmar no UI: “Quitar todas as parcelas em aberto de Ago/2026 deste responsável?”

### 4. MVP sem endpoint de matriz (fallback)

Se a matriz ainda não existir:

1. `GET /transacoes/listar?responsavel_id=&tipo=purchase&perPage=200` **sem** mes/ano (ou várias chamadas por competência da janela)
2. Agrupar no front por `compra_grupo_id` (ou `id` se null)
3. Pivotar por `fatura_mes`/`fatura_ano`
4. Campos de repasse (`status_repasse`, etc.) só aparecem quando o backend enriquecer a listagem

Preferir esperar a API `matriz` — o pivot no front fica frágil com paginação.

---

## UI sugerida

### Topo

1. Voltar → Projeção ou Fatura do responsável
2. Título: `Repasses · {nome}` + chip `tipo`
3. Competência de referência + Anterior / Próxima (atualiza `mes`/`ano` e refetch; **colunas** se deslocam como na Projeção)
4. Toggle: **Somente em aberto**
5. Filtro opcional de cartão

### Cards de resumo (uma faixa, sem poluir)

```
Em aberto          R$ 3.800,00     ← destaque
Pago               R$ 1.200,00
A receber (ref.)   R$   450,00     ← competência referência
Compras abertas    3
```

CTA secundário: **Quitar competência de referência** (chama `quitar-competencia`).

### Grade (núcleo)

Layout tipo planilha com scroll horizontal nas competências e **sticky** nas colunas de identificação:

```
| Compra              | Total  | Pago | Aberto | Jul/26 | Ago/26* | Set/26 | … |
|---------------------|--------|------|--------|--------|---------|--------|---|
| Magazine            | 3000   | 900  | 2100   | ✓ 300  | ○ 300   | ○ 300  |   |
| TV 55" · 10x · 15/03|        | 3/10 |        | pago   | pend.   | pend.   |   |
| [Nubank ·••1234]    |        |      |        |        |         |        |   |
```

#### Célula

| Status | Aparência sugerida |
|--------|--------------------|
| `pago` | fundo verde suave; valor; check; tooltip com data |
| `parcial` | fundo âmbar; `pago/devido` (ex. 150/300) |
| `pendente` | neutro; valor devido; clique = registrar |
| vazia | “—” ou vazio |

Célula da coluna `referencia: true` com borda/highlight (igual Projeção).

Clique na célula com `transacao_id`:

→ **Modal / drawer de repasse**

### Modal de repasse

1. Cabeçalho: estabelecimento + obs + `parcela k/N` + competência
2. Devido / Já pago / Em aberto (readonly, da célula)
3. Lista de repasses já lançados (`GET .../listar?transacao_id=`) — editar / excluir
4. Form novo:
   - `data_pagamento` (default hoje)
   - `valor` (default = `valor_aberto`)
   - `observacoes` opcional
5. Botões: **Quitar restante** (`quitar: true`) · **Salvar** · Cancelar

Validação UI: valor ≤ aberto (espelhar regra 422 do backend).

Após sucesso: fechar e **refetch da matriz** (resumo + linha).

### Empty states

- Sem compras: “Nenhuma compra deste responsável na janela.” + link para Compras / Projeção
- Tudo pago + filtro somente abertos: “Nada em aberto 🎉”

### Mobile

- Priorizar: lista de compras com expand (acordeão) mostrando os meses como lista vertical, em vez da grade larga
- Ou: grade com scroll horizontal obrigatório + sticky da 1ª coluna
- Resumo em carrossel horizontal de métricas

---

## Integração com Fatura do Responsável

Na tela já especificada em [`frontend-prompt-fatura-responsavel.md`](frontend-prompt-fatura-responsavel.md):

1. **Não** usar `pago`/`valor_pago` da fatura de bandeira.
2. Por linha purchase, se a API enriquecer:
   - badge `pendente` / `parcial` / `pago` (`status_repasse`)
   - texto `Aberto R$ X` quando > 0
3. Ação por linha: **Registrar repasse** → mesmo modal (precisa `transacao_id`).
4. No header: link **Controle de repasses** → matriz.
5. Opcional no bloco financeiro:

```
Total devido (competência)   R$ …
Já repassado                 R$ …
Em aberto                    R$ …
```

(só se o endpoint de fatura do responsável ou a listagem trouxer esses totais; senão calcular só das linhas carregadas.)

Atualizar o checklist da fatura do responsável: passa a **exibir** quitação de **repasse**, sem confundir com quitação de bandeira.

---

## Integração com Projeção

Na Tabela 2 (`por_responsavel`):

- Ícone/botão “Repasses” na linha (não precisa mudar a API da projeção no MVP)
- Tooltip opcional (fase 2): valor em aberto do responsável — quando o backend expor

Não alterar o significado de `realizado` / `projetado` / `total` da projeção (continuam sendo **devido** no cartão).

---

## Estados de loading / erro

- Skeleton da grade (linhas × colunas)
- Erro 422 no create: mostrar mensagem do backend no form (`valor excede em aberto`)
- Após `quitar-competencia`: toast com quantidade de parcelas quitadas

---

## Fluxo resumido

```
Projeção → Repasses do responsável
  → GET /repasses/matriz?responsavel_id&mes&ano
  → render grade compra × competência
  → clique célula
      → GET /repasses/listar?transacao_id
      → POST /repasses/cadastrar (ou quitar: true)
      → refetch matriz

Fatura do responsável (competência)
  → badge status_repasse por linha
  → CTA modal repasse / link para matriz
```

---

## Checklist de aceite

- [ ] Entrada a partir da Projeção com `responsavel_id` + competência
- [ ] Matriz: compra (linha) × meses (colunas); sticky com estabelecimento/obs/totais
- [ ] Célula mostra status `pendente` | `parcial` | `pago` e valores
- [ ] Modal registra valor, data e permite quitar restante
- [ ] Pagamento parcial atualiza célula para `parcial` e mantém `valor_aberto`
- [ ] Resumo: total em aberto, pago, a receber na referência
- [ ] Toggle somente em aberto
- [ ] (Opcional) Quitar competência inteira com confirmação
- [ ] Fatura do responsável: badges de repasse + link para a matriz (sem usar quitação de bandeira)
- [ ] Mobile usável (acordeão ou scroll horizontal)
- [ ] Empty states cobertos
- [ ] Não confundir visualmente com `pago` da fatura do cartão (copy: “Repassado” / “Em aberto com o responsável”)

---

## Copy sugerida (evitar ambiguidade)

| Evitar | Preferir |
|--------|----------|
| Pago / Fatura paga | Repassado / Quitado com o responsável |
| Restante da fatura | Em aberto (responsável) |
| Registrar pagamento | Registrar repasse |

---

## Fora de escopo deste prompt

- Implementar a quitação da bandeira (`tipo=payment`)
- Alterar cálculos de `projecao-faturas`
- Conta-corrente genérica do responsável sem vínculo a parcela
