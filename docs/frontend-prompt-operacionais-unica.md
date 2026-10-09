# Prompt — Frontend: uma só seção Operacionais na fatura

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: **CTLFAT-47**. Só a tela da fatura (`/faturas/view/{id}`).

Complementa [`frontend-prompt-fatura-pagamentos-financiamentos.md`](frontend-prompt-fatura-pagamentos-financiamentos.md). A ordem das faixas continua a mesma. O que muda é: estorno, pagamento, encargo, antecipação e saldo anterior **não** abrem Operacionais dentro do cartão.

---

## Problema

Na fatura Itaú 02/2023 (local `1197`) a tela mostra **duas** seções Operacionais.

1. Dentro do cartão final **2944**, só o estorno:
   - 07/09/2022 — ALIEXPRESS - TURISMO E ENTRETENIM.SAO PAULO — 5,74 — Estorno
2. A seção irmã **Operacionais**, depois dos cartões:
   - Valor do IOF — 297,07 — Encargo
   - 12/01/2023 — Pagamento Efetuado — 1.051,72 — Pagamento

O estorno tem `cartao_numero_id` do final 2944. Pagamento e encargo vêm sem cartão. A tela separa por isso e abre a segunda seção.

O total errado (**R$ 1.348,79** em vez de **R$ 1.051,72**) vem da API. O front **não** soma as linhas para montar o total.

---

## O que o backend faz

`GET /api/v1/transacoes/listar?fatura_id={id}` continua devolvendo `tipo`, `tipo_label`, `operacional`, `cartao_numero_id`, `ultimos_digitos` e `grupo_chave`.

Estorno com final ainda pode vir com `grupo_chave === "cartao"` e `cartao_numero_id` preenchido. Isso **não** autoriza uma Operacionais dentro do cartão.

`operacional === true` quando `tipo` é `payment`, `refund`, `advance`, `fee` ou `carryover`.

O total da tela é `valor_total` do detalhe (`GET /api/v1/faturas/listar/{id}`). Depois do reprocesso da 1197, a API devolve **1051.72**.

A linha “Valor do IOF” 297,07 some nesse reprocesso (não é lançamento desta fatura). O front não filtra essa linha à parte.

---

## O que o front faz

Uma única seção **Operacionais**, a irmã, depois dos cartões (e depois de Pagamentos e Financiamentos, se essa seção existir).

1. Linha com `operacional === true` entra nessa seção, **mesmo** com `cartao_numero_id` / `ultimos_digitos` e `grupo_chave === "cartao"`.
2. Não renderizar subtítulo nem bloco Operacionais dentro do `••••`.
3. Compra (`operacional === false` ou `tipo === "purchase"`) continua no cartão do final (ex.: FAST SHOP no final 8201). Compra sem cartão continua em Pagamentos e Financiamentos.
4. O número grande continua `valor_total` da API. Não somar `transacoes[]` nem `grupos_por_cartao`.

Na 1197, depois do back: uma Operacionais com o estorno de 5,74 e o pagamento de 1.051,72; total **R$ 1.051,72**.

---

## Fora

- Recalcular o total no browser.
- Esconder compra porque o nome parece operacional.
- Mudar Pagamentos e Financiamentos (só compras sem final).

---

## Critérios

- [ ] A fatura tem uma única seção Operacionais, a irmã, depois dos cartões
- [ ] O estorno de 5,74 da 1197 fica nessa seção, junto do pagamento
- [ ] O cartão final 2944 não abre Operacionais só por causa do final
- [ ] Compra permanece no cartão (FAST SHOP no final 8201)
- [ ] O total exibido é o `valor_total` da API (1197: **R$ 1.051,72** depois do reprocesso)

## Como testar

Abrir `/faturas/view/1197`. Conferir uma só Operacionais, com estorno e pagamento, e nenhuma Operacionais dentro do final 2944. A compra do final 8201 continua no cartão. O total é o `valor_total` da API.
