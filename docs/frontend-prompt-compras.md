# Prompt — Frontend: Compras / Estabelecimentos / Responsável

Use este prompt no repositório do frontend para alinhar a UI à API atualizada do `controle_fatura_back`.

---

## Contexto do produto

Sistema de controle de gastos no cartão. A dívida da compra fica com o **responsável** (não há rateio).

Cadastros envolvidos:
- **Categoria** (opcional na compra)
- **Subcategoria** (opcional na compra; exige categoria e vínculo N:N)
- **Estabelecimento** (opcional no cadastro manual; obrigatório só em lançamentos da fatura/PDF)
- **Responsável** (obrigatório; default = “Eu”)
- **Origem da compra** (obrigatório) — canal/origem: online, presencial, pagamento de serviços (assinatura/débito automático) ou pagamento de fatura
- **Descrição da compra** (`observacoes`) — o que foi comprado; obrigatório no cadastro manual

Não existe 3º nível hierárquico. No cadastro manual, a descrição **não** é o estabelecimento.

---

## APIs (Bearer Sanctum)

Base: `/api/v1`

| Módulo | Prefixo |
|--------|---------|
| Categorias | `/categorias` |
| Subcategorias | `/subcategorias` |
| Estabelecimentos | `/estabelecimentos` |
| Responsáveis | `/responsaveis` |
| Transações | `/transacoes` |

CRUD padrão em todos: `lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `{modulo}-list`.

### Breaking changes

- Removido: `/estabelecimento-categorias`
- Transação agora usa `estabelecimento_id` (ainda aceita `estabelecimento` texto no create, com find-or-create **somente se o nome do estabelecimento for enviado**). No cadastro **manual**, não enviar estabelecimento: a descrição da compra vai para `observacoes` e o estabelecimento fica `null` até a conciliação — ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md)
- Categoria da compra é `transacoes.categoria_id` (não herda mais globalmente do estabelecimento)
- Novo: `subcategoria_id`
- `responsavel_id` obrigatório; lookups de transação incluem `default_responsavel_id`
- Lookups de transação **não** incluem `estabelecimentos` — usar `GET /estabelecimentos/estabelecimentos-list?palavra_chave=`
- `lookups.cartoes[]` inclui `pessoa_id` / `pessoa_nome` (dois cartões com o mesmo nome de titulares diferentes são itens distintos)

---

## 1) Nova tela: Estabelecimentos

Criar CRUD de estabelecimentos.

Campos:
- Nome (obrigatório) — identificador da maquininha
- Loja / nome fantasia (opcional) — UX tipo responsável (texto + modal busca/cadastro); ver [`frontend-prompt-loja-estabelecimento.md`](frontend-prompt-loja-estabelecimento.md)
- Categoria padrão (opcional)
- Subcategoria padrão (opcional; só habilitada com categoria padrão; listar via `GET /subcategorias/subcategorias-list?categoria_id=`)
- Ativo

Listagem com busca (`palavra_chave`) e edição dos padrões. Exibir `loja_nome` quando houver.

Regra: alterar padrão **não** reescreve categorias de compras antigas.

---

## 2) Nova tela: Subcategorias

CRUD de subcategorias.

Campos:
- Nome
- Categorias vinculadas (multi-select, mínimo 1) → payload `categoria_ids: number[]`
- Ativo

---

## 3) Tela / formulário de Compra (Transação)

### Fluxo fatura → transações (obrigatório)

A tela de detalhe da fatura já abre a listagem de transações daquela fatura:

```http
GET /api/v1/faturas/listar/{id}
GET /api/v1/transacoes/listar?fatura_id={id}&perPage=200&page=1
```

Nessa tela **também** deve ser possível **adicionar e editar categoria e subcategoria** da transação (não só na tela global de compras).  
Usar os mesmos endpoints:

```http
PUT /api/v1/transacoes/editar
POST /api/v1/transacoes/cadastrar
GET /api/v1/transacoes/lookups
GET /api/v1/subcategorias/subcategorias-list?categoria_id={id}
```

A listagem já devolve, por linha: `categoria_id`, `categoria_nome`, `categoria_cor`, `subcategoria_id`, `subcategoria_nome`.

Campos do formulário de compra:

| Campo | UI |
|-------|-----|
| Valor da compra | input obrigatório (`valor_compra`) — total da venda |
| Parcelas | **select 1..36** (default 1). Não usar mais inputs de `parcela_atual` no create |
| Valores das parcelas | se N > 1: projetar N inputs “Parcela k/N” com split igual; usuário pode ajustar |
| Total das parcelas | soma dos inputs; deve bater com `valor_compra` (bloquear submit se diferir) |
| Data | data da compra — com o `dia_limite_fatura` do cartão define a fatura da 1ª parcela; demais avançam mês a mês |
| Cartão / Fatura | cartão (grupo) no form global; `fatura_id` opcional na tela da fatura |
| Final do cartão | select `cartao_numero_id` — obrigatório no create (quando 2+ finais); **sempre editável** no update |
| Estabelecimento | no cadastro **manual**: **não mostrar**. Na edição de lançamento do PDF: select/async (`/estabelecimentos/estabelecimentos-list`) |
| Origem da compra | select obrigatório — opções em `lookups.origens_compra` (`value`/`label`) |
| É assinatura | switch/checkbox `eh_assinatura` (independente da origem). Pré-marcar ao escolher `PAGAMENTO_SERVICOS`. Ver [assinaturas](frontend-prompt-assinaturas.md) |
| Categoria | select opcional; ao escolher estabelecimento, **pré-selecionar** `categoria_padrao_id` |
| Subcategoria | select opcional; filtrar por categoria; pré-selecionar `subcategoria_padrao_id` se compatível |
| Observação | textarea **obrigatório** no cadastro manual (rótulo **Descrição da compra** — o que foi comprado). Enviar como `observacoes`. Ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md) |
| Responsável | ver UX abaixo |

Valores de `origem_compra` (enviar o `value`):

| value | label | Uso |
|-------|-------|-----|
| `COMPRAS_ONLINE` | Compras online | E-commerce / compra pela internet |
| `COMPRAS_PRESENCIAL` | Compras presencial | Compra no estabelecimento físico |
| `PAGAMENTO_SERVICOS` | Pagamento de serviços | Assinatura / cartão cadastrado com desconto automático |
| `PAGAMENTO_FATURA` | Pagamento fatura | Pagamento de fatura |

### UX do parcelamento (obrigatório)

1. Usuário informa `valor_compra` e escolhe N no select (1–36).
2. Front gera N campos com valores iguais (`valor_compra / N`; centavos na última).
3. Usuário pode ajustar cada parcela.
4. Exibir **Total das parcelas** em tempo real; validar igualdade com `valor_compra` antes do POST.
5. **Não** enviar `parcela_atual` no create — o backend sempre materializa 1..N.
6. Resposta traz `compra_grupo_id` + array `transacoes` (uma por parcela/fatura).

Regras UX gerais:
- Ao trocar estabelecimento, reaplicar pré-seleção dos padrões (preferência: reaplicar ao trocar estabelecimento).
- Ao categorizar uma compra cujo estabelecimento **ainda não tem** padrão, o backend:
  - grava essa categoria/subcategoria como padrão do estabelecimento;
  - preenche as demais transações vazias do mesmo estabelecimento.
  Não é necessário chamar `PUT /estabelecimentos/editar` no front — acontece no `PUT /transacoes/editar` (e no create com `categoria_id` explícito).
  Se o estabelecimento já tem padrão, editar só a compra altera aquela linha (a menos de `propagar_grupo`).
- Subcategoria desabilitada sem categoria.
- Create payload à vista:

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "estabelecimento_id": 10,
  "valor_compra": "150,90",
  "data": "2026-07-15",
  "tipo": "purchase",
  "origem_compra": "COMPRAS_PRESENCIAL",
  "eh_assinatura": false,
  "parcelas_total": 1,
  "categoria_id": 2,
  "subcategoria_id": 5,
  "responsavel_id": 1,
  "observacoes": "Feira do mês + ventilador"
}
```

- Create payload parcelado (10x):

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "estabelecimento_id": 10,
  "valor_compra": "1000,00",
  "data": "2026-03-15",
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE",
  "parcelas_total": 10,
  "parcelas": [
    { "parcela": 1, "valor": "100,00" },
    { "parcela": 2, "valor": "100,00" },
    { "parcela": 3, "valor": "100,00" },
    { "parcela": 4, "valor": "100,00" },
    { "parcela": 5, "valor": "100,00" },
    { "parcela": 6, "valor": "100,00" },
    { "parcela": 7, "valor": "100,00" },
    { "parcela": 8, "valor": "100,00" },
    { "parcela": 9, "valor": "100,00" },
    { "parcela": 10, "valor": "100,00" }
  ],
  "categoria_id": 2,
  "responsavel_id": 1
}
```

### UX — cadastro rápido de categoria e subcategoria (obrigatório)

Mesmo espírito do modal de Responsável: botão **+** ao lado dos selects, sem ir para outra tela.

Detalhe completo (endpoints `cadastrar-rapido`, payloads, checklist): [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md).

Resumo:
1. Categoria: `POST /categorias/cadastrar-rapido` `{ nome, cor? }` → selecionar id → se compra já existe, `PUT /transacoes/editar`.
2. Subcategoria: exige categoria; `POST /subcategorias/cadastrar-rapido` `{ nome, categoria_id }` → selecionar id → `PUT /transacoes/editar`.
3. Backend deduplica por nome (case-insensitive) e, na subcategoria, vincula à categoria atual se o nome já existir.
4. Em parceladas na edição: `propagar_grupo: true` se quiser aplicar a todas as parcelas.

### UX — cadastro rápido de cartão (obrigatório)

Mesmo espírito: botão **+** ao lado do select de **Cartão** em `/transacoes/add`, sem ir para `/cartoes`.

Detalhe: [`frontend-prompt-cadastro-rapido-cartao.md`](frontend-prompt-cadastro-rapido-cartao.md).

Resumo:
1. `POST /cartoes/cadastrar-rapido` `{ nome, bandeira, ultimos_digitos, dia_limite_fatura, dia_vencimento_fatura }`
2. Resposta traz `data.id` (`cartao_id`) e `data.cartao_numero_id` — **selecionar os dois**
3. Se o cartão já existe (mesmo nome), `criado: false` e o final é incluído se faltar
4. CTA “Cadastre um final neste cartão” (0 números): mesmo POST com `cartao_id` + `bandeira` + `ultimos_digitos` (dias não são obrigatórios)

### UX — seleção do final do cartão (obrigatório)

Hierarquia: **Grupo → Bandeira → Número (final)**. A compra aponta para o **número** (`cartao_numero_id`). A fatura é da **bandeira** (derivada do número).

1. Select **Cartão** (grupo) — `lookups.cartoes` ou `GET /cartoes/cartoes-list`
2. Select **Final do cartão** (`cartao_numero_id`):
   - Preferir números aninhados em `lookups.cartoes[].bandeiras[].numeros[]`
   - Ou async: `GET /cartoes/numeros-list?cartao_id={id}` (todos os finais do grupo)
   - Na tela da fatura: `GET /cartoes/numeros-list?fatura_id={id}` (só finais da bandeira da fatura)
3. Regras de UI:
   - **0 números** → bloquear com CTA “Cadastre um final neste cartão”
   - **1 número** → pré-selecionar e **não exibir** o campo (backend também auto-seleciona)
   - **2+ números** → select obrigatório “Cartão / Final” (label `•••• 1234` ou `•••• 5678 (Viagem)`)
4. Se o grupo tiver várias bandeiras, o label do select pode incluir a bandeira (`Mastercard · •••• 1234`). O `cartao_numero_id` já implica a bandeira — não precisa enviar `cartao_bandeira_id` no create (opcional).
5. Enviar `cartao_numero_id` no create (e no edit quando alterar). Parcelas do mesmo grupo herdam o mesmo final.

### UX — editar final quando não veio no create/import

Quando o final **não pôde ser selecionado/detectado** (import PDF, linha sem `cartao_numero_id`, etc.), a edição da transação **deve** permitir escolher o final:

1. No formulário de **editar** transação, o select **Final do cartão** fica sempre visível (mesmo que no create tenha sido oculto por haver só 1 final).
2. Fonte das opções:
   - Na tela da fatura: `GET /cartoes/numeros-list?fatura_id={fatura_id}`
   - Na tela global: `GET /cartoes/numeros-list?cartao_id={cartao_id}` (ou pela bandeira da fatura da linha: `cartao_bandeira_id`)
3. Payload mínimo:

```json
{
  "id": 123,
  "cartao_numero_id": 10
}
```

```http
PUT /api/v1/transacoes/editar
```

4. Se `compra_grupo_id` existir, oferecer “Aplicar a todas as parcelas” → `propagar_grupo: true`.
5. Após salvar, atualizar a linha (`ultimos_digitos`, `cartao_numero_nome_no_cartao`) e, no detalhe da fatura, o agrupamento por final.

Detalhes da view da fatura (grupo “Sem cartão identificado”, atalho “Definir final”): ver [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

- No formulário global: selecionar **cartão** (`cartao_id`) + **final** (`cartao_numero_id`). Não enviar `fatura_id`.
- Backend cria/vincula fatura da **bandeira do número** pelo ciclo do cartão (`dia_limite_fatura`): compras até o dia limite entram na fatura do mês; após o limite, na fatura seguinte. Parcelas seguintes avançam +1 mês a partir desse período.
- Lookups de cartões incluem `cor_fundo`, `cor_texto`, `dia_limite_fatura`, `dia_vencimento_fatura` e `bandeiras[].numeros[]` (chip: `background = cor_fundo`, `color = cor_texto`).
- Na tela de detalhe da fatura: pode enviar `fatura_id` (já conhecido) + `cartao_numero_id` (final dentro da bandeira).
- Listagem devolve `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_tipo`, `cartao_numero_apelido`, `cartao_numero_nome_no_cartao`, `cartao_bandeira`.
- `valor_compra` / valores de parcela em formato BR (`125,50`).
- Omitir `categoria_id`/`subcategoria_id`/`responsavel_id` no create aplica defaults.
- Listagem: mostrar `k/N`; se a linha tiver `compra_grupo_id`, na exclusão oferecer “Excluir só esta parcela” vs “Excluir todas as parcelas da compra” (`DELETE .../excluir/{id}?excluir_grupo=1`).
- Edit de `responsavel_id` ou `observacoes` já sincroniza sozinho em todas as parcelas do `compra_grupo_id`.
- Edit de outros campos compartilhados (categoria, estabelecimento, origem_compra, `cartao_numero_id`) pode enviar `propagar_grupo: true` para atualizar o grupo.
- `origem_compra` é obrigatório no create; omitir → 422.
- `cartao_numero_id` é obrigatório no create quando há 2+ finais; com 1 final o backend preenche sozinho.
- No **edit**, `cartao_numero_id` pode ser enviado a qualquer momento para preencher ou corrigir o final.

---

## 4) UX do Responsável (listagem e formulário)

### Listagem de transações
- **Não** mostrar select de responsável na linha/filtro principal (filtro por responsável na toolbar ainda pode existir).
- Mostrar o nome do responsável como **texto**.
- Botão (ex.: “Responsável” / ícone pessoa) abre **modal** para:
  - selecionar responsável existente, ou
  - cadastrar novo (`POST /responsaveis/cadastrar`) e já vincular.
- Ao fechar o modal com sucesso, chamar `PUT /transacoes/editar` com `id` + `responsavel_id`. Em compra parcelada o backend aplica o responsável a **todas** as parcelas do `compra_grupo_id`; atualizar o texto do responsável nas linhas irmãs da listagem/detalhe.

### Formulário de nova compra
- Mesmo padrão: não exibir select grande por padrão; mostrar “Responsável: Eu” (usar `default_responsavel_id` dos lookups) + botão para abrir modal e trocar.

---

## 5) Listagem de transações — colunas sugeridas

- Data, **o que foi comprado** (`texto_compra` / `observacoes`), Estabelecimento (`null` → **—**), Valor, Origem da compra, **Assinatura** (`eh_assinatura`), Categoria, Subcategoria, Responsável (texto), Fatura/Cartão, Final (`•••• 1234`), ações.
- Se `precisa_conciliar === true`: destaque âmbar + badge `precisa_conciliar_label` (`Compra manual · conciliar com a fatura`). Não usar o estabelecimento como título nessas linhas. Ignorar o badge se `compra_manual === false` (parcela automática da fatura).
- Se `conciliada_com_manual === true`: badge + atalho para `compra_manual_vinculada.id`. Na fatura, ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).

Filtros: data, origem_compra, `eh_assinatura`, categoria, subcategoria, estabelecimento, responsável, fatura/cartão, `cartao_numero_id` / `ultimos_digitos`, palavra-chave, `status_conciliacao`.

Mapear `origem_compra` para o `label` de `lookups.origens_compra` (badge/chip discreto na linha).

---

## 6) Relatórios (não implementar agora)

Preparar navegação/placeholder futuro para:
- o que cada responsável deve **por fatura**
- **por compra**
- **geral**

O backend já tem `responsavel_id` obrigatório e embrião no dashboard (`por_responsavel`).

---

## 7) Assinaturas (tela nova)

Cobranças recorrentes (Netflix, Spotify, sistemas) têm tela própria — não misturar com o formulário de compra nem com o ranking de parceladas.

Prompt: [`frontend-prompt-assinaturas.md`](frontend-prompt-assinaturas.md).

Resumo: tela em **duas listas** — oficiais (`data.assinaturas`) e sugestões para confirmar (`data.candidatas`). Na compra, switch `eh_assinatura`. Confirmar na tela de assinaturas: `POST /assinaturas/cadastrar`.

---

## Checklist de aceite

- [ ] Tela Estabelecimentos com padrão de categoria/subcategoria
- [ ] Tela Subcategorias com multi categorias
- [ ] Compra pré-seleciona padrões do estabelecimento
- [ ] Na tela de fatura → transações: add/edit de categoria **e** subcategoria
- [ ] Botões de cadastro rápido (+) de categoria e subcategoria (ver prompt dedicado)
- [ ] Botão de cadastro rápido (+) de **cartão** em `/transacoes/add` (ver [`frontend-prompt-cadastro-rapido-cartao.md`](frontend-prompt-cadastro-rapido-cartao.md))
- [ ] Primeira categorização de um estabelecimento sem padrão → vira padrão + preenche vazias
- [ ] Editar categoria quando já há padrão → altera só a compra (não sobrescreve outras)
- [ ] Subcategoria exige categoria
- [ ] Listagem: responsável só como texto + modal
- [ ] Default responsável = Eu
- [ ] Removidas referências a `/estabelecimento-categorias`
- [ ] Select de parcelas 1..36 + campos editáveis por parcela + validação do total
- [ ] Select obrigatório de origem da compra (`origem_compra`) no formulário
- [ ] Select de final do cartão (`cartao_numero_id`) — oculto se só houver 1 no create
- [ ] Create envia `cartao_numero_id` (quando aplicável)
- [ ] Edit permite escolher/alterar `cartao_numero_id` quando a transação veio sem final
- [ ] Listagem/filtro exibem origem da compra e final do cartão
- [ ] Switch **É assinatura** (`eh_assinatura`) no form e badge na listagem
- [ ] Create parcelado materializa N transações (sem input de parcela_atual)
- [ ] Excluir grupo de compra quando houver `compra_grupo_id`
- [ ] Tela Assinaturas (detector + gasto anual) — ver prompt dedicado
