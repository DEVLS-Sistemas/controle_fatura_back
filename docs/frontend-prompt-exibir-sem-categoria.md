# Prompt — Frontend: Exibir sem categoria sob demanda

Use este prompt no repositório do **frontend**. Backend **não muda** neste card. Complementa [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md).

Card: CTLFAT-29.

---

## Objetivo

Nos dashboards de categoria, o bloco **Sem categoria** deixa de ser totalizador e deixa de ser fatia da rosca no estado inicial.

No lugar, um texto clicável: **Exibir sem categoria**. O clique revela valor e compras desse bucket. Sem o clique, totais e fatias são só do que já tem categoria.

Vale para **Gastos por categoria**. Se **Gastos críticos** tiver o mesmo bloco (totalizador ou fatia de categoria **Sem categoria**), o mesmo comportamento. Ranking de loja, estabelecimento e subcategoria não entra.

---

## O que o backend já faz

Nada muda. A API já separa o bucket.

`GET /api/v1/dashboard/gastos-por-categoria`

| Onde | Como achar |
|------|------------|
| Totais | `data.totais.sem_categoria` → `{ valor_total, compras, ocorrencias, percentual_gasto }` |
| Lista completa | `data.categorias[]` com `chave === "categoria-0"` e `categoria_id === null` |
| Snapshot da rosca | `data.dashboards.categorias[]` — o mesmo `chave` / `categoria_id` |
| Nome / cor | `nome`: **Sem categoria**. `cor`: `#9ca3af` |

`percentual_gasto` da API é contra o total do período **incluindo** o que não tem categoria. Com o bucket escondido, o centro da rosca e os KPIs usam só o gasto categorizado (ver abaixo). Não chamar a API de novo no clique.

---

## O que o front faz

Estado local, começa **escondido** a cada carga e a cada troca de período/filtro. Não persistir no `localStorage` nem na query da rota.

1. Tirar o totalizador **Sem categoria** da área de KPIs / totais.
2. Tirar a fatia `chave === "categoria-0"` da rosca de categorias no estado inicial. As outras fatias permanecem. “Outros” continua a regra atual.
3. No lugar do totalizador, texto clicável **Exibir sem categoria**.
4. Se `totais.sem_categoria.valor_total` e `compras` forem 0, não mostrar o texto.
5. Clique revela:
   - o valor (`valor_total`, BRL) e a quantidade (`compras`);
   - a fatia **Sem categoria** de volta na rosca (cinza `#9ca3af`).
6. Segundo clique, ou o texto **Ocultar sem categoria**, volta ao estado inicial (sem fatia, sem totalizador).
7. Sem o clique:
   - centro da rosca = gasto categorizado (`totais.valor_total` menos `totais.sem_categoria.valor_total`);
   - KPIs de gasto e compras do período também ignoram esse bucket;
   - percentuais visíveis da rosca fecham em cima desse total categorizado (recalcular só nesse estado; com o bucket visível, voltar ao `percentual_gasto` da API).
8. Recarregar a página: escondido de novo.

Gastos críticos: aplicar só se a tela já desenhar totalizador ou fatia **Sem categoria**. Não criar rosca nova lá.

---

## O que não fazer

- Não apagar compras sem categoria.
- Não criar endpoint nem query nova.
- Não mudar cor nem ordem das categorias cadastradas.
- Não esconder subcategoria, origem nem plataforma por causa deste toggle.

---

## Como testar

- [ ] A tela abre sem totalizador e sem fatia Sem categoria
- [ ] Existe o texto **Exibir sem categoria** quando houver gasto sem categoria
- [ ] O clique revela valor, compras e a fatia
- [ ] Sem o clique, o centro da rosca e os KPIs ignoram o que não tem categoria
- [ ] **Ocultar sem categoria** (ou o segundo clique) volta ao estado inicial
- [ ] Recarregar: volta escondido
- [ ] Período sem gasto sem categoria: o texto não aparece
