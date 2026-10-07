# Prompt — Frontend: Bandeira ao finalizar o lote do simulador

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: [CTLFAT-17](https://devlssistemas.atlassian.net/browse/CTLFAT-17).

Backend **não muda**. O `POST /api/v1/transacoes/cadastrar-lote` já recusa o item. O que falta é o form do `/simulador` pedir a bandeira **antes** do POST.

Complementa [`frontend-prompt-simulador-multiplas-compras.md`](frontend-prompt-simulador-multiplas-compras.md) e a exceção já descrita em [`frontend-prompt-compra-rapida.md`](frontend-prompt-compra-rapida.md). Não refazer o fluxo de somar / finalizar.

---

## Problema

**Finalizar** manda o lote sem bandeira nem final. Cartão com **uma** bandeira grava. Cartão com **duas ou mais** volta 422 e o lote inteiro é desfeito:

```json
{
  "error": true,
  "message": "Selecione a bandeira da fatura",
  "indice": 1
}
```

`indice` é a posição em `compras` (0 = primeiro). Exemplo real: Nubank (só Mastercard) passa; Sofisa (Visa e Mastercard) é o `indice: 1`.

O form do simulador hoje não mostra esse select, então a pessoa não tem como corrigir.

---

## Objetivo

1. No form de `/simulador`, se o cartão escolhido tiver **2+ bandeiras ativas**, mostrar o select **Bandeira** no mesmo bloco do cartão.
2. Sem bandeira escolhida, **Somar** e **Finalizar** não disparam. Marcar o select (`is-invalid`).
3. Cada item do `POST /transacoes/cadastrar-lote` desse cartão envia `cartao_bandeira_id`.
4. Cartão com **0 ou 1** bandeira: select some e a chave **não** vai no JSON.

Não criar endpoint. Não pedir final do cartão neste fluxo.

---

## Quando mostrar

Fonte: `GET /api/v1/transacoes/lookups` → `lookups.cartoes[]`. Cada cartão já traz `bandeiras[]` ativas, com `id` e `bandeira`.

| `bandeiras` ativas do cartão | Select | No item do lote |
|-------------------------------|--------|-----------------|
| 0 ou 1 | não mostrar | omitir `cartao_bandeira_id` |
| 2 ou mais | obrigatório, no form principal | `cartao_bandeira_id` = `bandeiras[].id` |

Trocar o cartão:

- passou a ter 2+: mostrar o select vazio (não pré-selecionar)
- passou a ter 1: esconder e descartar a bandeira que estava escolhida

Opções do select: `label` = `bandeiras[].bandeira` (Visa, Mastercard, …), `value` = `bandeiras[].id`.

Placeholder: `Selecione a bandeira`.

---

## Onde vale

O mesmo select nas três horas em que o cartão está na tela:

| Momento | O que fazer |
|---------|-------------|
| Form da simulação da vez | Select ao lado do cartão (ou logo abaixo). Não esconder em “Mais detalhes”. |
| **Somar com outra simulação** | Exigir a bandeira se o cartão tiver 2+. Guardar `cartao_bandeira_id` e o nome na linha da lista. |
| **Editar** a linha | Devolver o cartão **e** a bandeira no form. |
| **Finalizar** | Cada item com cartão de 2+ bandeiras leva `cartao_bandeira_id`. Validar a lista **e** a simulação da vez antes do POST. |

Linha da lista: se houver bandeira, mostrar o nome junto do cartão (`Sofisa · Visa`).

---

## Request

Contrato do item continua o da compra rápida. A única chave nova neste fluxo é `cartao_bandeira_id`, e só quando o select está visível e preenchido.

```json
{
  "compras": [
    {
      "cartao_id": 163,
      "observacoes": "Mouse",
      "valor_compra": "3.430,00",
      "data": "2026-09-25",
      "tipo": "purchase",
      "parcelas_total": 1,
      "responsavel_id": 14
    },
    {
      "cartao_id": 164,
      "cartao_bandeira_id": 115,
      "observacoes": "Teclado",
      "valor_compra": "330,00",
      "data": "2026-09-25",
      "tipo": "purchase",
      "parcelas_total": 1,
      "responsavel_id": 14
    }
  ]
}
```

No exemplo, `163` tem uma bandeira (chave omitida) e `164` tem duas (`115` = Visa, `124` = Mastercard). O id vem do lookup, não é fixo.

Campo vazio: **omitir** a chave. Não enviar `""` nem `null`.

Não enviar `cartao_numero_id` se a pessoa não escolheu final. A bandeira sozinha basta para o back gravar.

---

## Validação

Antes de **Somar** e antes de **Finalizar** (e antes da confirmação `N compras · R$ …`):

- cartão com 2+ bandeiras e select vazio → `is-invalid` + texto `Selecione a bandeira da fatura`
- não chamar a API
- se o item inválido estiver na lista, destacar a linha e pedir **Editar**
- ao escolher a bandeira, tirar o `is-invalid` na hora

Cartão com uma bandeira: não marcar erro de bandeira.

Se o POST ainda voltar 422 `Selecione a bandeira da fatura` com `indice`:

- toast com `message`
- destacar só o item desse índice (linha da lista, ou o form se for a simulação da vez)
- lista, veredito e form permanecem; nada foi gravado
- a pessoa escolhe a bandeira e finaliza de novo (POST do conjunto inteiro)

---

## Checklist

- [ ] Nubank (1 bandeira): sem select; o item do lote não tem `cartao_bandeira_id`; Finalizar grava
- [ ] Sofisa (Visa + Mastercard): select obrigatório no form; sem escolha, Somar e Finalizar não chamam a API
- [ ] Com bandeira escolhida, o item manda `cartao_bandeira_id` e o lote grava
- [ ] Trocar de Sofisa para um cartão de uma bandeira esconde o select e tira a chave
- [ ] Linha somada mostra a bandeira; Editar devolve a escolha
- [ ] 422 com `indice` destaca só aquele item e não limpa a lista

---

## Fora deste prompt

- Mudar o back ou o contrato do lote
- Pedir final do cartão (`cartao_numero_id`) no simulador
- Refazer Nova compra / compra rápida (a exceção de 2+ bandeiras já está no prompt dela)
- Conciliar, anexo ou estabelecimento no lote
