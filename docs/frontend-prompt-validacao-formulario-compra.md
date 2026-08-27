# Prompt — Frontend: Validação visual do formulário de compra

Use este prompt no repositório do **frontend**. Complementa [`frontend-prompt-compras.md`](frontend-prompt-compras.md) e [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md). **Não substitui** esses prompts.

A API já existe e **não muda**. O back já recusa o POST com `422` + `{ "error": true, "message": "..." }`. O que falta é a **tela** apontar **quais campos** precisam ser preenchidos — hoje o submit some/falha sem `is-invalid` / `invalid-feedback`.

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

1. **Não** chamar `POST /transacoes/cadastrar` (nem `PUT /transacoes/editar` no mesmo form) se faltar campo obrigatório.
2. No submit inválido, marcar **todos** os campos faltando de uma vez (não só o primeiro).
3. Cada campo inválido recebe classe de erro do tema (`is-invalid` no input/select + `invalid-feedback` visível).
4. Focar / scrollar até o **primeiro** campo inválido.
5. Toast/alert global **não** substitui o destaque no campo. Pode existir **além** do destaque, não no lugar.

Não criar endpoint. Não inventar módulo `compras`.

---

## Onde aplicar (obrigatório)

O mesmo form de compra em **todos** os pontos de entrada:

| Tela / fluxo | Ação |
|--------------|------|
| Modal **Nova compra** | `POST /transacoes/cadastrar` |
| Página `/transacoes/add` (se existir) | idem |
| Editar compra (mesmo form) | `PUT /transacoes/editar` — só validar o que o update exige na UI |
| **Registrar esta compra** (Posso comprar? / simulador) | o mesmo `POST /transacoes/cadastrar` |

Se o tema for Bootstrap / Metronic / Keen: usar `was-validated` no `<form>` **ou** `is-invalid` campo a campo. Se for outro kit (MUI, Ant, PrimeReact, etc.), usar o equivalente visual de **erro no campo** + texto abaixo. O nome da classe pode mudar; o comportamento não.

`required` nativo do HTML **sozinho não basta** se o form tiver `novalidate`, se o botão não for `type="submit"`, ou se selects custom (Select2, Vue Select, React Select) ignorarem HTML5. Tratar esses componentes explicitamente.

---

## Campos obrigatórios no cadastro (create)

Validar **no front**, com os nomes da API. Mensagem abaixo do campo (PT).

| UI | API | Quando é inválido | Mensagem sugerida |
|----|-----|-------------------|-------------------|
| Descrição da compra | `observacoes` | vazio / só espaços | Informe a descrição da compra |
| Valor total | `valor_compra` | vazio, `0`, não numérico | Valor da compra é obrigatório |
| Data da compra | `data` | vazio | Informe a data da compra |
| Cartão | `cartao_id` | vazio **e** sem `fatura_id` | Cartão é obrigatório |
| Final do cartão | `cartao_numero_id` | cartão com **2+** finais e nada selecionado | Selecione o cartão (final) da compra |
| Origem da compra | `origem_compra` | vazio / opção placeholder | Origem da compra é obrigatória |
| Parcelas (N > 1) | `parcelas[]` | alguma parcela sem valor | Valor da parcela {n} é obrigatório |
| Total das parcelas | — (só UI) | soma ≠ `valor_compra` (± R$ 0,01) | A soma das parcelas deve ser igual ao valor da compra |

**Não** marcar como obrigatórios (não pôr `is-invalid` se vazios):

- Categoria / subcategoria
- Responsável (default “Eu”; se o UI já preenche, ok)
- É assinatura (`eh_assinatura`)
- Estabelecimento — **não aparece** no cadastro manual
- Fatura (`fatura_id`) — opcional; o back deriva pelo ciclo

`tipo`: o front envia sempre `"purchase"` — não é campo de tela.

### Final do cartão (condicional)

Regra já documentada em [`frontend-prompt-compras.md`](frontend-prompt-compras.md):

- **0 finais** no cartão → não submeter; CTA para cadastrar final (cadastro rápido). Campo/aviso visível, não silêncio.
- **1 final** → ocultar o select e enviar o id sozinho (não marcar inválido).
- **2+ finais** → select **obrigatório**; vazio → `is-invalid`.

### Edit

No `PUT /transacoes/editar`, não reexigir campos que o back aceita omitir (parcial). Se o campo estiver visível e o usuário **apagar** um obrigatório (descrição, valor, origem, cartão/final quando aplicável), a mesma marcação `is-invalid` vale.

---

## Comportamento no submit

```
clique em Salvar / Cadastrar / Registrar esta compra
  → validar todos os campos da tabela
  → se inválido:
       adicionar is-invalid em cada um
       mostrar invalid-feedback
       form.was-validated (se o tema usar)
       focus + scroll no primeiro inválido
       NÃO chamar a API
  → se válido:
       limpar erros
       POST/PUT
```

Regras:

1. Validar **todos** de uma vez. Não parar no primeiro (o back para no primeiro 422; o front não deve copiar isso).
2. Ao corrigir um campo (input/change/blur), **remover** `is-invalid` daquele campo. Não esperar um novo submit.
3. Select custom: ligar o estado de erro na wrapper (`form-group`, `fv-row`, etc.) — o `is-invalid` no `<input type="hidden">` invisível não aparece.
4. Textarea de descrição: trim. `"   "` é inválido.
5. Valor: aceitar `"249,90"` / `"1.200,00"` / número. Vazio, `null`, `""` e `0` (se a UI tratar 0 como “não informado”) → inválido no create.
6. Data: o back aceita omitir e usa hoje; **na UI do cadastro manual a data é obrigatória** (já está na tabela do prompt de cadastro). Default = hoje no open do modal **não** isenta de `is-invalid` se o usuário apagar.
7. Loading no botão só depois da validação client passar.
8. `Enter` no input dispara o mesmo fluxo (não burlar a validação).

---

## 422 da API (complemento, não substituto)

Envelope (inalterado):

```json
{
  "error": true,
  "message": "Origem da compra é obrigatória"
}
```

**Não** existe `{ "errors": { "origem_compra": ["..."] } }`.

Depois que o client validou e mesmo assim veio 422:

1. Mostrar `message` (toast ou alert).
2. **Se** a mensagem bater na tabela abaixo, marcar também o campo.

| `message` (exata) | Campo a marcar |
|-------------------|----------------|
| `Cartão é obrigatório` | `cartao_id` |
| `Informe a descrição da compra` | `observacoes` |
| `Valor da compra é obrigatório` | `valor_compra` |
| `Origem da compra é obrigatória` | `origem_compra` |
| `Origem da compra inválida` | `origem_compra` |
| `Selecione o cartão (final) da compra` | `cartao_numero_id` |
| `Cadastre ao menos um final de cartão neste cartão/bandeira` | `cartao_numero_id` (aviso + CTA rápido) |
| `Cartão (final) inválido para esta compra` | `cartao_numero_id` |
| `Quantidade de parcelas deve ser entre 1 e 36` | `parcelas_total` |
| `A soma das parcelas (*) deve ser igual ao valor da compra (*)` | bloco de parcelas + `valor_compra` |
| `Valor da parcela {n} é obrigatório` | input da parcela n |
| `Parcela {n} duplicada` / `Parcela {i} não informada` / `Número da parcela inválido` | bloco de parcelas |
| `A quantidade de parcelas informadas deve ser igual a parcelas_total` | bloco de parcelas |
| `Valor inválido` | `valor_compra` (e parcela se for o caso) |
| `Subcategoria exige categoria informada` | `subcategoria_id` / `categoria_id` |
| `Subcategoria não está vinculada à categoria informada` | `subcategoria_id` |

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
    <label class="required">Origem da compra</label>
    <select name="origem_compra" class="form-select" required>
      <option value="">Selecione</option>
      <!-- lookups.origens_compra -->
    </select>
    <div class="invalid-feedback">Origem da compra é obrigatória</div>
  </div>
</form>
```

No JS do submit inválido:

- `form.classList.add('was-validated')` **ou**
- `el.classList.add('is-invalid')` em cada controle ruim

Labels com `required` (asterisco) nos campos da tabela; **sem** asterisco nos opcionais.

---

## O que não fazer

- Desabilitar o botão Salvar “para sempre” até tudo preenchido **sem** dizer o que falta — o clique deve **mostrar** os erros.
- Confiar só no toast do 422.
- Validar só no blur e deixar o botão enviar payload incompleto.
- Tratar select custom como válido quando o valor é `""`, `0`, `null` ou a option “Selecione”.
- Enviar o POST “para ver o erro do back” no lugar da validação local.

---

## Checklist de aceite

- [ ] Submit com form vazio **não** chama a API
- [ ] Todos os obrigatórios vazios ficam `is-invalid` (ou equivalente) **ao mesmo tempo**
- [ ] Cada um tem texto `invalid-feedback` visível (não só borda vermelha)
- [ ] Focus/scroll no primeiro inválido
- [ ] Corrigir o campo tira o erro daquele campo
- [ ] `origem_compra` e `cartao_id` (selects) também marcam, não só inputs de texto
- [ ] Final do cartão: inválido só quando 2+ finais e nada escolhido
- [ ] Parcelado: parcela sem valor e soma ≠ total marcam o bloco
- [ ] Asterisco / `required` só nos obrigatórios
- [ ] Mesmo comportamento no modal Nova compra **e** em Registrar esta compra
- [ ] 422 ainda mostra `message`; se a string bater na tabela, o campo também marca
- [ ] Fluxo feliz (tudo preenchido) inalterado: POST, redirect, conciliação, etc.

---

## Fora de escopo

- Mudar o envelope de erro da API (`errors` por campo)
- Validação de categoria/sub/responsável como obrigatórios
- Redesenhar o layout do form
