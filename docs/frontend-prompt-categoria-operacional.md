# Prompt — Frontend: sem categoria em transação operacional

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: **CTLFAT-28**. Só na tela da fatura, na linha da transação.

Compra (`tipo === "purchase"`), com ou sem final de cartão, **continua** com categoria e subcategoria. Pix e nome de pessoa em Pagamentos e Financiamentos são compra.

---

## Problema

Pagamento, estorno, antecipação, encargo e saldo anterior não são compra. A linha não deve ter select de categoria nem de subcategoria, nem o botão de cadastro rápido.

Se a coluna precisar aparecer, o texto é o tipo, já fixo (`tipo_label`): Pagamento, Estorno, Antecipação, Encargo, Saldo anterior.

---

## O que o backend já faz

A listagem da fatura continua devolvendo `tipo`, `tipo_label` e `operacional`.

`operacional === true` quando `tipo` é `payment`, `refund`, `advance`, `fee` ou `carryover`.

Criar ou editar uma linha operacional grava `categoria_id` e `subcategoria_id` como `null`, mesmo se o body enviar categoria. Essa linha não vira categoria padrão do estabelecimento e não entra na contagem de transações categorizadas.

Compra continua gravando categoria e subcategoria.

---

## O que o front faz

1. Na fatura, se `operacional === true`, não mostrar select de categoria, select de subcategoria nem cadastro rápido desses campos.
2. Se a coluna de categoria existir nessa linha, mostrar só `tipo_label`, sem edição.
3. Compra (`operacional === false` ou `tipo === "purchase"`), inclusive sem cartão / Pix / nome de pessoa, mantém os selects.

Não esconder categoria em Pagamentos e Financiamentos quando a linha é compra. Não mudar o agrupamento Operacionais / cartão.

---

## Fora

- Categoria fixa no cadastro só para operacional.
- Tirar categoria de compra.

---

## Critérios

- [ ] Pagamento, estorno, antecipação, encargo e saldo anterior não abrem categoria
- [ ] Onde a coluna aparece, o texto é o `tipo_label`
- [ ] Compra sem cartão continua com categoria

## Como testar

Na fatura, abrir uma linha de pagamento e uma de saldo anterior: sem select de categoria. Abrir uma compra (inclusive Pix ou nome de pessoa): o select continua lá.
