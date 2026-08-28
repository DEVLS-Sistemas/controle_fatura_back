# Prompt — Frontend: Ir para Mês Atual na listagem de faturas

Use este prompt no repositório do **frontend**. Backend **já implementado**. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

Não misturar com cadastro, detalhe, upload de PDF nem outras telas. Só a **listagem** (`/faturas`).

---

## Objetivo

Na listagem de faturas:

1. Botão **Ir para Mês Atual** — de qualquer página da lista, leva à competência de **hoje** (ex.: 28/08/2026 → mês `8`, ano `2026`).
2. A tela **já abre com esse filtro ligado**. Os selects de **mês** e **ano** nascem preenchidos com a competência atual.
3. **Desmarcar** o botão tira o recorte de competência: a lista volta a trazer **todas** as faturas, ainda respeitando os outros filtros (cartão, bandeira, status, busca).

---

## O que o backend já faz

`GET /api/v1/faturas/listar` **não** assume mês atual sozinho. Sem `mes`/`ano`/`mes_atual`, devolve tudo (é assim que o “desmarcar” funciona).

O recorte de competência é o calendário (`faturas.mes` / `faturas.ano`), igual aos selects que já existem — não é o ciclo de fechamento do cartão.

### Lookups — `GET /api/v1/faturas/lookups`

Campos novos (além de `meses`, `status`, `cartoes`):

```json
{
  "competencia_atual": { "mes": 8, "ano": 2026, "label": "08/2026" },
  "anos": [
    { "value": 2027, "label": "2027" },
    { "value": 2026, "label": "2026" },
    { "value": 2025, "label": "2025" }
  ]
}
```

| Campo | Uso |
|-------|-----|
| `competencia_atual.mes` / `.ano` | Preencher os selects e o estado do botão. **Não** calcular “hoje” no browser |
| `competencia_atual.label` | Texto auxiliar (`08/2026`) |
| `anos[]` | Options do select de ano (anos das faturas do usuário + ano anterior/atual/próximo) |
| `meses[]` | Já existia (`01`…`12`) |

### Listagem — query

```http
GET /api/v1/faturas/listar?mes=8&ano=2026&page=1
GET /api/v1/faturas/listar?mes_atual=1&page=1
GET /api/v1/faturas/listar?cartao_id=1
```

| Query | Efeito |
|-------|--------|
| `mes` + `ano` | Filtra aquela competência. **Preferir este par** (URL compartilhável e selects sincronizados) |
| `mes_atual=1` (ou `true`) | Atalho: o back aplica a competência de hoje e **sobrescreve** `mes`/`ano` se vierem diferentes |
| sem `mes`, sem `ano`, sem `mes_atual` | Sem recorte de competência — todas as faturas (outros filtros valem) |

`mes_atual=1` **e** `mes`+`ano` juntos: o back usa a competência de hoje. Na prática, com o botão ligado, envie `mes`+`ano` iguais a `competencia_atual` (e, se quiser, `mes_atual=1`).

### Listagem — meta na resposta

A paginação ganhou dois blocos. Usar para sincronizar o botão depois do fetch (empty state incluso):

```json
{
  "current_page": 1,
  "per_page": 5,
  "total": 0,
  "data": [],
  "competencia_atual": { "mes": 8, "ano": 2026, "label": "08/2026" },
  "filtros": {
    "mes": 8,
    "ano": 2026,
    "mes_atual_ativo": true
  }
}
```

| Campo | Uso |
|-------|-----|
| `filtros.mes` / `filtros.ano` | O que de fato filtrou (`null` = sem recorte) |
| `filtros.mes_atual_ativo` | `true` quando mes+ano aplicados **são** os de hoje → botão **pressionado** |
| `competencia_atual` | Igual ao lookup; dá para atualizar os selects sem outro GET |

---

## UI

Colocar o botão **junto dos filtros de mês e ano** (não escondido no rodapé, não só no empty state).

```
[ Cartão ] [ Bandeira ] [ Mês: 08 ] [ Ano: 2026 ] [ Status ] [ Busca ]
[ Ir para Mês Atual ]   ← toggle; estado pressionado = mês atual
```

- Label **exato:** `Ir para Mês Atual`
- Visual de **toggle** (pressed / selected / chip ativo) quando `mes_atual_ativo`
- Mobile: o botão permanece visível sem scroll horizontal absurdo (pode ir para a linha de baixo dos selects)

Os selects de mês e ano **continuam visíveis e editáveis**. Com o botão ligado, os dois mostram `competencia_atual`.

---

## Estado inicial (obrigatório)

Na **primeira carga** de `/faturas` **sem** `mes`/`ano` na URL (e sem `mes_atual=0` — ver abaixo):

1. Buscar lookups (ou usar `competencia_atual` da listagem).
2. Ligar o botão.
3. Preencher mês e ano com `competencia_atual`.
4. Chamar `GET /listar?mes={mes}&ano={ano}&page=1` (+ demais filtros da URL, se houver).

Não disparar um `GET /listar` “sem mês” e depois outro com o mês atual — a primeira listagem já vai recortada.

### URL

Manter a query da rota alinhada com a tela (refresh e deep-link):

| Situação | Query da rota |
|----------|----------------|
| Botão ligado | `?mes=8&ano=2026` (números de `competencia_atual`) |
| Botão desligado, sem mês/ano escolhidos | `?mes_atual=0` (ou omitir mes/ano **e** gravar `mes_atual=0` para o refresh **não** religar o default) |
| Usuário escolheu outro período | `?mes=3&ano=2025` (botão desligado) |
| Deep-link de outra tela (Raio-X, Projeção) | Honrar `mes`/`ano` da URL. Se forem os de hoje, botão ligado; senão, desligado |

`mes_atual=0` **não** precisa ir para a API (omitir `mes`, `ano` e `mes_atual` no GET). Serve só para o front não reaplicar o default no F5.

---

## Interações

### Clicar com o botão **desligado** (ou em qualquer página da lista)

1. `page = 1` — **sempre**. Não importa se estava na página 4 de “todas” ou na 2 de março.
2. `mes` / `ano` = `competencia_atual`.
3. Botão liga.
4. Refetch. Outros filtros (cartão, bandeira, status, `palavra_chave`) **permanecem**.

### Clicar com o botão **ligado** (desmarcar)

1. `page = 1`.
2. Limpar `mes` e `ano` (selects vazios / “Todos”).
3. Botão desliga.
4. Refetch **sem** `mes`/`ano`/`mes_atual` — a API devolve todas as competências. Cartão/status/busca continuam.

### Mudar o select de mês ou ano

- Novo valor **é** a competência de hoje → botão **liga** (mesmo efeito de “Ir para Mês Atual”).
- Novo valor **não** é hoje (ou um dos dois ficou vazio) → botão **desliga**, mas **não** limpe o que o usuário acabou de escolher. Filtre pelo par mês/ano restante (se só um estiver preenchido, a API já aceita filtrar só `mes` ou só `ano`).
- Sempre `page = 1` ao mudar competência.

### Paginação com o botão ligado

Trocar de página **não** desliga o botão. Os `mes`/`ano` (e o toggle) seguem na query.

---

## Empty state

Com o recorte de competência e lista vazia, manter o texto já previsto: **“Nenhuma fatura neste período”**. Não tratar isso como “não há faturas na conta” — o usuário pode desmarcar o botão ou mudar o mês.

---

## O que **não** fazer

- Não calcular mês/ano com `new Date()` no cliente se o lookup/listagem já trouxe `competencia_atual`.
- Não defaultar o mês atual no **cadastro** de fatura / modal de PDF (lá o ano do arquivo continua sendo a regra).
- Não navegar para o **detalhe** de uma fatura ao clicar no botão — continua na listagem, só muda o filtro e volta à página 1.
- Não limpar cartão/status/busca ao ligar ou desligar o botão.
- Não mudar a tela de detalhe, Projeção, Raio-X nem compras.

---

## Checklist de aceite

- [ ] Botão **Ir para Mês Atual** visível na listagem, junto de mês/ano
- [ ] Abrir `/faturas` sem query já filtra o mês/ano de `competencia_atual` e deixa os dois selects preenchidos
- [ ] Botão nasce **pressionado** nesse estado
- [ ] Clicar no botão (de qualquer `page`) aplica competência de hoje e volta para `page=1`
- [ ] Desmarcar o botão remove mês/ano, lista todas as competências e **mantém** os outros filtros
- [ ] Refresh com botão desligado **não** volta sozinho para o mês atual (`mes_atual=0` na URL, ou equivalente)
- [ ] Mudar o select para outro mês desliga o botão e filtra aquele período
- [ ] Deep-link `?mes=3&ano=2025` respeita março/2025 (botão desligado)
- [ ] Deep-link `?mes=8&ano=2026` em agosto/2026 deixa o botão ligado
- [ ] `filtros.mes_atual_ativo` da API bate com o estado visual do botão
- [ ] Empty do período: “Nenhuma fatura neste período”
