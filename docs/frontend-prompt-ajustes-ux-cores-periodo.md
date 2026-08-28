# Prompt — Frontend: ajustes de UX (cores do cartão, modal de categoria, período)

Use este prompt no repositório do **frontend**. Back **já atende** os contratos abaixo — é correção de UI.

Quatro itens. Implementar **os quatro** neste PR. Não misturar com outras telas.

| # | Onde | Problema | Correção |
|---|------|----------|----------|
| 1 | Cadastro/edição de **cartão** | “Cor personalizada” não diz se é fundo ou texto | **Dois** blocos: personalizada do **fundo** e personalizada do **texto** |
| 2 | Fatura → **+ categoria** | Clicar no quadrado tema não mostra seleção | O mesmo anel/borda do cadastro de categoria |
| 3 | Modal categoria/sub (compra parcelada) | “Aplicar a todas as parcelas” nasce desmarcado | Checkbox **já marcado** |
| 4 | Análise / **gastos por categoria** | Chips `1 / 3 / 6 / 12` sem contexto; “12” parece ano calendário | Selects **Ano / De / Até** iguais ao Dashboard + atalhos de janela **com datas** |

Specs que este prompt **corrige** (não substituir o resto):

- Cartão / presets: [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md) · etapa 4 em [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md)
- Modal + / fatura: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md)
- Análise categoria: [`frontend-prompt-gastos-por-categoria.md`](frontend-prompt-gastos-por-categoria.md)
- Filtro do Dashboard (copiar o padrão): [`frontend-prompt-dashboard.md`](frontend-prompt-dashboard.md)

---

# 1 — Cartão: duas cores personalizadas (fundo e texto)

## Problema

Um único chip “Cor personalizada” mistura fundo e texto. O usuário não sabe o que está pintando.

## Objetivo

Presets de banco **permanecem**. Personalizada vira **dois blocos independentes**.

```
Cor do cartão
[ Padrão ] [ Nubank ] [ Inter ] [ C6 ] …     ← pares_cores (intacto)

Personalizada
  Fundo   [████]  seletor   hover/tooltip: #1a2b3c
  Texto   [████]  seletor   hover/tooltip: #ffffff

Preview do chip: fundo + texto do formulário
```

## UX (obrigatório)

1. Grade `lookups.pares_cores` **não muda** (Padrão primeiro, depois bancos). Clique num preset → preenche **os dois** (`cor_fundo` + `cor_texto`) e `coresManuais = true`. Fecha/esconde os seletores personalizados.
2. Abaixo da grade, seção **Personalizada** com **dois** controles lado a lado (empilhar no mobile):

| Bloco | Bind | Default ao abrir a seção |
|-------|------|--------------------------|
| **Fundo** | `cor_fundo` | HEX atual do form (ou `#e5e7eb` se vazio) |
| **Texto** | `cor_texto` | HEX atual do form. Se o usuário **acabou** de mudar só o fundo, sugerir contraste (abaixo) — ele **pode** trocar |

3. Cada bloco: label (**Fundo** / **Texto**) + swatch + `<input type="color">` (ou o picker já usado) + tooltip com o HEX em minúsculo.
4. Mudar **qualquer** um dos dois → `coresManuais = true` (auto-apply por nome/banco **para**).
5. Preview do cartão usa **sempre** o par visível no form (`background: cor_fundo`, `color: cor_texto`).
6. Clique num banco depois de personalizar → volta o par oficial e os pickers personalizados podem recolher.

## Contraste (sugestão, não trava)

Ao mudar **só** o fundo, se o texto ainda não foi editado nesta sessão de picker:

- luminância relativa do fundo ≥ `0.179` → sugerir texto `#111827`
- senão → `#ffffff`

O usuário **pode** escolher outro texto no bloco Texto. O back já aceita o par livre; se o front mandar só `cor_fundo`, o back calcula o texto — **neste fluxo mandar os dois**.

## Edit

Abrir a seção Personalizada (pickers visíveis) quando:

- `cor_fundo` **não** casa com nenhum `pares_cores[].cor_fundo`, **ou**
- casa o fundo mas `cor_texto` **não** é o `cor_texto` daquele swatch

Helper: `CartaoCoresPreset::casaComSwatch` existe no back; no front comparar HEX minúsculo 6 dígitos.

## Payload

Continua `cor_fundo` + `cor_texto`. Lookups: `cor_personalizada` é só metadado de UI (`chave`, `label`) — **não** pintar um chip único ambíguo. Usar o `label` como título da seção (“Cor personalizada”) e os dois blocos Fundo/Texto.

## Critérios de aceite — item 1

- [ ] Presets de banco intactos e clicáveis
- [ ] Dois seletores visíveis e nomeados: Fundo e Texto
- [ ] Hover de cada um mostra o HEX correspondente
- [ ] Preview usa o par escolhido
- [ ] Auto-apply Nubank etc. **não** quebra enquanto `coresManuais === false`
- [ ] Edit de HEX fora do catálogo já abre Fundo + Texto preenchidos

---

# 2 — Modal “Nova categoria” (fatura): quadrado tema selecionado

## Problema

Na tela de **faturas** (e no mesmo modal da compra), ao clicar **+ categoria**, a grade de temas aparece, mas o clique **não marca** o quadrado.

## Objetivo

A mesma regra visual do cadastro de categoria ([`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md) etapa 1) vale **também** neste modal.

## UX (obrigatório)

Reutilizar o **mesmo componente** de quadrados tema (`lookups.temas[]`), não uma grade “só de cor” sem estado.

- Quadrado ~20–28px, `background: tema.hex`.
- **Selecionado:** anel/borda 2px (`ring-2 ring-offset-2` ou equivalente) — **não** um ícone que tape a cor.
- `aria-pressed="true"` no quadrado ativo; `aria-label="{label} {hex}"`.
- Hover: tooltip com o HEX.
- Clique: `form.cor = tema.hex` **e** o anel muda na hora (estado local). POST manda esse HEX.
- Abrir o modal: **Preto já selecionado** (`lookups.cor_padrao` = `#000000`). Não deixar todos “vazios”.
- Um único selecionado por vez.

Se o modal de fatura e o de `/categorias` forem dois códigos: **igualar**. O bug é falta de estado visual, não falta de API.

## Critérios de aceite — item 2

- [ ] Clicar Vermelho/Azul/… destaca **só** aquele quadrado
- [ ] Preto vem marcado ao abrir
- [ ] Confirmar envia o HEX do quadrado com anel
- [ ] Vale na fatura **e** no form de compra (mesmo modal)

---

# 3 — Checkbox “Aplicar a todas as parcelas” já marcado

## Onde

Modal de cadastro rápido de **categoria** ou **subcategoria** quando a compra é **parcelada** (`compra_grupo_id` preenchido). Também no PUT de categorização da linha, se o checkbox existir fora do modal.

Texto atual (manter): **Aplicar a todas as parcelas da compra**.

## UX (obrigatório)

| Situação | Checkbox |
|----------|----------|
| Compra à vista / sem grupo | **Não mostrar** |
| Compra parcelada | Mostrar **já marcado** (`true`) |

- Estado inicial: `propagarGrupo = true`.
- O usuário **pode** desmarcar (só esta parcela).
- Persistência: `PUT /transacoes/editar` com `propagar_grupo: true` quando o box estiver marcado.

Não lembrar “desmarquei da última vez” no `localStorage`. Cada abertura do modal em parcelada = marcado.

## Critérios de aceite — item 3

- [ ] Parcelada: checkbox visível e **checked** ao abrir
- [ ] À vista: checkbox ausente
- [ ] Desmarcar → PUT **sem** `propagar_grupo` (ou `false`)
- [ ] Marcado → `propagar_grupo: true`; linhas irmãs atualizam na listagem

---

# 4 — Período da tela Análise / gastos por categoria

## Problema

Chips `1 / 3 / 6 / 12` não dizem **de quando até quando**. “12” parece **ano calendário** (jan–dez) mas a API `?meses=12` é **janela móvel** (hoje menos 12 meses). Falta o intervalo **Ano / De / Até** que o Dashboard já tem.

## Recorte (não confundir com o Dashboard)

| Tela | Recorte |
|------|---------|
| Dashboard | **Competência da fatura** (`faturas.mes` / `ano`) |
| Esta tela | **Data da compra** (`transacoes.data`) |

Os selects são **iguais visualmente** ao Dashboard. A query desta API é outra.

API: `GET /api/v1/dashboard/gastos-por-categoria`  
Spec: [`modules/gastos-por-categoria.md`](modules/gastos-por-categoria.md)

Esta API **não** tem `mes_inicio` / `mes_fim`. Mapear os selects para os params que existem:

| UI | Query |
|----|-------|
| Ano 2026 + De **Ano todo** | `data_inicio=2026-01-01&data_fim=2026-12-31` — **não** usar `meses=12` |
| Ano 2026 + De Agosto + Até Agosto | `ano=2026&mes=8` — **não** mandar `meses` |
| Ano 2026 + De Março + Até Junho | `data_inicio=2026-03-01&data_fim=2026-06-30` |
| Atalho **Últimos 3 meses** | `meses=3` (janela até **hoje**) |

Nunca misturar `meses` com `mes`/`ano` nem com `data_inicio`/`data_fim`.

## Layout do filtro (obrigatório)

Mesmos três selects do Dashboard ([`frontend-prompt-dashboard.md`](frontend-prompt-dashboard.md) § UX do filtro):

```
[ Ano ▼ ]     [ De ▼ ]     [ Até ▼ ]
  2026         Agosto       Agosto
```

Labels visíveis: **Ano**, **De**, **Até** (não só placeholder).

### Select Ano

- Componente **select** (não text).
- Opções: `anoAtual - 5` … `anoAtual + 1` (reutilizar o helper do Dashboard).
- Default: ano atual.

### Select De

| value | Label |
|-------|--------|
| `all` | **Ano todo (jan – dez)** |
| `1` … `12` | Janeiro … Dezembro |

O label **Ano todo (jan – dez)** é obrigatório — não escrever só “Ano todo” nem “12 meses”.

Default na primeira visita: **mês atual** (De = Até = agosto, se hoje é agosto) — um mês calendário, como o Dashboard.

### Select Até

- Visível só quando De **não** é “Ano todo (jan – dez)”.
- Opções: meses **≥ De**.
- Default: igual a De.
- Se De > Até, Até = De.

### Subtítulo (obrigatório)

Sempre mostrar o intervalo **real** da resposta, não o chip cru:

1. `periodo.label` (ex.: “Últimos 3 meses”, “Agosto 2026”)
2. Datas: `periodo.inicio` → `periodo.fim` (formatar `dd/mm/aaaa`)

Exemplos:

| Modo | Subtítulo |
|------|-----------|
| Últimos 3 meses (hoje 27/08/2026) | Últimos 3 meses · **27/05/2026 – 27/08/2026** |
| Ano todo 2026 | 2026 · janeiro a dezembro · **01/01/2026 – 31/12/2026** |
| De = Até = Agosto | Agosto 2026 · **01/08/2026 – 31/08/2026** |
| De março / Até junho | Março – junho 2026 · **01/03/2026 – 30/06/2026** |

Usar `periodo.inicio` / `periodo.fim` da API (não inventar o “hoje menos 3 meses” no cliente depois do GET). No atalho rolling, o subtítulo é o que tira a ambiguidade.

## Atalhos de janela móvel (além dos selects)

Os chips **não** são `1 / 3 / 6 / 12`. São frases +, se couber, o intervalo:

```
[ Último mês ]  [ Últimos 3 meses ]  [ Últimos 6 meses ]  [ Últimos 12 meses ]
```

- “Últimos 12 meses” **≠** “Ano todo (jan – dez)”. São dois controles diferentes.
- Clique no atalho → `?meses=1|3|6|12`, refetch, zera `selecao` das pizzas. Os selects Ano/De/Até **não** fingem ser essa janela (De/Até podem voltar a um estado neutro ou mostrar só o subtítulo da API).
- Clique em Ano/De/Até → **sai** do modo rolling (não mandar `meses`).

Default se não houver query/localStorage: **Últimos 3 meses** (`meses=3`) — produto atual — **ou** mês calendário atual, desde que o subtítulo deixe claro. Preferir: primeira visita = **Últimos 3 meses** com subtítulo datado; quem quiser ago–ago usa De/Até.

`localStorage`: persistir o modo (`rolling` + `meses` **ou** `calendario` + ano/de/até). Query string > localStorage > default.

## O que não mudar nesta tela

- Duas pizzas reativas, clique sem refetch.
- Filtros de cartão / responsável / origem.
- Não mandar `categoria_id` no GET por causa do clique na fatia.

## Critérios de aceite — item 4

- [ ] Selects Ano / De / Até iguais ao Dashboard (select, não text)
- [ ] De “Ano todo (jan – dez)” + ano 2026 → jan–dez **daquele ano**, não `meses=12`
- [ ] De = Até = Agosto → um mês calendário (`mes` + `ano`)
- [ ] Atalho “Últimos 3 meses” mostra **datas** no subtítulo (início → fim)
- [ ] “Últimos 12 meses” e “Ano todo (jan – dez)” são coisas diferentes na UI
- [ ] Mudar período refetch e zera a fatia selecionada

---

## Fora deste prompt

- Recolorir cartões em massa
- Color picker livre na **categoria** (categoria = só temas)
- Mudar a API de gastos por categoria (não precisa `mes_inicio`; mapear com `data_inicio` / `mes`+`ano` / `meses`)
- Filtro de gastos críticos (outra tela; se quiser o mesmo padrão, PR separado)

---

## Checklist geral

- [ ] Item 1: Fundo + Texto personalizados no cartão
- [ ] Item 2: quadrado tema com anel no modal da fatura/compra
- [ ] Item 3: `propagar_grupo` default `true` em parcelada
- [ ] Item 4: Ano / De / Até + atalhos datados na análise de categoria
