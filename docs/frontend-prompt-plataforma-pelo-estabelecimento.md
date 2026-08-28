# Prompt — Frontend: Plataforma pré-selecionada pelo estabelecimento

Use este prompt no repositório do frontend. Backend **já implementado**.

Quando o nome da maquininha parece uma plataforma conhecida, a compra **já chega com a plataforma marcada**. O front **não** reimplementa matching — só consome o que a API devolve.

---

## O problema

Na fatura o estabelecimento vem truncado, com `*`, sem espaço:

| Nome na fatura | Plataforma |
|----------------|------------|
| `Mercadolivre*Mercadol` | Mercado Livre |
| `Shopee *Raceplast` | Shopee |
| `IFOOD *PIZZARIA JOAO` | iFood |
| `Amazon Marketplace` | Amazon |
| `Aliexpress*LojaX` | AliExpress |
| `SHEIN*VESTIDO` | Shein |
| `Magazine Luiza*ABC` | Magalu |

Padaria, Atacadão, `Amazonas Turismo` **não** casam (falso positivo de Amazon).

A inferência acontece **no backend** (PDF não passa pelo form). O front só pré-seleciona e exibe.

---

## O que o backend já faz

1. Ao criar/encontrar o estabelecimento (`findOrCreateByNome` no import PDF, cadastro, create da compra), infere `plataforma_padrao_id` pelo nome.
2. Compra nova **sem** `plataforma_id` herda `estabelecimento.plataforma_padrao_id` (igual `categoria_padrao_id`).
3. Import PDF grava `plataforma_id` na linha. Match exato também preenche se a compra ainda estiver vazia.
4. Estabelecimentos/compras antigos: migration + `php artisan plataformas:inferir-estabelecimentos`.
5. O usuário ainda pode trocar a plataforma no form. Padrão do estabelecimento só muda se ainda estava vazio e o usuário escolheu uma plataforma (mesmo aprendizado da categoria).

**Não** inferir plataforma a partir de `origem_compra`. **Não** esconder o select. **Não** duplicar a lógica de alias (`mercadol`, `amzn`, etc.) no front.

---

## APIs (Bearer Sanctum) — campos novos

### Estabelecimento (`listar`, `listar/{id}`, `estabelecimentos-list`)

Já vêm:

| Campo | Uso |
|-------|-----|
| `plataforma_padrao_id` | id para pré-selecionar o select |
| `plataforma_padrao_nome` | chip / coluna |
| `plataforma_padrao_cor` | HEX do cadastro |

Lookups de estabelecimento agora incluem `plataformas[]` (`id`, `nome`, `cor`).

Create/edit de estabelecimento aceitam `plataforma_padrao_id` (opcional). Omitir no create → backend infere pelo nome. Enviar `null` no edit **limpa** o padrão (não reinfere).

Filtro opcional: `?plataforma_padrao_id=`

### Compra

Sem mudança de contrato. Já existem `plataforma_id`, `plataforma_nome`, `plataforma_cor`.

Depois do PDF, a linha **já vem preenchida**. Listagem/fatura/visualização só precisam **mostrar o chip**.

Formulário:

- Ao escolher estabelecimento (edit de lançamento do PDF, conciliação, etc.): se o select de plataforma estiver vazio, pré-selecionar `plataforma_padrao_id` — **mesmo padrão de `categoria_padrao_id`**.
- Ao trocar estabelecimento, reaplicar os três padrões (categoria, subcategoria, plataforma).
- Se a transação já tem `plataforma_id`, respeitar o valor da compra (não sobrescrever com o padrão ao abrir o edit).
- Compra rápida **sem** estabelecimento: omitir `plataforma_id` (como hoje).

---

## Onde implementar

### 1. Listagem de compras / fatura → transações (prioridade)

Chip **Plataforma** com `plataforma_nome` + `plataforma_cor`.

`Mercadolivre*Mercadol` importado **já deve** mostrar Mercado Livre, sem o usuário abrir o form.

Sem `plataforma_id` → omitir o chip (não mostrar “—”).

### 2. Formulário de compra (edit / Mais detalhes)

Campo Plataforma (já existe). Ao setar o estabelecimento:

```
se plataforma_id da compra está vazio
  e estabelecimento.plataforma_padrao_id está preenchido
    → selecionar essa plataforma
```

Espelhar o código que já faz isso para categoria/subcategoria. Não chamar endpoint extra.

`GET /estabelecimentos/estabelecimentos-list` já devolve os três `*_padrao_*`.

### 3. Tela Estabelecimentos

Coluna **Plataforma padrão** (quadrado de cor + nome), ao lado da categoria padrão.

No form create/edit: select Plataforma (lookups `plataformas` ou `GET /plataformas/plataformas-list`). Placeholder “Selecione / inferir pelo nome”.

- Create sem selecionar: backend infere (`Mercadolivre*Mercadol` → Mercado Livre).
- Edit: usuário pode corrigir (ex.: marcou Shopee à mão num nome ambíguo).
- Limpar: enviar `plataforma_padrao_id: null`.

Não precisa de botão “inferir” — o nome já dispara no create.

### 4. Visualização da compra

Linha Plataforma já prevista. Depois do PDF de marketplace, **não** deve ficar vazia se o nome casou.

---

## Exemplos de payload

Estabelecimento na listagem async:

```json
{
  "id": 88,
  "nome": "Mercadolivre*Mercadol",
  "loja_id": null,
  "loja_nome": null,
  "categoria_padrao_id": null,
  "categoria_padrao_nome": null,
  "categoria_padrao_cor": null,
  "subcategoria_padrao_id": null,
  "subcategoria_padrao_nome": null,
  "plataforma_padrao_id": 2,
  "plataforma_padrao_nome": "Mercado Livre",
  "plataforma_padrao_cor": "#fbbc04"
}
```

Compra na listagem após o PDF:

```json
{
  "id": 401,
  "estabelecimento": "Mercadolivre*Mercadol",
  "plataforma_id": 2,
  "plataforma_nome": "Mercado Livre",
  "plataforma_cor": "#fbbc04"
}
```

Outro:

```json
{
  "estabelecimento": "Shopee *Raceplast",
  "plataforma_id": 3,
  "plataforma_nome": "Shopee",
  "plataforma_cor": "#ee4d2d"
}
```

Create de estabelecimento sem plataforma (o back infere):

```json
{ "nome": "Mercadolivre*Mercadol" }
```

Override explícito:

```json
{ "id": 88, "plataforma_padrao_id": 9 }
```

---

## Regras de UX

- Pré-seleção é **sugestão**. O usuário pode trocar iFood → Loja Física na mesma pizzaria.
- Não inferir no cliente (“se nome includes shopee”). Nomes novos (Rappi, Temu) só casam se existirem no cadastro `/plataformas` do usuário — o back usa o nome cadastrado + aliases.
- Loja Física / Site da loja / Outros **nunca** são auto-vinculados pelo nome.
- Ao categorizar/escolher plataforma numa compra cujo estabelecimento **ainda não tem** padrão, o backend grava o padrão e preenche linhas vazias (igual categoria). Não chamar `PUT /estabelecimentos/editar` só por isso.
- Chip na listagem usa os campos da **transação** (`plataforma_*`), não os do estabelecimento.

---

## Checklist de aceite

- [ ] **Não** há matcher de nome no front
- [ ] `estabelecimentos-list` / listar estabelecimento usam `plataforma_padrao_id/nome/cor`
- [ ] Ao escolher estabelecimento no form, pré-selecionar plataforma se o campo estiver vazio — igual categoria
- [ ] Ao trocar estabelecimento, reaplicar categoria + sub + plataforma
- [ ] Edit de compra já preenchida **não** sobrescreve `plataforma_id` ao abrir
- [ ] Após import PDF, `Mercadolivre*Mercadol` e `Shopee *Raceplast` mostram o chip certo na fatura/listagem **sem** o usuário editar
- [ ] Tela Estabelecimentos: coluna e select de plataforma padrão
- [ ] Create de estabelecimento sem plataforma: o detalhe volta com o id inferido quando o nome casar
- [ ] Compra rápida continua omitindo `plataforma_id` se o usuário não abriu Mais detalhes
- [ ] Select de plataforma continua visível e editável (origem da compra não some)
