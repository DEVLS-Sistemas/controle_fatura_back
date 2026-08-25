# Prompt — Frontend: Visualização da Compra

Use este prompt no repositório do frontend para implementar a tela de **visualização da compra** — um detalhe somente leitura que concentra **todas as informações** de uma compra (à vista ou parcelada).

A tela de **ranking de parceladas** deve abrir esta visualização ao clicar no card/linha da compra.

---

## Objetivo

Permitir que o usuário veja, em um só lugar:

- O que foi comprado (título, estabelecimento, loja)
- Quando (data da compra)
- Onde caiu (cartão, bandeira, final, nome no cartão)
- Classificação (categoria, subcategoria, responsável, origem)
- Valores (total, parcela, pago, aberto, %)
- **Parcelas já pagas** vs competência atual vs em aberto
- Repasse do responsável por parcela (se houver)
- Estimativa de término

Não é tela de edição.

---

## Conceito

No backend, uma compra parcelada são N linhas em `transacoes` com o mesmo `compra_grupo_id`. À vista é 1 linha (`compra_grupo_id = null`).

- Título: **`observacoes`** se existir; senão **estabelecimento**
- **Paga / aberta** usa a competência da fatura vs `mes`/`ano` da query (igual ao ranking)
  - competência **anterior** à referência → `status_parcela = paga`
  - **igual** à referência → `atual` (conta como paga no progresso, igual ao ranking)
  - **posterior** → `aberta`
- Repasse é outro conceito: o responsável devolveu o valor ao usuário (não misturar com “parcela na fatura do mês”)

---

## API

```http
GET /api/v1/transacoes/visualizar/{identificador}?mes=8&ano=2026
Authorization: Bearer {token}
```

`identificador`:

| Origem | Valor |
|--------|--------|
| Ranking de parceladas | `compra_grupo_id` (UUID) |
| Listagem de compras / fatura | `id` da transação (número). Se for parcela de um grupo, a API devolve **o grupo inteiro** |

404 `{ "error": true, "message": "Compra não encontrada" }` se não for do usuário ou não existir.

### Query params

| Param | Default | Descrição |
|-------|---------|-----------|
| `mes` / `ano` | atual | Competência de referência para pago vs aberto (**obrigatório** ao vir do ranking — usar o mesmo `mes`/`ano` da listagem) |

### Resposta (`data`) — campos principais

```json
{
  "referencia": { "mes": 8, "ano": 2026 },
  "compra_grupo_id": "uuid-ou-null",
  "transacao_id": 101,
  "avista": false,
  "titulo": "Geladeira Frost Free 400L",
  "titulo_origem": "observacoes",
  "observacoes": "Geladeira Frost Free 400L",
  "data_compra": "2026-03-15",
  "tipo": "purchase",
  "tipo_label": "Compra",
  "origem_compra": "COMPRAS_PRESENCIAL",
  "origem_compra_label": "Compras presencial",
  "eh_assinatura": false,
  "importada_pdf": true,
  "parcelas_total": 12,
  "parcela_atual": 3,
  "parcelas_pagas": 3,
  "parcelas_restantes": 9,
  "valor_parcela": 291.67,
  "valor_total": 3500.0,
  "valor_pago": 875.01,
  "valor_aberto": 2624.99,
  "percentual_pago": 25.0,
  "quitada": false,
  "estimativa_termino": "Fev/2027",
  "estabelecimento": { "id": 10, "nome": "Magazine", "loja_id": 3, "loja_nome": "Magazine Luiza" },
  "categoria": { "id": 1, "nome": "Casa", "cor": "#22c55e" },
  "subcategoria": { "id": 2, "nome": "Eletro" },
  "responsavel": { "id": 1, "nome": "Eu", "tipo": "eu" },
  "cartao": { "id": 2, "nome": "Nubank", "banco": "Nu Pagamentos", "cor_fundo": "#8b5cf6", "cor_texto": "#ffffff" },
  "bandeira": { "id": 5, "nome": "Mastercard" },
  "cartao_numero": {
    "id": 7,
    "ultimos_digitos": "1234",
    "tipo": "fisico",
    "tipo_label": "Físico",
    "apelido": "Principal",
    "nome_no_cartao": "LEO SILVA"
  },
  "primeira_parcela": { "parcela_atual": 1, "mes": 3, "ano": 2026, "valor": 291.67, "fatura_id": 80 },
  "competencia_atual": { "parcela_atual": 3, "mes": 8, "ano": 2026, "valor": 291.67, "fatura_id": 88 },
  "ultima_parcela": { "parcela_atual": 12, "mes": 2, "ano": 2027, "valor": 291.63, "fatura_id": 100 },
  "proxima_parcela": { "parcela_atual": 4, "mes": 9, "ano": 2026, "valor": 291.67, "fatura_id": 90 },
  "parcelas": [
    {
      "id": 101,
      "parcela_atual": 1,
      "parcelas_total": 12,
      "data": "2026-03-15",
      "valor": 291.67,
      "fatura_id": 80,
      "fatura_mes": 3,
      "fatura_ano": 2026,
      "fatura_label": "Mar/2026",
      "fatura_status": "processada",
      "fatura_status_label": "Processada",
      "paga": true,
      "status_parcela": "paga",
      "status_parcela_label": "Paga",
      "importada_pdf": true,
      "repasse": {
        "status_repasse": "pago",
        "status_repasse_label": "Pago",
        "valor_pago": 291.67,
        "valor_aberto": 0,
        "data_ultimo": "2026-04-10"
      }
    }
  ]
}
```

Objetos `estabelecimento`, `categoria`, `subcategoria`, `responsavel`, `cartao`, `bandeira`, `cartao_numero` vêm `null` se não houver.

Campos flat do ranking (`categoria_nome`, `cartao_nome`, `cartao_cor_fundo`, …) também vêm no payload — preferir os objetos aninhados na UI.

`status_parcela`: `paga` | `atual` | `aberta`.  
`paga` (bool) é `true` para `paga` **e** `atual` (alinhado a `parcelas_pagas` do ranking).

---

## UX / Tela

### Menu / rota

Rota sugerida: `/compras/:identificador`

Query: `?mes=8&ano=2026`

Ao voltar para o ranking, preservar `mes`/`ano`/filtros.

### Origem do clique (obrigatório)

Na tela de **ranking de parceladas** (`/parceladas`):

- Card (visão lista) e linha (visão competências) são **clicáveis**
- Cursor pointer + chevron/`→` indicando que abre detalhe
- Navegar para:

```
/compras/{item.compra_grupo_id}?mes={referencia.mes}&ano={referencia.ano}
```

Não disparar o clique em controles internos (toggle, filtros). Tooltip opcional: “Ver detalhes da compra”.

### Layout sugerido

Cabeçalho:

1. Botão **Voltar** (histórico; fallback `/parceladas`)
2. Título da compra (`titulo`)
3. Se `titulo_origem === 'observacoes'` e houver estabelecimento, subtítulo com o estabelecimento / loja
4. Badge `parcela_atual/parcelas_total` (ou “À vista” se `avista`)
5. Badge **Quitada** se `quitada`

Bloco **resumo financeiro** (cards):

| Card | Campo |
|------|--------|
| Total | `valor_total` |
| Pago | `valor_pago` + `parcelas_pagas` parcelas |
| Em aberto | `valor_aberto` + `parcelas_restantes` restantes |
| Progresso | barra 0–100% com `percentual_pago` |

Texto: **Termina em {estimativa_termino}** (ocultar se à vista).

Bloco **dados da compra** (grid de pares rótulo/valor; omitir linha se null):

| Rótulo | Fonte |
|--------|--------|
| Data da compra | `data_compra` (dd/mm/aaaa) |
| Cartão | chip com `cartao.cor_fundo` / `cor_texto` + `cartao.nome` |
| Bandeira | `bandeira.nome` |
| Final / número | `**** {cartao_numero.ultimos_digitos}` + apelido + tipo |
| Nome no cartão | `cartao_numero.nome_no_cartao` |
| Categoria | pill com `categoria.cor` + `categoria.nome` |
| Subcategoria | `subcategoria.nome` |
| Estabelecimento | `estabelecimento.nome` |
| Loja | `estabelecimento.loja_nome` |
| Responsável | `responsavel.nome` |
| Origem | `origem_compra_label` |
| Assinatura | `eh_assinatura` (sim/não) |
| Observação | `observacoes` (bloco de texto) |

Bloco **parcelas** (tabela; esconder se `avista` **ou** mostrar 1 linha — preferir mostrar sempre):

| Coluna | Campo |
|--------|--------|
| # | `{parcela_atual}/{parcelas_total}` |
| Competência | `fatura_label` |
| Valor | `valor` (moeda BRL) |
| Status | badge `status_parcela_label` |
| Fatura | `fatura_status_label` (processada/pendente…) |
| Repasse | só se responsável ≠ “Eu” **ou** se `status_repasse !== pendente`: badge + valor pago |

Cores sugeridas do status da parcela:

- `paga` → verde
- `atual` → azul (destacar a linha)
- `aberta` → cinza / âmbar

Linha da competência de referência (`status_parcela === 'atual'`) com fundo leve.

Clique na competência pode ir para a fatura (`/faturas/{fatura_id}`) se essa rota já existir — opcional.

Mobile: tabela vira lista de cards por parcela.

### Estados

- Loading (skeleton do header + grid + tabela)
- Erro / 404: “Compra não encontrada” + voltar
- Empty de parcelas não deve ocorrer se o GET deu 200

---

## Critérios de aceite

- [ ] Clique no ranking (lista e competências) abre esta tela com o `compra_grupo_id`
- [ ] `mes`/`ano` da referência do ranking vão na query (progresso igual ao ranking)
- [ ] Tela concentra: data, cartão/bandeira/final, categoria/sub, estabelecimento/loja, responsável, origem, observação
- [ ] Resumo: total, pago, aberto, %, parcelas pagas/restantes, término
- [ ] Lista de parcelas com status paga / atual / aberta
- [ ] Repasse visível quando houver pagamento
- [ ] À vista funciona se o identificador for o `id` da transação
- [ ] Somente leitura (sem editar nesta tela)
- [ ] Voltar preserva o contexto do ranking
- [ ] Loading / 404 / responsivo

---

## Fora de escopo

- Editar compra, parcela, categoria ou responsável nesta tela
- Registrar repasse (continuar na tela de repasses)
- Upload / PDF da fatura

---

## Backend (já implementado)

```http
GET /api/v1/transacoes/visualizar/{identificador}
```

Service: `App\Services\Transacao\CompraVisualizacaoService`  
Docs: [`docs/modules/transacoes.md`](modules/transacoes.md)  
Ranking (origem do clique): [`docs/frontend-prompt-ranking-parceladas.md`](frontend-prompt-ranking-parceladas.md)
