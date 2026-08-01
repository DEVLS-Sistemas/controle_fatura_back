# Prompt — Frontend: Compras / Estabelecimentos / Responsável

Use este prompt no repositório do frontend para alinhar a UI à API atualizada do `controle_fatura_back`.

---

## Contexto do produto

Sistema de controle de gastos no cartão. A dívida da compra fica com o **responsável** (não há rateio).

Cadastros envolvidos:
- **Categoria** (opcional na compra)
- **Subcategoria** (opcional na compra; exige categoria e vínculo N:N)
- **Estabelecimento** (obrigatório; tem categoria/subcategoria padrão)
- **Responsável** (obrigatório; default = “Eu”)
- **Observação** (texto livre opcional)

Não existe 3º nível hierárquico. Detalhe livre só em Observação.

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
- Transação agora usa `estabelecimento_id` (ainda aceita `estabelecimento` texto no create, com find-or-create)
- Categoria da compra é `transacoes.categoria_id` (não herda mais globalmente do estabelecimento)
- Novo: `subcategoria_id`
- `responsavel_id` obrigatório; lookups de transação incluem `default_responsavel_id`
- Lookups de transação **não** incluem `estabelecimentos` — usar `GET /estabelecimentos/estabelecimentos-list?palavra_chave=`

---

## 1) Nova tela: Estabelecimentos

Criar CRUD de estabelecimentos.

Campos:
- Nome (obrigatório)
- Categoria padrão (opcional)
- Subcategoria padrão (opcional; só habilitada com categoria padrão; listar via `GET /subcategorias/subcategorias-list?categoria_id=`)
- Ativo

Listagem com busca (`palavra_chave`) e edição dos padrões.

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
| Data | data da compra — define a fatura da 1ª parcela; demais avançam mês a mês |
| Cartão / Fatura | cartão no form global; `fatura_id` opcional na tela da fatura |
| Estabelecimento | select/async obrigatório (`/estabelecimentos/estabelecimentos-list`) |
| Categoria | select opcional; ao escolher estabelecimento, **pré-selecionar** `categoria_padrao_id` |
| Subcategoria | select opcional; filtrar por categoria; pré-selecionar `subcategoria_padrao_id` se compatível |
| Observação | textarea opcional |
| Responsável | ver UX abaixo |

### UX do parcelamento (obrigatório)

1. Usuário informa `valor_compra` e escolhe N no select (1–36).
2. Front gera N campos com valores iguais (`valor_compra / N`; centavos na última).
3. Usuário pode ajustar cada parcela.
4. Exibir **Total das parcelas** em tempo real; validar igualdade com `valor_compra` antes do POST.
5. **Não** enviar `parcela_atual` no create — o backend sempre materializa 1..N.
6. Resposta traz `compra_grupo_id` + array `transacoes` (uma por parcela/fatura).

Regras UX gerais:
- Ao trocar estabelecimento, reaplicar pré-seleção dos padrões (preferência: reaplicar ao trocar estabelecimento).
- Editar categoria/subcategoria na compra **não** chama update do estabelecimento.
- Subcategoria desabilitada sem categoria.
- Create payload à vista:

```json
{
  "cartao_id": 1,
  "estabelecimento_id": 10,
  "valor_compra": "150,90",
  "data": "2026-07-15",
  "tipo": "purchase",
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
  "estabelecimento_id": 10,
  "valor_compra": "1000,00",
  "data": "2026-03-15",
  "tipo": "purchase",
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

- No formulário global: selecionar **cartão** (`cartao_id`). Não enviar `fatura_id`.
- Backend cria/vincula fatura do cartão no mês da `data` (parcela 1) e nos meses seguintes.
- Na tela de detalhe da fatura: pode enviar `fatura_id` (já conhecido).
- `valor_compra` / valores de parcela em formato BR (`125,50`).
- Omitir `categoria_id`/`subcategoria_id`/`responsavel_id` no create aplica defaults.
- Listagem: mostrar `k/N`; se a linha tiver `compra_grupo_id`, na exclusão oferecer “Excluir só esta parcela” vs “Excluir todas as parcelas da compra” (`DELETE .../excluir/{id}?excluir_grupo=1`).
- Edit de campos compartilhados (categoria, responsável, estabelecimento, observação) pode enviar `propagar_grupo: true` para atualizar o grupo.

---

## 4) UX do Responsável (listagem e formulário)

### Listagem de transações
- **Não** mostrar select de responsável na linha/filtro principal (filtro por responsável na toolbar ainda pode existir).
- Mostrar o nome do responsável como **texto**.
- Botão (ex.: “Responsável” / ícone pessoa) abre **modal** para:
  - selecionar responsável existente, ou
  - cadastrar novo (`POST /responsaveis/cadastrar`) e já vincular.
- Ao fechar o modal com sucesso, atualizar só o texto do responsável na linha (`PUT /transacoes/editar` com `id` + `responsavel_id`).

### Formulário de nova compra
- Mesmo padrão: não exibir select grande por padrão; mostrar “Responsável: Eu” (usar `default_responsavel_id` dos lookups) + botão para abrir modal e trocar.

---

## 5) Listagem de transações — colunas sugeridas

- Data, Estabelecimento, Valor, Categoria, Subcategoria, Responsável (texto), Observação (tooltip/corte), Fatura/Cartão, ações.

Filtros: data, categoria, subcategoria, estabelecimento, responsável, fatura/cartão, palavra-chave.

---

## 6) Relatórios (não implementar agora)

Preparar navegação/placeholder futuro para:
- o que cada responsável deve **por fatura**
- **por compra**
- **geral**

O backend já tem `responsavel_id` obrigatório e embrião no dashboard (`por_responsavel`).

---

## Checklist de aceite

- [ ] Tela Estabelecimentos com padrão de categoria/subcategoria
- [ ] Tela Subcategorias com multi categorias
- [ ] Compra pré-seleciona padrões do estabelecimento
- [ ] Na tela de fatura → transações: add/edit de categoria **e** subcategoria
- [ ] Editar categoria na compra não altera o estabelecimento
- [ ] Subcategoria exige categoria
- [ ] Listagem: responsável só como texto + modal
- [ ] Default responsável = Eu
- [ ] Removidas referências a `/estabelecimento-categorias`
- [ ] Select de parcelas 1..36 + campos editáveis por parcela + validação do total
- [ ] Create parcelado materializa N transações (sem input de parcela_atual)
- [ ] Excluir grupo de compra quando houver `compra_grupo_id`
