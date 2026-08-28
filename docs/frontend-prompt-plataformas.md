# Prompt — Frontend: Plataforma de compra

Use este prompt no repositório do frontend para cadastrar **plataforma de compra** e usá-la na compra, na listagem e nos gráficos.

Backend **já implementado**. Não inventar enum: é cadastro por usuário, igual categoria.

---

## O que é

A **plataforma** diz *onde* a compra foi feita (app, marketplace ou loja física). É independente de:

| Campo | Papel |
|-------|--------|
| Categoria / subcategoria | *o que* (Alimentação → Pizzaria) |
| `origem_compra` | *canal* fechado: online, presencial, serviços, fatura |
| Estabelecimento / loja | *quem* vendeu (Pizzaria do João) |
| **Plataforma** | *por onde* comprou (iFood vs Loja Física vs Amazon) |

Exemplos:

```
Categoria: Alimentação
Subcategoria: Pizzaria
Origem: Compras online
Plataforma: iFood

Categoria: Alimentação
Subcategoria: Pizzaria
Origem: Compras presencial
Plataforma: Loja Física
```

Não inferir plataforma a partir da origem. Não esconder o select de origem.

---

## APIs (Bearer Sanctum)

Base: `/api/v1/plataformas`

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/lookups` | Paleta de cor (iguais categorias) |
| GET | `/listar` | Tela CRUD paginada |
| GET | `/listar/{id}` | Detalhe |
| POST | `/cadastrar` | Criar (422 se nome duplicado) |
| POST | `/cadastrar-rapido` | Find-or-create no modal da compra |
| PUT | `/editar` | Editar |
| DELETE | `/excluir/{id}` | Soft delete |
| GET | `/plataformas-list` | Async select |

Na compra:

- Lookups de transação já trazem `plataformas[]` (`id`, `nome`, `cor`)
- Create/edit: `plataforma_id` (opcional; omitir → herda `plataforma_padrao_id` do estabelecimento, se houver; senão `null`)
- Listagem: `plataforma_id`, `plataforma_nome`, `plataforma_cor`
- Import PDF: se o nome da maquininha casar (ex.: `Mercadolivre*Mercadol`, `Shopee *Raceplast`), a linha **já vem** com plataforma. Front só exibe. Prompt: [`frontend-prompt-plataforma-pelo-estabelecimento.md`](frontend-prompt-plataforma-pelo-estabelecimento.md)
- Filtro: `?plataforma_id=`
- Parcelada: `propagar_grupo: true` também propaga plataforma

Cadastro rápido no form: [`frontend-prompt-cadastro-rapido-plataforma.md`](frontend-prompt-cadastro-rapido-plataforma.md).

---

## Tela CRUD `/plataformas`

Espelhar **Categorias** (lista + form nome + quadrados de cor tema). Sem sub-entidade.

- Colunas: cor (quadrado), nome, ativo
- Padrões já vêm no cadastro do usuário (Loja Física, Mercado Livre, Shopee, Amazon, AliExpress, iFood, Magalu, Shein, Site da loja, Outros)
- Menu: perto de Categorias / Subcategorias. Rótulo: **Plataformas**

---

## Formulário de compra

Campo **Plataforma** em **Mais detalhes**, ao lado de Origem / Categoria (não no modo rápido).

- Select/async: `GET /plataformas/plataformas-list` **ou** `lookups.plataformas` das transações
- Placeholder: “Selecione”
- **Opcional** no create (compra rápida: omitir a chave)
- Ao escolher estabelecimento: se o campo estiver vazio, pré-selecionar `plataforma_padrao_id` (igual categoria)
- Botão **+** → cadastro rápido
- Chip/pill com `cor` + `nome` na opção

Valor inválido / id de outro usuário → 404 `"Plataforma não encontrada"`.

Payload (detalhes preenchidos):

```json
{
  "cartao_id": 1,
  "observacoes": "Pizza grande",
  "valor_compra": "89,90",
  "data": "2026-08-28",
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE",
  "categoria_id": 2,
  "subcategoria_id": 5,
  "plataforma_id": 6
}
```

---

## Listagem / visualização / fatura

- Coluna ou chip **Plataforma** (`plataforma_nome` + `plataforma_cor`). Sem id → omitir chip (não mostrar “—”)
- Filtro `plataforma_id` na toolbar
- Visualização da compra: linha **Plataforma** com `plataforma.nome` / `plataforma.cor` (omitir se `plataforma` for `null`)
- Na fatura → transações: mesmo select + botão + da categoria

---

## Gastos por categoria

Quarta rosca **Plataforma**, irmã da rosca de Origem.

Prompt: [`frontend-prompt-gastos-por-categoria-plataforma.md`](frontend-prompt-gastos-por-categoria-plataforma.md).

---

## Checklist de aceite

- [ ] Tela `/plataformas` (CRUD nome + cor tema)
- [ ] Select **Plataforma** no form de compra (Mais detalhes), opcional
- [ ] Pré-seleção via `plataforma_padrao_id` do estabelecimento; PDF já traz o chip preenchido
- [ ] Botão + de cadastro rápido
- [ ] Create/edit enviam `plataforma_id` só se preenchido (omitir no rápido)
- [ ] Listagem/filtro/visualização mostram a plataforma
- [ ] Parcelada: `propagar_grupo` inclui plataforma
- [ ] Não confundir com origem da compra (os dois campos coexistem)
- [ ] Rosca de plataforma em Gastos por categoria
