# Prompt — Frontend: Compra rápida

Use este prompt no repositório do **frontend**. Complementa [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md), [`frontend-prompt-compras.md`](frontend-prompt-compras.md) e [`frontend-prompt-validacao-formulario-compra.md`](frontend-prompt-validacao-formulario-compra.md). **Não substitui** esses prompts.

Copie o arquivo inteiro para o chat do front. A API **já aceita** o payload mínimo. Não invente módulo `compras` nem endpoint novo.

---

## Problema

Quem registra uma compra “só para não esquecer” (e conciliar depois com a fatura) em geral **só sabe**:

- o que comprou
- quando
- no cartão Z
- o valor
- se foi parcelado (e em quantas vezes)

Hoje o form pede o resto (final do cartão, origem, categoria, valores parcela a parcela, …) como se fosse obrigatório. Isso trava o registro.

---

## Objetivo

O cadastro **abre em compra rápida**. Dá para salvar só com o mínimo. O restante fica **ali mesmo**, opcional, se a pessoa quiser completar agora.

Mesmo `POST /transacoes/cadastrar`. Mesma conciliação depois. Sem tela nova.

Pontos de entrada (todos):

- Modal **Nova compra**
- `/transacoes/add` (se existir)
- **Registrar esta compra** (Posso comprar? / simulador)

---

## O que é obrigatório (só isso)

| UI | API | Default | Notas |
|----|-----|---------|-------|
| Descrição da compra | `observacoes` | — | o que foi comprado |
| Valor | `valor_compra` | — | total da venda |
| Data | `data` | hoje | `Y-m-d` |
| Cartão | `cartao_id` | — | o grupo (Nubank, Inter, …) |
| Parcelas | `parcelas_total` | `1` | 1 = à vista; 2..36 |

`tipo`: sempre `"purchase"`.

**Não** exigir no modo rápido (omitir no JSON se vazio):

- Final do cartão (`cartao_numero_id`)
- Origem (`origem_compra`)
- Fatura (`fatura_id`)
- Categoria / subcategoria
- Responsável (back usa “Eu”)
- É assinatura
- `parcelas[]` (valores um a um) — o back divide o total igualmente

Asterisco / `required` **somente** nos 5 da tabela.

---

## Layout (obrigatório)

Um único form. **Não** duas rotas. **Não** dois POSTs.

```
Nova compra

  O que foi comprado *
  [ Mouse Logitech                    ]

  Valor *              Data *              Parcelas
  [ 249,90 ]           [ 27/08/2026 ]      [ 3x ▾ ]

  Cartão *
  [ Nubank ▾ ]  [+]

  [ Mais detalhes ]

  [ Cancelar ]  [ Salvar compra ]
```

- Título do modal/página continua **Nova compra**.
- Toggle/accordion **Mais detalhes** (fechado ao abrir). Texto do botão: **Mais detalhes** / **Ocultar detalhes**.
- Salvar habilitado com o mínimo; o clique com campo rápido vazio marca `is-invalid` (prompt de validação).
- Não esconder o Salvar até “completar o form longo”.

### Mais detalhes (opcional, mesmo POST)

Quando expandir, mostrar o que já existia no form completo:

| UI | API | Obrigatório? |
|----|-----|--------------|
| Final do cartão | `cartao_numero_id` | não (ver regra abaixo) |
| Origem da compra | `origem_compra` | não; placeholder “Selecione” |
| É assinatura | `eh_assinatura` | não |
| Fatura (1ª competência) | `fatura_id` | não; preview do ciclo continua visível |
| Categoria / sub | `categoria_id` / `subcategoria_id` | não |
| Responsável | `responsavel_id` | default Eu |
| Valores por parcela | `parcelas[]` | só se N > 1 **e** o usuário abriu os inputs para ajustar |

Se o usuário preencher algum detalhe, **enviar**. Se deixar em branco, **não enviar** a chave (`origem_compra: ""` não; omitir).

Estado do accordion: se já tiver detalhe preenchido (ex.: veio do simulador com responsável), pode abrir já expandido. Caso contrário, fechado.

---

## Payload — compra rápida (à vista)

```json
{
  "cartao_id": 1,
  "observacoes": "Mouse Logitech",
  "valor_compra": "249,90",
  "data": "2026-08-27",
  "tipo": "purchase",
  "parcelas_total": 1
}
```

## Payload — compra rápida (parcelada)

```json
{
  "cartao_id": 1,
  "observacoes": "Notebook",
  "valor_compra": "3.000,00",
  "data": "2026-08-27",
  "tipo": "purchase",
  "parcelas_total": 10
}
```

**Não** enviar `parcelas[]` no modo rápido. O back reparte 10x iguais (centavos na última).

## Payload — com detalhes (se a pessoa preencheu)

Igual ao prompt de cadastro manual: pode incluir `cartao_numero_id`, `origem_compra`, `categoria_id`, `responsavel_id`, `fatura_id`, `eh_assinatura`, `parcelas[]`.

---

## Regras de UI por campo extra

### Final do cartão

Não bloqueia o Salvar.

- **0 finais** — no modo rápido, **não** travar. CTA “Cadastre um final” só em **Mais detalhes** (opcional).
- **1 final** — back auto-seleciona; não mostrar o select.
- **2+ finais** — select em **Mais detalhes**, opcional. Placeholder “Selecione (opcional)”. Omitir a chave se vazio.
- Na **edição** da compra depois: o select de final continua visível para completar o que faltou.

Exceção: cartão com **2+ bandeiras** e nenhum final/bandeira escolhido → a API pode 422 `Selecione a bandeira da fatura`. Só nesse caso, mostrar bandeira **ou** final no bloco rápido (obrigatório). Cartão com uma bandeira (o caso comum) não pede isso.

### Origem

Select opcional. Não pré-selecionar. Não marcar `is-invalid` se vazio. Se preenchido, enviar o `value` de `lookups.origens_compra`.

### Parcelas

Modo rápido: só o select 1..36. Preview “10x de R$ 300,00” (split igual) é bem-vindo; **não** projetar 10 inputs.

Em Mais detalhes (N > 1): inputs por parcela + total deve bater com `valor_compra`. Se o usuário não abriu/não mexeu, não enviar `parcelas[]`.

### Fatura

Preview pelo ciclo (`dia_limite_fatura`) pode aparecer discreto abaixo do cartão **mesmo no modo rápido** (informação, não campo). O select para trocar a 1ª fatura fica em Mais detalhes.

---

## Depois de salvar (igual ao cadastro manual)

- `compra_manual: true`, `precisa_conciliar: true`, estabelecimento `—`
- Origem / final vazios: listagem e detalhe mostram **—** (não “Desconhecido”)
- Redirect `/compras/{compra_grupo_id || transacoes[0].id}`
- Completar origem, final, categoria: `PUT /transacoes/editar` (parcelada: `propagar_grupo: true` nos campos compartilhados)
- Conciliar com o PDF quando a fatura chegar — fluxo já documentado

---

## Validação (`is-invalid`)

Prompt: [`frontend-prompt-validacao-formulario-compra.md`](frontend-prompt-validacao-formulario-compra.md).

No modo rápido, **só** os 5 obrigatórios. Não marcar origem/final vazios.

---

## Checklist de aceite

- [ ] Nova compra abre em **compra rápida** (5 campos). Mais detalhes fechado
- [ ] Dá para salvar só com descrição + valor + data + cartão + parcelas
- [ ] POST **não** manda `origem_compra` nem `cartao_numero_id` se a pessoa não preencheu
- [ ] Parcelado rápido: só `parcelas_total`, sem `parcelas[]`
- [ ] Mais detalhes no mesmo modal: final, origem, fatura, categoria, responsável, valores por parcela
- [ ] Preencher detalhe e salvar envia esses campos no mesmo POST
- [ ] 0 ou 2+ finais **não** bloqueiam o Salvar no modo rápido (exceto 2+ bandeiras sem escolha)
- [ ] Submit vazio: `is-invalid` só nos 5 obrigatórios
- [ ] Compra nasce `compra_manual` / precisa conciliar; estabelecimento —
- [ ] Registrar esta compra (simulador) usa o mesmo form rápido
- [ ] Edição posterior ainda permite completar final/origem/categoria

---

## Fora de escopo

- Endpoint `/compras/rapida` ou flag `compra_rapida` no JSON
- Inferir origem ou final sozinho além da auto-seleção de **1** final / **1** bandeira
- Tornar origem/final obrigatórios de novo
