# Prompt — Frontend: Cadastro rápido de Cartão

Use este prompt no repositório do frontend para o botão **+** ao lado do select de **Cartão** no formulário de **Nova compra** (`/transacoes/add`) — o mesmo espírito do cadastro rápido de categoria/subcategoria e do modal de Responsável.

O usuário **não** deve sair para `/cartoes` no meio do cadastro da compra.

---

## Contexto

A compra precisa de `cartao_id`. O `cartao_numero_id` é **opcional** no create (compra rápida); se o modal rápido devolver o final, selecionar os dois. Sem final, o backend auto-seleciona se só houver 1; senão grava `null`.

Por isso o cadastro rápido cria de uma vez: **cartão + bandeira + final**.

Referência de UX: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md).  
Formulário de compra: [`frontend-prompt-compras.md`](frontend-prompt-compras.md) · [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).

---

## API (Bearer Sanctum)

```http
POST /api/v1/cartoes/cadastrar-rapido
```

**Não** usar `POST /cartoes/cadastrar` neste fluxo (esse retorna 422 em cenários de CRUD completo). O rápido **reutiliza** cartão com o mesmo nome.

Lookups (bandeiras, tipos, dias, cores): `GET /api/v1/cartoes/lookups`.

### Payload — cartão novo

```json
{
  "nome": "Nubank",
  "bandeira": "Mastercard",
  "ultimos_digitos": "1234",
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12
}
```

| Campo | Obrigatório | Notas |
|-------|-------------|-------|
| `nome` | sim (se não enviar `cartao_id`) | Trim; match case-insensitive por usuário |
| `bandeira` | sim | Valor de `lookups.bandeiras` (Visa, Mastercard, …) |
| `ultimos_digitos` | sim | 4 números (`"1234"`). Aceita `"12 34"` — o backend deixa só os dígitos |
| `dia_limite_fatura` | sim **só na criação** | 1–31 (fechamento do ciclo) |
| `dia_vencimento_fatura` | sim **só na criação** | 1–31 |
| `cartao_id` | não | Se enviado, **não cria grupo novo** — só inclui bandeira/final nesse cartão |
| `banco` | não | Default = `nome` |
| `tipo` | não | `fisico` (default na criação) \| `virtual` \| `adicional` |
| `apelido` | não | |
| `nome_no_cartao` | não | |
| `limite_credito` | não | |
| `cor_fundo` / `cor_texto` | não | Preset pelo nome/banco se omitido |
| `pessoa_id` | não | Titular |

### Payload — incluir final num cartão já selecionado

```json
{
  "cartao_id": 1,
  "bandeira": "Mastercard",
  "ultimos_digitos": "5678"
}
```

Dias **não** são obrigatórios (o grupo já existe). Use isso no CTA “Cadastre um final neste cartão” quando o select de cartão já tem valor e há 0 finais.

### Resposta

```json
{
  "cartao": {
    "data": {
      "id": 10,
      "cartao_id": 10,
      "cartao_numero_id": 44,
      "nome": "Nubank",
      "banco": "Nubank",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "cor_fundo": "#820ad1",
      "cor_texto": "#ffffff",
      "bandeiras": [
        {
          "id": 3,
          "bandeira": "Mastercard",
          "numeros": [
            { "id": 44, "ultimos_digitos": "1234", "tipo": "fisico" }
          ]
        }
      ]
    },
    "status": true,
    "criado": true,
    "message": "Cartão cadastrado com sucesso!"
  }
}
```

- `criado: true` → grupo novo
- `criado: false` → grupo já existia (reutilizado; o final foi incluído se faltava)
- `data.cartao_numero_id` → usar no select de final / no `POST /transacoes/cadastrar`
- Soft-deleted com o mesmo nome → restaura e reativa
- **Não** retorna 422 por duplicidade de nome

Erros comuns:

- `"O nome do cartão é obrigatório"`
- `"Bandeira é obrigatória"` / `"Bandeira inválida: …"`
- `"Últimos dígitos devem conter 4 números"`
- `"Dia limite da fatura é obrigatório"` (só cartão novo)

---

## Onde implementar

1. **`/transacoes/add`** (Nova compra) — **obrigatório**
2. Formulário de editar compra
3. Fatura → adicionar transação (opcional; na fatura o cartão já é o da fatura)

Espelhar o botão **+** de categoria.

---

## UX — select Cartão (obrigatório)

- Select de cartão como hoje (`lookups.cartoes` de `GET /transacoes/lookups` **ou** `GET /cartoes/cartoes-list`).
- Ao lado: botão **“+” / “Novo cartão”**.
- **Não** redirecionar para a tela de cartões.

### Modal “Novo cartão”

Campos (nessa ordem):

1. **Nome** — obrigatório (placeholder: `Ex.: Nubank`)
2. **Bandeira** — select `GET /cartoes/lookups` → `bandeiras[]`
3. **Final** — 4 dígitos, máscara numérica
4. **Dia limite da fatura** — select 1–31 (`lookups.dias`)
5. **Dia de vencimento** — select 1–31
6. Tipo (Físico / Virtual / Adicional) — opcional, default Físico

Confirmar → `POST /cartoes/cadastrar-rapido`.

Com a resposta:

1. Inserir o cartão no select local (`data.id`, chip com `cor_fundo` / `cor_texto`).
2. **Selecionar** `cartao_id = data.id`.
3. Atualizar opções de final com `data.bandeiras[].numeros[]`.
4. **Selecionar** `cartao_numero_id = data.cartao_numero_id`.
   - Se só houver 1 final, o campo de final pode continuar oculto (regra já existente).
5. Recalcular o preview da fatura (`dia_limite_fatura`).
6. Toast com `message` + `criado`.

Compra **nova** (ainda sem id): só atualizar o state; os ids vão no `POST /transacoes/cadastrar`.

Compra **já salva**: `PUT /transacoes/editar` `{ id, cartao_id, cartao_numero_id }` (`propagar_grupo: true` em parcelada).

### CTA “Cadastre um final neste cartão” (0 números)

Mesmo modal, com `nome` e dias **ocultos/read-only** (já escolheu o cartão). Enviar `cartao_id` + `bandeira` + `ultimos_digitos`.

---

## Fluxo resumido (`/transacoes/add`)

```
[Botão + cartão]
  → modal: nome, bandeira, final, dias
  → POST /cartoes/cadastrar-rapido
  → setState cartao_id + cartao_numero_id
  → usuário conclui o form e POST /transacoes/cadastrar
```

---

## Regras

| Regra | Detalhe |
|-------|---------|
| Cadastro rápido ≠ CRUD | Sempre `cadastrar-rapido`, nunca `POST /cartoes/cadastrar` no modal |
| Sem navegação | Não ir para `/cartoes` |
| Deduplicação | Mesmo nome → reutiliza (`criado: false`) |
| Final | Sempre gravar `cartao_numero_id` da resposta |
| 1 final | Ocultar o select de final depois de selecionar |
| Preview fatura | Usar `dia_limite_fatura` devolvido |
| Categoria / responsável | Continuar iguais; este prompt só cobre cartão |

---

## Checklist de aceite

- [ ] Botão **+** ao lado do Cartão em `/transacoes/add`
- [ ] Modal com nome, bandeira, final (4 dígitos), dia limite e vencimento
- [ ] `POST /cartoes/cadastrar-rapido` (não `/cadastrar`)
- [ ] Após sucesso: cartão **e** final selecionados (`cartao_id` + `cartao_numero_id`)
- [ ] Compra nova: ids no `POST /transacoes/cadastrar`
- [ ] Toast distingue criado vs reutilizado
- [ ] 0 finais no cartão escolhido: mesmo endpoint com `cartao_id`
- [ ] Sem redirecionar para a tela de cartões
- [ ] 422 mostra `message` da API
