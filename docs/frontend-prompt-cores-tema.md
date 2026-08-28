# Prompt — Frontend: Cores tema (categoria, subcategoria e cartão)

Use este prompt no repositório do **frontend**. Spec do back (mesmas etapas): [`modules/cores-tema.md`](modules/cores-tema.md).

Implementar **uma etapa por vez**, no mesmo número que o back. Quando o back começar a etapa N, o front faz a etapa N.

| Etapa | Tema | Telas / comportamento |
|-------|------|------------------------|
| **1** | Quadrados tema na categoria + gráficos usam a cor salva | Cadastro de categoria; pizzas/barras/bolinhas de **categoria**; default preto |
| **2** | Subcategorias herdam tons mais claros | Preview no cadastro da categoria; pizza escrava e chips de sub usam `cor` da sub |
| **3** | Resto do app | Compras, gastos críticos, dashboard resumo, assinaturas, cadastro rápido |
| **4** | Cor personalizada no **cartão** *(outra tarefa)* | Presets de banco **permanecem**; chip “Cor personalizada” abre o seletor HEX |

Não implementar a etapa 4 no mesmo PR das categorias.

HEX sempre minúsculo (`#3b82f6`). Moeda e demais regras das telas **não mudam** — só a origem da cor.

---

## Regras visuais (todas as etapas)

| Quem | Cor | Fallback |
|------|-----|----------|
| Categoria **cadastrada** | `cor` da API | `#000000` se vier `null` (legado). **Não** cinza, **não** paleta do Chart.js |
| Bucket **Sem categoria** | API deve mandar `#9ca3af` | cinza `#9ca3af` |
| Fatia **Outros** | fixo no front | `#d1d5db` — não clicável |
| Subcategoria nomeada (etapa 2+) | `cor` **da sub** (não copiar a pai) | se `cor` ausente: clarear a `categoria_cor` **só** como fallback temporário; remover na etapa 3 |
| Cartão | `cor_fundo` / `cor_texto` | presets — etapa 4 não apaga isso |

Hover em quadrado/swatch: tooltip com o hexadecimal (`#3b82f6`).

**Proibido:** gerar rainbow por índice (`hsl(i * 37, 70%, 50%)`), ignorar `cor` da API, pintar sub com a mesma cor da pai depois da etapa 2.

---

# Etapa 1 — Cadastro de categoria: quadrados tema + gráficos

## Objetivo

1. No cadastro/edição de categoria, a cor é uma **grade de quadrados pequenos** (cores tema), não um `<select>` de HEX e não um color picker livre.
2. Hover no quadrado mostra o HEX.
3. Sem escolha do usuário → **preto** selecionado.
4. Todo gráfico de **categoria** pinta com `item.cor` (a cor salva).

## API — lookups

```http
GET /api/v1/categorias/lookups
```

```json
{
  "cor_padrao": "#000000",
  "cores": ["#000000", "#ef4444", "#f59e0b", "#22c55e", "#3b82f6", "#8b5cf6", "#ec4899", "#6b7280", "#14b8a6"],
  "temas": [
    { "chave": "preto", "label": "Preto", "hex": "#000000", "padrao": true, "variacoes": [] },
    { "chave": "vermelho", "label": "Vermelho", "hex": "#ef4444", "padrao": false, "variacoes": [] },
    { "chave": "laranja", "label": "Laranja", "hex": "#f59e0b", "padrao": false, "variacoes": [] },
    { "chave": "verde", "label": "Verde", "hex": "#22c55e", "padrao": false, "variacoes": [] },
    { "chave": "azul", "label": "Azul", "hex": "#3b82f6", "padrao": false, "variacoes": [] },
    { "chave": "roxo", "label": "Roxo", "hex": "#8b5cf6", "padrao": false, "variacoes": [] },
    { "chave": "rosa", "label": "Rosa", "hex": "#ec4899", "padrao": false, "variacoes": [] },
    { "chave": "cinza", "label": "Cinza", "hex": "#6b7280", "padrao": false, "variacoes": [] },
    { "chave": "teal", "label": "Teal", "hex": "#14b8a6", "padrao": false, "variacoes": [] }
  ]
}
```

Se o back desta etapa ainda não tiver `temas[]`, montar os quadrados a partir de `cores[]` e tratar `#000000` como padrão. Preferir `temas[]` quando existir.

## UX — formulário de categoria (create / edit)

```
Nome
[ Alimentação                    ]

Cor tema
[■] [■] [■] [■] [■] [■] [■] [■] [■]
 Preto Vermelho …                 ← label só no tooltip; o quadrado é a cor
```

- Quadrado ~20–28px, `border-radius` 4px, `background: tema.hex`.
- Selecionado: anel/borda 2px (ex. `ring-2 ring-offset-2`) na cor do texto da UI, **não** um check que tape a cor.
- Hover / long-press: tooltip **`#3b82f6`** (o hex). Opcional segunda linha com `label` (“Azul”).
- Clique: `form.cor = tema.hex`.
- Create: estado inicial `form.cor = lookups.cor_padrao` (`#000000`) — o quadrado preto já vem marcado.
- Edit: marcar o quadrado cujo `hex` === `data.cor`. Se a cor salva **não** estiver na paleta (legado), mostrar um quadrado extra com essa cor + tooltip HEX, sem apagar o valor.
- Acessível: cada quadrado é `button` com `aria-label="{label} {hex}"`.

**Não** colocar `<input type="color">` nesta etapa. Categoria = só temas.

Payload create/edit / cadastro rápido: enviar `cor` (o HEX visível). Não omitir — o back defaulta preto, mas o preview e o POST devem bater.

## Gráficos de categoria (obrigatório nesta etapa)

Onde houver fatia, barra ou bolinha de **categoria**, a cor **é** `cor` do item.

### Gastos por categoria — pizza mestre

Spec da tela: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md).

Trocar a regra antiga:

| Antes (apagar) | Agora |
|----------------|-------|
| `cor` null → cinza genérico para categoria cadastrada | cadastrada → `#000000` |
| paleta default da lib | `fatia.backgroundColor = item.cor` |
| “Sem categoria” misturado com cadastrada sem cor | “Sem categoria” → `#9ca3af` |

```ts
function corCategoria(item: { cor?: string | null; categoria_id?: number | null }) {
  if (item.categoria_id == null) return item.cor ?? '#9ca3af'
  return item.cor || '#000000'
}
```

Legenda: a bolinha usa a mesma função.

### Dashboard resumo

`por_categoria[].cor` na pizza/barras do resumo. Mesma função. Chip do cartão **não** muda (`cor_fundo` / `cor_texto`).

### Listagem de categorias

Bolinha ao lado do nome com `background: cor`.

## Cadastro rápido (modal da compra)

O modal de nova categoria hoje tem select opcional de cor. Nesta etapa:

- Os **mesmos quadrados tema** (compactos) + default preto.
- Pode omitir a grade e mandar sem `cor` (back grava preto) — aceitável no modal estreito. Se mostrar cor, é a grade, não um select.

Detalhe do fluxo: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md).

## Critérios de aceite — etapa 1

- [ ] Grade de quadrados tema no create/edit de categoria; preto selecionado no create
- [ ] Hover mostra o HEX
- [ ] POST envia o HEX do quadrado marcado
- [ ] Pizza mestre de gastos por categoria usa `cor` de cada fatia (Alimentação azul se o cadastro é azul)
- [ ] Categoria cadastrada sem cor (legado) aparece **preta**, não cinza
- [ ] “Sem categoria” é cinza `#9ca3af`
- [ ] Nenhum gráfico de categoria usa a paleta default da lib

## Fora da etapa 1

- Tons das subcategorias (etapa 2)
- Color picker livre
- Cartão (etapa 4)

---

# Etapa 2 — Subcategorias: tons mais claros que o tema

Só começa com o back da etapa 2 no ar (`cor` no item de subcategoria + `temas[].variacoes`).

## Objetivo

1. Usuário escolhe **azul** na categoria → as subs daquela categoria ficam **azuis mais claros**, cada uma um degrau.
2. A pizza escrava (e qualquer chip de sub) usa `sub.cor`, **não** a `categoria_cor` copiada.
3. No formulário da categoria, uma prévia mostra como as subs vão aparecer.

## Lookups

`temas[].variacoes` vem com ~5 HEX, todos mais claros que `temas[].hex`. Usar **só** para a prévia. Não recalcular HSL no front.

## UX — cadastro de categoria (além da etapa 1)

Abaixo da grade tema:

```
Cor tema
[■] [■] [■] …   ← selecionado: azul #3b82f6

Subcategorias (tons mais claros)
[■] [■] [■] [■] [■]
 tooltip HEX de cada variação
```

- Título: **“Subcategorias (tons mais claros)”**.
- Os quadrados da prévia **não** são clicáveis (são consequência do tema).
- Trocar o tema → a prévia troca na hora (`temas.find(t => t.hex === form.cor).variacoes`).
- Se o edit trouxer `subcategorias[]` com `cor` real, preferir **essas** cores na prévia (é o que está salvo). Sem array, usar `variacoes` do lookup.
- Texto de apoio (uma linha): “Ao salvar, as subcategorias desta categoria recebem tons desta cor, sempre mais claros que o tema.”

Edit de categoria: mudar o tema **regenera** as cores das subs no back. Não pedir confirmação; o texto de apoio basta.

## Formulário de subcategoria

**Sem** seletor de cor.

Em cada categoria vinculada, um quadrado read-only:

```
Categorias
[x] Alimentação  ■  #93c5fd     ← cor do vínculo (variação)
[x] Mercado      ■  #86efac
```

Create: ainda não há cor até salvar o vínculo; depois do POST, o `data` traz as categorias com a variação.

## Pizza escrava — gastos por categoria

Apagar a regra antiga (“usar `categoria_cor` e variar luminosidade no front”).

| Visual | Campo |
|--------|--------|
| Fatia da sub | `item.cor` |
| Tooltip / “de Alimentação” | `item.categoria_nome` + bolinha `item.categoria_cor` (pai) |
| Duas subs da mesma pai | HEX **já diferentes** na API — não clarear de novo |

```ts
function corSubcategoria(item: { cor?: string | null; categoria_cor?: string | null }) {
  return item.cor || item.categoria_cor || '#000000'
}
```

Depois do backfill, `item.cor` sempre vem.

“Outros” na pizza escrava continua `#d1d5db`.

## Listagens / selects

- `subcategorias-list?categoria_id=`: bolinha com a `cor` daquele vínculo.
- Compra: chip da sub usa essa cor; chip da categoria usa a tema.

## Critérios de aceite — etapa 2

- [ ] Prévia de 5 tons mais claros abaixo do tema; não clicável
- [ ] Pizza escrava: Delivery ≠ Alimentação na cor; ambos da família do tema (ex. azuis)
- [ ] Clique na fatia mestre (filtro local) **não** recolorir as fatias — só recorta o dataset
- [ ] Sub ligada a 2 categorias mostra 2 quadrados (um por pai) no cadastro da sub
- [ ] Sem seletor de cor no CRUD de subcategoria

## Fora da etapa 2

- Varredura do resto do app (etapa 3)
- Cartão (etapa 4)

---

# Etapa 3 — Alinhar o restante das telas

**Back: pronto.** APIs coalescem `null` → preto (cadastrada) / cinza (sem categoria). Item de sub em gastos críticos traz `cor`. Compra devolve `subcategoria.cor`. Estabelecimento sem categoria padrão continua `categoria_padrao_cor: null`.

Com as APIs já coalescendo `null` → preto e devolvendo `cor` na sub.

Percorrer e pintar com o HEX da API (sem lógica nova):

| Tela | O que pintar |
|------|----------------|
| Gastos críticos | bolinha `categoria_cor`; item de sub usa `cor` (variação) |
| Compras / fatura view | chip categoria / sub na linha |
| Visualização da compra | chip |
| Assinaturas | chip categoria |
| Estabelecimentos | categoria padrão |
| Responsável → visualizar | agregados por categoria |
| Dashboard resumo | pizza `por_categoria` (se a etapa 1 não cobriu 100%) |
| Cadastro rápido | quadrados tema (se a etapa 1 deixou o modal sem cor) |

Helper único no front (`corCategoria` / `corSubcategoria`) — **reutilizar**, não copiar fallback em cada tela.

## Critérios de aceite — etapa 3

- [ ] Nenhuma tela de gasto/cadastro mostra categoria cadastrada cinza ou rainbow
- [ ] “Sem categoria” continua cinza `#9ca3af` em todas as pizzas
- [ ] Chips de sub na compra batem com a pizza escrava da mesma sub

---

# Etapa 4 — Cartão: cor personalizada *(outra tarefa)*

**Back: pronto.** `lookups.cor_personalizada` + HEX livre em `cor_fundo`/`cor_texto`. Se o front mandar só o fundo, o back preenche o texto pelo contraste. `pares_cores` intacto (Padrão + bancos).

**Não remover** o esquema atual de cores oficiais dos bancos.

Prompt que continua válido para os presets: [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md).

## Objetivo

O seletor que hoje existe na categoria (hover → HEX, usuário escolhe a cor que quiser) entra no **cadastro de cartão** atrás de um chip **Cor personalizada**. Os swatches Nubank / Inter / C6 / Padrão **permanecem**.

## UX — formulário de cartão (create / edit)

```
Cor do cartão
[ Padrão ] [ Nubank ] [ Inter ] [ C6 ] …     ← pares_cores (já existe)

[ + Cor personalizada ]                      ← novo

  (só depois do clique)
  [████]  seletor   hover/tooltip: #1a2b3c
  Preview do chip do cartão com fundo + texto
```

1. Clique num preset → comportamento atual (`cor_fundo` + `cor_texto`, `coresManuais = true`).
2. Clique em **Cor personalizada**:
   - destaca esse chip
   - revela o seletor (`<input type="color">` ou o componente de HEX já usado na categoria)
   - hover no preview do seletor mostra o hexadecimal
   - `cor_fundo` = HEX escolhido
   - `cor_texto` = `#ffffff` ou `#111827` pelo contraste (luminância relativa do fundo; se ≥ 0.179 usar texto escuro)
   - `coresManuais = true` (auto-apply por nome/banco **para**)
3. Se o usuário depois clicar num banco, o seletor personalizado **fecha** e o par oficial volta (override do personalizado).
4. Edit: se `cor_fundo` **não** casa com nenhum `pares_cores[].cor_fundo`, abrir já em modo personalizada com o HEX salvo.

Não exigir picker de texto. Override opcional com `lookups.cores_texto[]` se a UI já tiver.

## Payload

Continua `cor_fundo` + `cor_texto`. O back **já aceita** HEX livre. O front **deve enviar** o par do preview.

## Critérios de aceite — etapa 4

- [ ] Todos os chips de banco atuais continuam visíveis e funcionando
- [ ] Auto-apply ao digitar Nubank etc. **não** quebra
- [ ] “Cor personalizada” revela o seletor; hover mostra HEX
- [ ] Preview do cartão usa fundo escolhido + texto com contraste
- [ ] Edit de um cartão com HEX fora do catálogo abre em personalizada
- [ ] C6 e XP continuam dois chips (preto oficial ≠ personalizada)

## Fora da etapa 4

- Recolorir cartões em massa
- Cor por bandeira/número
- Tirar os presets
- Color picker livre na **categoria** (categoria fica nos quadrados tema)

---

## Checklist geral

- [ ] Etapa 1: quadrados tema + gráficos de categoria na cor salva + default preto
- [ ] Etapa 2: pizza/chips de sub na variação clara; prévia no cadastro da categoria
- [ ] Etapa 3: o restante das telas no mesmo helper
- [ ] Etapa 4 (PR separado): personalizada no cartão, presets intactos

Spec: [`modules/cores-tema.md`](modules/cores-tema.md)  
Pizzas: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md)  
Presets de cartão: [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md)  
Cadastro rápido: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md)
