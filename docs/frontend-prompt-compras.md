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

Campos (além dos já existentes: valor, data, fatura/cartão, parcelas, tipo):

| Campo | UI |
|-------|-----|
| Estabelecimento | select/async obrigatório (`/estabelecimentos/estabelecimentos-list`) |
| Categoria | select opcional; ao escolher estabelecimento, **pré-selecionar** `categoria_padrao_id` |
| Subcategoria | select opcional; filtrar por categoria; pré-selecionar `subcategoria_padrao_id` se compatível |
| Observação | textarea opcional |
| Responsável | ver UX abaixo |

Regras UX:
- Ao trocar estabelecimento, reaplicar pré-seleção dos padrões **somente se** o usuário ainda não alterou categoria/subcategoria manualmente (ou sempre reaplica — escolha uma e documente; preferência: reaplicar ao trocar estabelecimento).
- Editar categoria/subcategoria na compra **não** chama update do estabelecimento.
- Subcategoria desabilitada sem categoria.
- Create payload sugerido:

```json
{
  "fatura_id": 1,
  "estabelecimento_id": 10,
  "valor": 150.9,
  "data": "2026-07-15",
  "tipo": "purchase",
  "categoria_id": 2,
  "subcategoria_id": 5,
  "responsavel_id": 1,
  "observacoes": "Feira do mês + ventilador"
}
```

Omitir `categoria_id`/`subcategoria_id`/`responsavel_id` no create faz o backend aplicar defaults.

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
