# Prompt — Frontend: atalho Projeção na listagem de faturas (CTLFAT-15)

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Backend **já implementado**. Sem endpoint novo.

Complementa:

- [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) — listagem
- [`frontend-prompt-fatura-mes-atual.md`](frontend-prompt-fatura-mes-atual.md) — botão **Ir para Mês Atual** e `filtros.mes` / `filtros.ano`
- [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md) — tela `/projecao` e `GET /dashboard/projecao-faturas`

Não misturar com cadastro, detalhe, upload de PDF nem outras telas. Só a **listagem** (`/faturas`) + a query que a **projeção já existente** já sabe ler.

---

## Objetivo

Na **listagem de faturas**, um botão de atalho **Projeção** abre a tela de projeção.

- Se o filtro de **mês** e **ano** da lista estiver preenchido (incluindo **Ir para Mês Atual** ligado), a projeção abre **nessa competência**.
- Sem recorte de competência, a projeção usa o **default dela** (mês atual no back). O client **não** inventa mês.

---

## O que o backend já faz

Não criar rota, query param nem cálculo novo.

| Superfície | Já existe |
|------------|-----------|
| Listagem | `GET /api/v1/faturas/listar` devolve `filtros.mes`, `filtros.ano`, `filtros.mes_atual_ativo`, `competencia_atual` |
| Projeção | `GET /api/v1/dashboard/projecao-faturas?mes=&ano=` — sem query, o back usa o mês atual |

Fonte da competência do atalho: **selects da lista** e/ou `filtros.mes` + `filtros.ano` da última resposta. **Não** usar `new Date()` no browser.

---

## UI

Colocar o botão **junto dos filtros de mês e ano**, na mesma faixa de **Ir para Mês Atual** (não escondido no rodapé, não só no empty state).

```
[ Cartão ] [ Bandeira ] [ Mês: 09 ] [ Ano: 2026 ] [ Status ] [ Busca ]
[ Ir para Mês Atual ] [ Projeção ]
```

- Label **exato:** `Projeção`
- É **navegação**, não toggle. Não fica “pressionado”.
- **Não** tirar o botão **Ir para Mês Atual**.
- Mobile: permanece visível (pode ir para a linha de baixo dos selects, ao lado de Ir para Mês Atual).

---

## Navegação

Rota da projeção **já existente** (menu Projeção / Previsão de faturas). Não inventar uma segunda tela.

### Com recorte de competência (`mes` **e** `ano` preenchidos)

Inclui **Ir para Mês Atual** ligado (selects = `competencia_atual`, `filtros.mes` e `filtros.ano` numéricos).

```
/projecao?mes={mes}&ano={ano}
```

Números inteiros, iguais aos da lista (ex.: lista em 09/2026 → `/projecao?mes=9&ano=2026` ou `mes=09` se a tela de projeção já normaliza; o GET da API aceita `mes=9`).

A tela de projeção **já** deve:

1. Ler `mes`/`ano` da query.
2. Preencher o seletor de referência.
3. Chamar `GET /api/v1/dashboard/projecao-faturas?mes=&ano=` com esses valores.
4. Destacar a coluna `referencia: true` dessa competência.

Se isso ainda não estiver ligado à query da rota, fazer **só** essa leitura — o contrato da API já está em [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md).

### Sem recorte de competência

Selects de mês/ano vazios (“Todos”), botão Ir para Mês Atual **desligado**, `filtros.mes` / `filtros.ano` `null`.

```
/projecao
```

**Sem** query. Não mandar `mes`/`ano` da `competencia_atual`. Não mandar `mes_atual=0` da listagem para a projeção — esse flag é só da lista.

O back da projeção aplica o default (mês atual). O seletor da projeção segue o `referencia` da resposta.

### Só mês **ou** só ano na lista

A API de listagem aceita filtrar só `mes` ou só `ano`. A projeção precisa do **par**. Tratar como **sem recorte**: ir para `/projecao` sem query. Não completar o outro valor no client.

---

## O que **não** levar na URL

Não copiar da listagem para `/projecao`:

- `cartao_id`, `cartao_bandeira_id`, `status`, `palavra_chave`, `page`, `perPage`
- `mes_atual` (`0` ou `1`)

O atalho leva **só** competência (ou nada).

---

## Voltar para a listagem

O estado da lista **não** quebra: filtros, página e toggle Ir para Mês Atual continuam como estavam (query de `/faturas` intacta). O atalho não faz refetch da lista.

---

## O que **não** fazer

- Não criar endpoint nem cálculo novo no back.
- Não calcular mês/ano com `new Date()` para montar a query da projeção.
- Não abrir a projeção no mês atual quando a lista está em **todas** as competências.
- Não remover **Ir para Mês Atual**.
- Não levar filtro de cartão/status/busca.
- Não navegar para detalhe de fatura.
- Não mudar cadastro, PDF, Raio-X, simulador.

---

## Checklist de aceite

- [ ] Botão **Projeção** visível na listagem, junto de **Ir para Mês Atual**
- [ ] Lista em 09/2026 (selects ou Ir para Mês Atual) → `/projecao?mes=&ano=` com referência setembro/2026 (seletor + coluna de referência)
- [ ] Lista sem mês/ano (todas as competências) → `/projecao` sem query; projeção no default do back
- [ ] Ir para Mês Atual ligado → projeção na competência de hoje (`competencia_atual` da API, não o relógio do browser)
- [ ] Cartão/status/busca da lista **não** vão na query da projeção
- [ ] Voltar para `/faturas` mantém os filtros da listagem
- [ ] **Ir para Mês Atual** continua no lugar

---

## Como testar

1. Listagem com mês 09 e ano 2026 → **Projeção**. Conferir o seletor e a coluna de referência.
2. Desmarcar **Ir para Mês Atual** (todas as competências) → **Projeção** sem query. Conferir default da tela.
3. Voltar e conferir que a lista não quebrou (filtros e toggle iguais).
4. Ligar **Ir para Mês Atual** de novo → **Projeção** na competência de `competencia_atual`.
