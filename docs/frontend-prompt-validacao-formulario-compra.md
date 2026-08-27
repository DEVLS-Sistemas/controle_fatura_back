# Prompt — Frontend: Validação visual do formulário de compra

Use este prompt no repositório do **frontend**. Complementa [`frontend-prompt-compras.md`](frontend-prompt-compras.md), [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md) e [`frontend-prompt-compra-rapida.md`](frontend-prompt-compra-rapida.md). **Não substitui** esses prompts.

A API já existe. O back recusa o POST com `422` + `{ "error": true, "message": "..." }` quando falta o **mínimo**. O que falta na tela é apontar **quais campos** precisam ser preenchidos — `is-invalid` / `invalid-feedback`.

**Compra rápida:** só 5 campos são obrigatórios. Origem, final do cartão e o resto **não** devem ficar vermelhos se vazios.

Copie o arquivo inteiro para o chat do front.

---

## Problema

Ao cadastrar uma compra (modal **Nova compra**, página `/transacoes/add`, edição, ou **Registrar esta compra** no Posso comprar?) e deixar campos obrigatórios vazios:

- o formulário **não** marca os campos
- o usuário **não** vê o que falta
- às vezes só um toast genérico, ou nada

Isso é bug de UX no front. O backend **não** devolve `errors` keyed por campo (não é o envelope Laravel `errors: { campo: ["msg"] }`). Por isso a validação **precisa ser no client**, no clique de salvar, **antes** do `POST`.

---

## Objetivo

1. **Não** chamar `POST /transacoes/cadastrar` (nem `PUT /transacoes/editar` no mesmo form) se faltar campo obrigatório **do modo rápido**.
2. No submit inválido, marcar **todos** os obrigatórios faltando de uma vez (não só o primeiro).
3. Cada campo inválido recebe classe de erro do tema (`is-invalid` no input/select + `invalid-feedback` visível).
4. Focar / scrollar até o **primeiro** campo inválido.
5. Toast/alert global **não** substitui o destaque no campo. Pode existir **além** do destaque, não no lugar.
6. **Não** exigir origem, final, categoria, responsável, fatura.

Não criar endpoint. Não inventar módulo `compras`.

---

## Onde aplicar (obrigatório)

O mesmo form de compra em **todos** os pontos de entrada:

| Tela / fluxo | Ação |
|--------------|------|
| Modal **Nova compra** (compra rápida) | `POST /transacoes/cadastrar` |
| Página `/transacoes/add` (se existir) | idem |
| Editar compra (mesmo form) | `PUT /transacoes/editar` — só validar o que o update exige na UI |
| **Registrar esta compra** (Posso comprar? / simulador) | o mesmo `POST /transacoes/cadastrar` |

Se o tema for Bootstrap / Metronic / Keen: usar `was-validated` no `<form>` **ou** `is-invalid` campo a campo. Se for outro kit (MUI, Ant, PrimeReact, etc.), usar o equivalente visual de **erro no campo** + texto abaixo. O nome da classe pode mudar; o comportamento não.

`required` nativo do HTML **sozinho não basta** se o form tiver `novalidate`, se o botão não for `type="submit"`, ou se selects custom (Select2, Vue Select, React Select) ignorarem HTML5. Tratar esses componentes explicitamente.

---

## Campos obrigatórios no cadastro (create) — compra rápida

Validar **no front**, com os nomes da API. Mensagem abaixo do campo (PT).

| UI | API | Quando é inválido | Mensagem sugerida |
|----|-----|-------------------|-------------------|
| Descrição da compra | `observacoes` | vazio / só espaços | Informe a descrição da compra |
| Valor total | `valor_compra` | vazio, `0`, não numérico | Valor da compra é obrigatório |
| Data da compra | `data` | vazio | Informe a data da compra |
| Cartão | `cartao_id` | vazio **e** sem `fatura_id` | Cartão é obrigatório |
| Parcelas | `parcelas_total` | vazio / fora de 1..36 | Quantidade de parcelas deve ser entre 1 e 36 |

`parcelas_total` default `1` no open do form — só marca inválido se o usuário apagar / mandar valor impossível.

**Não** marcar como obrigatórios (não pôr `is-invalid` se vazios):

- Final do cartão (`cartao_numero_id`) — opcional; 1 final o back auto-escolhe
- Origem da compra (`origem_compra`)
- Categoria / subcategoria
- Responsável (default “Eu”)
- É assinatura (`eh_assinatura`)
- Estabelecimento — **não aparece** no cadastro manual
- Fatura (`fatura_id`) — opcional; o back deriva pelo ciclo
- Valores por parcela (`parcelas[]`) — no modo rápido o back divide o total

`tipo`: o front envia sempre `"purchase"` — não é campo de tela.

### Exceção — 2+ bandeiras

Só se o cartão escolhido tiver **2+ bandeiras** e a pessoa não escolheu final nem bandeira: a API 422 `Selecione a bandeira da fatura`. Aí sim marcar o select de final/bandeira (mesmo no bloco rápido). Cartão com uma bandeira: ignorar.

### Mais detalhes visível

Se o usuário **abriu** os inputs de parcela e mexeu neles:

| UI | Quando é inválido | Mensagem sugerida |
|----|-------------------|-------------------|
| Parcela n | valor vazio | Valor da parcela {n} é obrigatório |
| Total das parcelas | soma ≠ `valor_compra` (± R$ 0,01) | A soma das parcelas deve ser igual ao valor da compra |

Origem preenchida com valor fora do enum → `Origem da compra inválida` (raro; o select só tem opções válidas).

### Edit

No `PUT /transacoes/editar`, não reexigir campos que o back aceita omitir (parcial). Se o campo estiver visível e o usuário **apagar** um obrigatório do rápido (descrição, valor, cartão), a mesma marcação `is-invalid` vale. Apagar origem/final **não** é erro.

---

## Comportamento no submit

```
clique em Salvar / Cadastrar / Registrar esta compra
  → validar os 5 do modo rápido (+ parcela/bandeira só nas exceções)
  → se inválido:
       adicionar is-invalid em cada um
       mostrar invalid-feedback
       form.was-validated (se o tema usar)
       focus + scroll no primeiro inválido
       NÃO chamar a API
  → se válido:
       limpar erros
       POST/PUT (omitir chaves vazias: origem, final, parcelas[])
```

Regras:

1. Validar **todos** os obrigatórios de uma vez. Não parar no primeiro (o back para no primeiro 422; o front não deve copiar isso).
2. Ao corrigir um campo (input/change/blur), **remover** `is-invalid` daquele campo. Não esperar um novo submit.
3. Select custom: ligar o estado de erro na wrapper (`form-group`, `fv-row`, etc.) — o `is-invalid` no `<input type="hidden">` invisível não aparece.
4. Textarea de descrição: trim. `"   "` é inválido.
5. Valor: aceitar `"249,90"` / `"1.200,00"` / número. Vazio, `null`, `""` e `0` (se a UI tratar 0 como “não informado”) → inválido no create.
6. Data: o back aceita omitir e usa hoje; **na UI a data é obrigatória**. Default = hoje no open do modal **não** isenta de `is-invalid` se o usuário apagar.
7. Loading no botão só depois da validação client passar.
8. `Enter` no input dispara o mesmo fluxo (não burlar a validação).
9. **Não** tratar `origem_compra === ""` nem `cartao_numero_id` vazio como erro no create.

---

## 422 da API (complemento, não substituto)

Envelope (inalterado):

```json
{
  "error": true,
  "message": "Valor da compra é obrigatório"
}
```

**Não** existe `{ "errors": { "valor_compra": ["..."] } }`.

Depois que o client validou e mesmo assim veio 422:

1. Mostrar `message` (toast ou alert).
2. **Se** a mensagem bater na tabela abaixo, marcar também o campo.

| `message` (exata) | Campo a marcar |
|-------------------|----------------|
| `Cartão é obrigatório` | `cartao_id` |
| `Informe a descrição da compra` | `observacoes` |
| `Valor da compra é obrigatório` | `valor_compra` |
| `Origem da compra inválida` | `origem_compra` (só se a pessoa **enviou** origem) |
| `Selecione a bandeira da fatura` | final / bandeira |
| `Cartão (final) inválido para esta compra` | `cartao_numero_id` |
| `Quantidade de parcelas deve ser entre 1 e 36` | `parcelas_total` |
| `A soma das parcelas (*) deve ser igual ao valor da compra (*)` | bloco de parcelas + `valor_compra` |
| `Valor da parcela {n} é obrigatório` | input da parcela n |
| `Parcela {n} duplicada` / `Parcela {i} não informada` / `Número da parcela inválido` | bloco de parcelas |
| `A quantidade de parcelas informadas deve ser igual a parcelas_total` | bloco de parcelas |
| `Valor inválido` | `valor_compra` (e parcela se for o caso) |
| `Subcategoria exige categoria informada` | `subcategoria_id` / `categoria_id` |
| `Subcategoria não está vinculada à categoria informada` | `subcategoria_id` |

`Origem da compra é obrigatória` **não existe mais** na API. Não mapear, não exigir no form.

`Selecione o cartão (final) da compra` e `Cadastre ao menos um final de cartão neste cartão/bandeira` **não existem mais** no create. Não bloquear o Salvar por isso.

Se a mensagem não estiver na tabela: toast com `message`, sem fingir campo.

404 (`Cartão não encontrado`, `Categoria não encontrada`, …): toast; não é “campo vazio”.

---

## Exemplo de marcação (Bootstrap / Metronic)

O HTML abaixo é referência. Adaptar ao componente real (Vue, React, Blade, etc.).

```html
<form class="needs-validation" novalidate>
  <div class="fv-row mb-5">
    <label class="required">Descrição da compra</label>
    <textarea
      name="observacoes"
      class="form-control"
      required
      placeholder="Ex.: Mouse Logitech"
    ></textarea>
    <div class="invalid-feedback">Informe a descrição da compra</div>
  </div>

  <div class="fv-row mb-5">
    <label class="required">Valor total</label>
    <input name="valor_compra" class="form-control" required />
    <div class="invalid-feedback">Valor da compra é obrigatório</div>
  </div>

  <div class="fv-row mb-5">
    <label class="required">Cartão</label>
    <select name="cartao_id" class="form-select" required>
      <option value="">Selecione</option>
    </select>
    <div class="invalid-feedback">Cartão é obrigatório</div>
  </div>

  <!-- origem e final: SEM required, SEM asterisco -->
</form>
```

No JS do submit inválido:

- `form.classList.add('was-validated')` **ou**
- `el.classList.add('is-invalid')` em cada controle ruim

Labels com `required` (asterisco) **somente** nos 5 do modo rápido.

---

## O que não fazer

- Desabilitar o botão Salvar “para sempre” até tudo preenchido **sem** dizer o que falta — o clique deve **mostrar** os erros.
- Confiar só no toast do 422.
- Validar só no blur e deixar o botão enviar payload incompleto.
- Tratar select custom como válido quando o valor é `""`, `0`, `null` ou a option “Selecione” — **só no cartão** (obrigatório). Origem/final vazios são válidos.
- Exigir origem ou final para “completar” o form rápido.
- Enviar o POST “para ver o erro do back” no lugar da validação local.

---

## Checklist de aceite

- [ ] Submit com os 5 rápidos vazios **não** chama a API
- [ ] Os 5 vazios ficam `is-invalid` (ou equivalente) **ao mesmo tempo**
- [ ] Cada um tem texto `invalid-feedback` visível (não só borda vermelha)
- [ ] Focus/scroll no primeiro inválido
- [ ] Corrigir o campo tira o erro daquele campo
- [ ] `cartao_id` (select) também marca; origem/final vazios **não**
- [ ] Parcelado rápido: não exige `parcelas[]`; se Mais detalhes tiver parcela vazia, aí marca
- [ ] Asterisco / `required` só nos 5 do modo rápido
- [ ] Mesmo comportamento no modal Nova compra **e** em Registrar esta compra
- [ ] 422 ainda mostra `message`; se a string bater na tabela, o campo também marca
- [ ] Fluxo feliz (mínimo preenchido) inalterado: POST, redirect, conciliação, etc.

---

## Fora de escopo

- Mudar o envelope de erro da API (`errors` por campo)
- Validação de categoria/sub/responsável/origem/final como obrigatórios
- Redesenhar o layout do form (isso é o prompt de compra rápida)
