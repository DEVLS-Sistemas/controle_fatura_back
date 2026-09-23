# Simulador de várias compras — etapa 2 (concluir)

Card: [CTLFAT-17](https://devlssistemas.atlassian.net/browse/CTLFAT-17). A etapa 1 (lista + overlay) é o [CTLFAT-18](https://devlssistemas.atlassian.net/browse/CTLFAT-18).

Prompt do front: [`frontend-prompt-simulador-multiplas-compras.md`](../frontend-prompt-simulador-multiplas-compras.md).

## `POST /api/v1/transacoes/cadastrar-lote`

```json
{ "compras": [ { "...": "mesmo corpo de POST /transacoes/cadastrar" } ] }
```

- 1..20 itens. 0, 21+ ou `compras` que não seja lista: **422** sem `indice`, mensagem `Envie entre 1 e 20 compras`.
- Cada item reusa `createTransacao` (compra rápida inclusa) **dentro de uma transação**.
- Item inválido: rollback, **nada** gravado. Corpo `{ "error": true, "message": "...", "indice": 0 }`. `indice` é a posição na lista (0 = primeiro).
- 200: `{ "compras": [ { "transacao": { "data", "status", "message" } } ] }` na mesma ordem. Cada `transacao` é o retorno de `POST /cadastrar`.
- `compra_manual: true`, `precisa_conciliar: true` enquanto não conciliar. Parcelas da mesma compra compartilham `compra_grupo_id`; o lote não junta compras diferentes num grupo só.
- Dono = usuário do token. `user_id` no item é ignorado.
- Estabelecimento só se o item enviar `estabelecimento_id` ou `estabelecimento`. A descrição **não** cria estabelecimento.

Fora: anexos no lote, conciliar no mesmo request, `POST /dashboard/simular-compra`, persistir a lista de simulação.
