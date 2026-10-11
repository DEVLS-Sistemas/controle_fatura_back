# Prompt — Frontend: Lista e detalhe da fatura aparecem por partes (CTLFAT-52)

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md). O formato de cada campo **não muda**. O que muda é **quando** cada bloco chega e **qual URL** devolve grupos, quitação e conferência.

Não mudar layout, total, parser, paginação nem o loading do envio de anexo.

---

## Objetivo

Em `/faturas` e na fatura aberta, o que já carregou fica visível. O que falta fica em skeleton. A página inteira não espera o request mais lento.

---

## O que o backend passou a fazer

### Listagem

`GET /api/v1/faturas/listar` responde com as faturas da página **sem** unificar duplicatas no meio do request. A unificação roda depois da resposta. A query, os filtros e o formato de `data[]` continuam os mesmos — inclusive `pago`, `valor_pago` e `valor_restante` **em cada linha da lista**.

`GET /api/v1/faturas/faturas-list` (select assíncrono) não muda.

### Detalhe — cabeçalho

`GET /api/v1/faturas/listar/{id}` devolve só o cabeçalho: cartão, competência, `valor_total`, `status`, anexo e contadores (`total_transacoes`, `transacoes_com_categoria`).

**Não** vêm mais neste payload:

- `grupos_por_cartao`
- `pago`, `valor_pago`, `valor_restante`
- `pagamentos_total`, `pagamentos_abatido_anterior`, `pagamentos_antecipado`
- `valor_extrato`, `valor_nao_conciliado`, `valor_total_com_pendencias`, `tem_compras_nao_conciliadas`, `compras_nao_conciliadas_label`, `conferencia`

### Blocos que completam depois

Mesmos campos de antes, cada um na própria rota:

```http
GET /api/v1/faturas/listar/{id}/grupos
GET /api/v1/faturas/listar/{id}/quitacao
GET /api/v1/faturas/listar/{id}/conferencia
GET /api/v1/transacoes/listar?fatura_id={id}
```

`grupos` → `{ "grupos_por_cartao": [ ... ] }`

`quitacao` → `pago`, `valor_pago`, `valor_restante`, `pagamentos_total`, `pagamentos_abatido_anterior`, `pagamentos_antecipado`

`conferencia` → `valor_extrato`, `valor_nao_conciliado`, `valor_total_com_pendencias`, `tem_compras_nao_conciliadas`, `compras_nao_conciliadas_label`, `conferencia`

Lançamentos continuam só em `GET /transacoes/listar?fatura_id=`.

---

## Comportamento

### `/faturas`

- Filtros e a área da lista aparecem na hora. Não esperar lookups, lista e transações para a primeira pintura.
- As linhas entram quando `GET /faturas/listar` responder. Até lá, skeleton nas linhas — a página não fica em branco.
- Sair da rota pode abortar o request da lista.

### Fatura aberta

Disparar em paralelo, cada um com o próprio loading:

1. `GET /faturas/listar/{id}` — pintar cartão, competência, total e status assim que chegar.
2. `GET /faturas/listar/{id}/grupos` — skeleton nos grupos até chegar.
3. `GET /faturas/listar/{id}/quitacao` — skeleton na quitação até chegar.
4. `GET /faturas/listar/{id}/conferencia` — skeleton na conferência / pendências até chegar.
5. `GET /transacoes/listar?fatura_id={id}` — skeleton nos lançamentos até chegar.

Não esconder o cabeçalho enquanto grupos, quitação, conferência ou lançamentos ainda buscam. Um bloco que falhar não apaga os que já chegaram.

Poll de processamento (`processada` / `erro`) continua em `GET /faturas/listar/{id}` — `status` está no cabeçalho.

---

## Fora deste card

- Mudar o layout das telas.
- Mudar total, parser de PDF ou paginação.
- Loading do envio de anexo.

---

## Como testar

1. Abrir `/faturas` com a rede lenta. Filtros e a área da lista aparecem antes das linhas. As linhas entram quando a listagem responde.
2. Abrir uma fatura. Cartão, competência, total e status aparecem antes da lista de lançamentos.
3. Grupos, quitação e conferência preenchem cada um no seu tempo, sem recolocar a página em branco.
