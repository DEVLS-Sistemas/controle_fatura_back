# Prompt — Frontend: Loja (nome fantasia) no Estabelecimento

Use este prompt no repositório do frontend para permitir associar um **nome de loja / fantasia** a vários estabelecimentos (máquinas diferentes do mesmo lugar).

Espelhar a UX do modal de **Responsável**: texto clicável → modal de busca/cadastro → vincular. Não precisa de select explícito na linha principal.

Referências:
- [`frontend-prompt-compras.md`](frontend-prompt-compras.md) § Responsável
- [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md)
- Backend: [`modules/lojas.md`](modules/lojas.md)

---

## Contexto

O nome que vem da fatura/PDF (`atacadao152145`, `atacadai4555`) é o **estabelecimento** (identificador da maquininha).

A **loja** é o nome real/fantasia que o usuário entende (`Atacadão`). Vários estabelecimentos podem apontar para a mesma loja.

---

## APIs (Bearer Sanctum)

Base: `/api/v1`

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/lojas/lojas-list?palavra_chave=` | Busca no modal |
| POST | `/lojas/cadastrar-rapido` | Find-or-create (+ vínculo opcional) |
| GET | `/lojas/listar` | Tela CRUD opcional |
| GET | `/lojas/listar/{id}` | Detalhe com estabelecimentos vinculados |
| PUT | `/lojas/editar` | Renomear / ativar |
| DELETE | `/lojas/excluir/{id}` | Soft-delete (desvincula estabelecimentos) |
| PUT | `/estabelecimentos/editar` | Vincular/desvincular `loja_id` |
| GET | `/estabelecimentos/listar` | Já retorna `loja_id`, `loja_nome` |
| GET | `/transacoes/listar` | Já retorna `loja_id`, `loja_nome`; filtro `loja_id` |

CRUD normal (`/cadastrar`, `/editar`) continua para tela de gestão; no fluxo inline use **sempre** `cadastrar-rapido`.

### Cadastro rápido

```http
POST /api/v1/lojas/cadastrar-rapido
```

```json
{
  "nome": "Atacadão",
  "estabelecimento_id": 12
}
```

- `nome` — obrigatório
- `estabelecimento_id` — opcional; se enviado, o backend já grava `loja_id` no estabelecimento
- Deduplica case-insensitive; soft-deleted → restaura
- Resposta: `loja.data`, `criado`, `message`

Alternativa em 2 passos (igual categoria):

1. `POST /lojas/cadastrar-rapido` só com `{ "nome": "Atacadão" }`
2. `PUT /estabelecimentos/editar` com `{ "id": 12, "loja_id": <id> }`

Preferir o atalho com `estabelecimento_id` no modal da tela de estabelecimento.

### Desvincular

```http
PUT /api/v1/estabelecimentos/editar
{ "id": 12, "loja_id": null }
```

---

## Onde implementar a UI

1. **Tela Estabelecimentos** (listagem + form create/edit) — principal
2. **Listagem de compras / fatura → transações** — mostrar `loja_nome` como texto secundário sob o estabelecimento (opcional no MVP)
3. **Tela CRUD de Lojas** (opcional) — útil para renomear e ver quantos estabelecimentos estão vinculados (`estabelecimentos_count`)

Não é obrigatório criar menu/tela de Lojas no MVP se o modal na tela de estabelecimentos cobrir o fluxo.

---

## UX — campo Loja no Estabelecimento (obrigatório)

Espelhar **Responsável** (não select na linha principal).

### Na listagem / card do estabelecimento

- Mostrar o nome da maquininha (`nome`) como título.
- Abaixo ou ao lado: label **Loja** com:
  - se vinculada: texto `loja_nome` (clicável)
  - se vazia: texto placeholder tipo “Definir loja” / “—” (clicável)

### Modal “Loja”

1. Input de busca com debounce → `GET /lojas/lojas-list?palavra_chave=`
2. Lista de resultados; ao clicar num item:
   - se já tem `estabelecimento.id`: `PUT /estabelecimentos/editar` `{ id, loja_id }` **ou** `POST /lojas/cadastrar-rapido` com `{ nome: item.nome, estabelecimento_id }` (reutiliza)
   - atualizar a linha com `loja_id` / `loja_nome`
3. Seção **Cadastrar nova**:
   - Input nome
   - Confirmar → `POST /lojas/cadastrar-rapido` com `{ nome, estabelecimento_id }`
   - Toast com `message` + distinguir `criado`
4. Ação **Remover vínculo** (só se já houver loja): `PUT .../editar` com `loja_id: null`

### Form create/edit de estabelecimento

- Mesmo padrão de texto + botão/modal.
- No create, pode guardar `loja_id` no state e enviar no `POST /estabelecimentos/cadastrar`.
- Ou cadastrar o estabelecimento primeiro e depois vincular pelo modal.

---

## Campos novos nas APIs existentes

### Estabelecimento (listar / listar/{id} / estabelecimentos-list)

| Campo | Tipo |
|-------|------|
| `loja_id` | number \| null |
| `loja_nome` | string \| null |

Filtros extras em `/estabelecimentos/listar`: `loja_id`, e `palavra_chave` também busca em `loja.nome`.

### Transação (listar / listar/{id})

| Campo | Tipo |
|-------|------|
| `loja_id` | number \| null |
| `loja_nome` | string \| null |

Filtro: `loja_id`. `palavra_chave` também busca em `loja.nome`.

---

## Fluxos resumidos

### Vincular loja a estabelecimento existente

```
[Clique em "Definir loja" / loja_nome]
  → modal busca ou digita "Atacadão"
  → POST /lojas/cadastrar-rapido { nome, estabelecimento_id }
  → atualiza linha (loja_id, loja_nome)
```

### Vários estabelecimentos → mesma loja

```
atacadao152145 → modal → "Atacadão"  (cria)
atacadai4555   → modal → busca "Atacad" → seleciona Atacadão  (reutiliza)
```

Ambos ficam com o mesmo `loja_id`.

### Exibir nas compras

Na coluna estabelecimento, mostrar:

```
atacadao152145
Atacadão          ← loja_nome (secundário / muted)
```

---

## Regras

| Regra | Detalhe |
|-------|---------|
| Cadastro rápido ≠ CRUD | No modal usar `cadastrar-rapido`, não `POST /cadastrar` |
| Deduplicação | Case-insensitive; toast distingue `criado` |
| Excluir loja | Backend desvincula estabelecimentos; não apaga estabelecimentos |
| Sem navegação obrigatória | Não redirecionar para `/lojas` no fluxo inline |
| Select estático | Evitar; preferir texto + modal como responsável |

---

## Checklist de aceite

- [ ] Na tela de estabelecimentos: campo Loja clicável (texto / “Definir loja”)
- [ ] Modal com busca (`lojas-list`) + cadastro rápido
- [ ] `POST /lojas/cadastrar-rapido` com `estabelecimento_id` vincula na hora
- [ ] Vários estabelecimentos podem apontar para a mesma loja
- [ ] Remover vínculo com `loja_id: null`
- [ ] Listagem de estabelecimentos mostra `loja_nome`
- [ ] (Opcional) Compras/fatura mostram `loja_nome` sob o estabelecimento
- [ ] Toast distingue criado vs reutilizado
- [ ] Sem select obrigatório de loja na linha principal
