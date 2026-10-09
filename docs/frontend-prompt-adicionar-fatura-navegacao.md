# Prompt — Frontend: Adicionar fatura abre na hora (CTLFAT-49)

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Backend **sem alteração**. Sem endpoint novo.

Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) (listagem) e [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md) (formulário de cadastro).

Não mexer em velocidade da listagem, upload de anexo, parser nem no formulário em si. Só a **navegação** do botão **Adicionar fatura** na listagem (`/faturas` → `/faturas/add`).

---

## Objetivo

Em `/faturas`, o clique em **Adicionar fatura** vai na hora para `/faturas/add` e **permanece** lá, mesmo com a lista ainda carregando.

Hoje o clique volta para a listagem. A tela de cadastro só abre depois que a lista termina de carregar.

---

## O que o backend já faz

Nada novo. A listagem continua `GET /api/v1/faturas/listar`. O cadastro continua o fluxo já documentado. Este card não muda contrato, query nem resposta.

---

## Comportamento

- A navegação **não espera** `GET /faturas/listar` nem outro request da listagem.
- Request da listagem em andamento **não cancela** a rota `/faturas/add` e **não cobre** a tela de cadastro (overlay, redirect, `finally` que faz `push`/`replace` de volta para `/faturas`).
- O formulário de cadastro aparece na hora. Não fica atrás do loading da lista.
- Sair de `/faturas` pode abortar o request da lista. O abort **não** devolve o usuário para `/faturas`.

---

## Fora deste card

- Deixar a listagem mais rápida.
- Loading do envio do anexo.
- Campos, validação e modal do cadastro.

---

## Como testar

1. Abrir `/faturas` (rede lenta ou lista grande, para o loading durar).
2. Clicar em **Adicionar fatura** **antes** da lista terminar.
3. A URL fica `/faturas/add` e o formulário aparece na hora.
4. Quando a listagem responder (ou falhar / for abortada), a tela **continua** em `/faturas/add`. Não volta sozinha para `/faturas`.
