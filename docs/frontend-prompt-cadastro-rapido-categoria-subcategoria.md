# Prompt — Frontend: Cadastro rápido de Categoria e Subcategoria

Use este prompt no repositório do frontend para implementar botões de **cadastro fácil** de categoria e subcategoria no formulário de compra / tela de fatura → transações — no mesmo espírito do modal de **Responsável**.

---

## Contexto

Hoje o responsável já tem UX de “texto + botão → modal → cadastrar/selecionar → vincular na transação”.

Categoria e subcategoria precisam do mesmo padrão: quando o item **não existe**, o usuário **não** deve sair para outra tela. Ele abre o modal, digita o nome, o backend verifica/reutiliza se já existir, cadastra se não, e o front **já deixa selecionado** e **salva na transação**.

Referência de UX existente: [`frontend-prompt-compras.md`](frontend-prompt-compras.md) §4 (Responsável).

---

## APIs novas (Bearer Sanctum)

Base: `/api/v1`

| Método | Rota | Uso |
|--------|------|-----|
| POST | `/categorias/cadastrar-rapido` | Find-or-create de categoria |
| POST | `/subcategorias/cadastrar-rapido` | Find-or-create + vínculo com a categoria atual |
| PUT | `/transacoes/editar` | Vincular na compra já existente |
| POST | `/transacoes/cadastrar` | Incluir ids no create da compra |

CRUD normal (`/cadastrar`, `/editar`, `*-list`) continua para as telas de cadastro.

### Categoria — cadastro rápido

```http
POST /api/v1/categorias/cadastrar-rapido
```

```json
{
  "nome": "Assinaturas",
  "cor": "#14b8a6"
}
```

- `nome` — obrigatório (trim + espaços colapsados)
- `cor` — opcional (HEX tema). Omitida → preto `#000000`. Se a categoria já existir sem cor e o front enviar, o backend preenche; se já tiver cor, não sobrescreve
- Match **case-insensitive** por usuário (`"Alimentação"` = `"alimentação"`)
- Soft-deleted com o mesmo nome → restaura e reativa
- **Não** retorna 422 por duplicidade — reutiliza

**Resposta:**

```json
{
  "categoria": {
    "data": {
      "id": 10,
      "user_id": 1,
      "nome": "Assinaturas",
      "cor": "#14b8a6",
      "ativo": true
    },
    "status": true,
    "criado": true,
    "message": "Categoria cadastrada com sucesso!"
  }
}
```

- `criado: true` → acabou de criar
- `criado: false` → já existia (reutilizada)

Cores tema: `GET /api/v1/categorias/lookups` → `temas[]` / `cores[]` / `cor_padrao` (`#000000`). Quadrados: [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md).

### Subcategoria — cadastro rápido

```http
POST /api/v1/subcategorias/cadastrar-rapido
```

```json
{
  "nome": "Feira do Mês",
  "categoria_id": 2
}
```

- `nome` — obrigatório
- `categoria_id` — obrigatório (categoria **já selecionada** no formulário)
  - aceita também `categoria_ids: [2]` (usa o primeiro)
- Match **case-insensitive** por usuário (nome único global do usuário, não por categoria)
- Se a subcategoria **já existir** com outro vínculo: **não falha** — adiciona o vínculo com a categoria atual (`syncWithoutDetaching`) e devolve o registro
- Soft-deleted → restaura, reativa e vincula

**Resposta:**

```json
{
  "subcategoria": {
    "data": {
      "id": 5,
      "nome": "Feira do Mês",
      "ativo": true,
      "categorias": [{ "id": 2, "nome": "Alimentação", "cor": "#ef4444" }],
      "categoria_ids": [2]
    },
    "status": true,
    "criado": false,
    "message": "Subcategoria já cadastrada — reutilizada e vinculada à categoria."
  }
}
```

### Vincular na transação

Após obter o `id`, persistir na compra:

**Transação já existente** (listagem / fatura view / editar):

```http
PUT /api/v1/transacoes/editar
```

```json
{
  "id": 123,
  "categoria_id": 10,
  "subcategoria_id": 5
}
```

Regras do backend (já existentes):
- Subcategoria exige `categoria_id` e vínculo N:N válido
- Limpar categoria limpa subcategoria
- Se o estabelecimento ainda **não** tem padrão, a primeira categorização vira padrão e preenche linhas vazias
- Em compra parcelada: enviar `propagar_grupo: true` se quiser aplicar categoria/subcategoria a todas as parcelas (diferente do responsável, que já propaga sozinho)

**Nova compra** (formulário create): após o cadastro rápido, só setar o select / state (`categoria_id` / `subcategoria_id`) e enviar no `POST /transacoes/cadastrar` — **não** chamar `editar` ainda.

---

## Onde implementar a UI

1. **Formulário de nova/editar compra** (tela global de transações)
2. **Fatura → listagem de transações** (add/edit inline ou modal da linha)
3. Qualquer outro ponto que já edite `categoria_id` / `subcategoria_id`

Espelhar o padrão visual do botão/modal de **Responsável**.

---

## UX — Categoria (obrigatório)

### Campo

- Select/async de categoria (como hoje), com pré-seleção via `categoria_padrao_id` do estabelecimento.
- Ao lado (ou dentro do select): botão **“+” / “Nova categoria”**.

### Modal “Nova categoria”

1. Input **Nome** (obrigatório).
2. **Cor tema** opcional: os mesmos quadrados de [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md) (hover = HEX). Default preto **já com anel** se omitir. Clique **tem** que destacar o quadrado (anel 2px). Sem `<select>` de HEX. O modal da **fatura** usa o mesmo componente — ver item 2 em [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md).
3. Feedback em tempo real opcional: `GET /categorias/categorias-list?palavra_chave=` para sugerir existentes (não é obrigatório; o `cadastrar-rapido` já deduplica).
4. Confirmar → `POST /categorias/cadastrar-rapido`.
5. Com a resposta:
   - Inserir opção no select local (se ainda não estiver).
   - **Selecionar** `categoria.data.id`.
   - Limpar subcategoria se a categoria mudou.
6. Se a compra **já existe** (`id` conhecido): `PUT /transacoes/editar` com `{ id, categoria_id }` (e `subcategoria_id: null` se trocou a categoria).
7. Toast: usar `message` + distinguir `criado` (criada vs reutilizada).

### Erros

- Nome vazio → 422 `"O nome da categoria é obrigatório"` — validar no front antes.
- Demais erros: exibir `message` do JSON.

---

## UX — Subcategoria (obrigatório)

### Campo

- Select/async filtrado por categoria: `GET /subcategorias/subcategorias-list?categoria_id={id}`.
- **Desabilitado** sem categoria selecionada.
- Botão **“+” / “Nova subcategoria”** — **desabilitado** sem categoria (tooltip: “Selecione uma categoria antes”).

### Modal “Nova subcategoria”

1. Mostrar (read-only) a categoria atual: nome/cor.
2. Input **Nome** (obrigatório).
3. Confirmar → `POST /subcategorias/cadastrar-rapido` com `{ nome, categoria_id }`.
4. Com a resposta:
   - Recarregar ou inserir no select de subcategorias da categoria atual.
   - **Selecionar** `subcategoria.data.id`.
5. Se a compra já existe: `PUT /transacoes/editar` com `{ id, categoria_id, subcategoria_id }`.
6. Toast com `message` / `criado`.

### Erros

- Sem categoria → bloquear abertura do modal no front.
- Nome vazio → 422.
- Categoria inválida → 422 `"Uma ou mais categorias informadas são inválidas"`.

---

## Fluxos resumidos

### Compra já salva (ex.: fatura view)

```
[Botão + categoria]
  → modal digita nome
  → POST /categorias/cadastrar-rapido
  → PUT /transacoes/editar { id, categoria_id }
  → atualiza linha (categoria_nome, categoria_cor); limpa subcategoria na UI se mudou

[Botão + subcategoria] (exige categoria_id da linha)
  → modal digita nome
  → POST /subcategorias/cadastrar-rapido { nome, categoria_id }
  → PUT /transacoes/editar { id, categoria_id, subcategoria_id }
  → atualiza linha (subcategoria_nome)
```

### Formulário de nova compra (ainda sem id)

```
[Botão +]
  → cadastrar-rapido
  → setState no select
  → usuário conclui o form e POST /transacoes/cadastrar já com os ids
```

### Compra parcelada (edição)

- Categoria/subcategoria: oferecer checkbox “Aplicar a todas as parcelas da compra” → `propagar_grupo: true`. **Já vem marcado.** Só aparece se existir `compra_grupo_id`. Detalhe: item 3 em [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md).
- Após salvar, atualizar as linhas irmãs na listagem se propagou.

---

## Regras que o front deve respeitar

| Regra | Detalhe |
|-------|---------|
| Subcategoria sem categoria | Botão e select desabilitados |
| Trocar categoria | Limpar subcategoria no state |
| Cadastro rápido ≠ tela CRUD | Não redirecionar para `/categorias` ou `/subcategorias` |
| Deduplicação | Sempre usar `cadastrar-rapido` no modal (não `POST /cadastrar`, que retorna 422 em duplicata) |
| Prefixo async | Continuar usando `*-list` nos selects normais |
| Estabelecimento | Não chamar `PUT /estabelecimentos` — o backend aprende o padrão na 1ª categorização |
| Responsável | Manter o fluxo atual; este prompt só cobre categoria/subcategoria |

---

## Checklist de aceite

- [ ] Botão de cadastro rápido ao lado do select de **categoria** (form compra + fatura→transações)
- [ ] Botão de cadastro rápido ao lado do select de **subcategoria** (desabilitado sem categoria)
- [ ] Modal só com nome (+ quadrados de cor tema na categoria; default preto **já selecionado** com anel)
- [ ] `POST .../cadastrar-rapido` — nunca `POST .../cadastrar` nesse fluxo
- [ ] Após sucesso: item **selecionado** no select
- [ ] Compra existente: `PUT /transacoes/editar` persiste na hora
- [ ] Compra nova: ids vão no `POST /transacoes/cadastrar`
- [ ] Toast distingue criado vs reutilizado (`criado`)
- [ ] Trocar categoria limpa subcategoria
- [ ] Parceladas: checkbox `propagar_grupo` visível e **já marcado**
- [ ] Sem navegação para telas de cadastro completo nesse fluxo
