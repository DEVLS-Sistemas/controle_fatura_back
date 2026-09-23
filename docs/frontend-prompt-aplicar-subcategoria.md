# Prompt — Frontend: aplicar subcategoria do mesmo estabelecimento

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: **CTLFAT-25**. Só na tela da fatura, ao gravar categoria ou subcategoria de uma transação.

Base: `PUT /api/v1/transacoes/editar` (Bearer Sanctum).

O checkbox atual de parcelas (`propagar_grupo`) continua como está. Esta pergunta é outra.

---

## Problema

Gravar só a categoria, ou categoria e subcategoria, numa compra não passa essa escolha para as outras linhas do mesmo estabelecimento nesta fatura. Parcelas da mesma compra em outras faturas também ficam de fora, a menos que o usuário marque `propagar_grupo`.

---

## O que o front faz

1. Salvar a categoria, com ou sem subcategoria, **sem** a flag nova. Só essa linha muda.
2. Se a resposta pedir, abrir a pergunta **nesta tela**. Vale também quando o usuário escolheu só a categoria.
3. O texto diz que as outras faturas da mesma compra parcelada também recebem essa escolha, e mostra as duas contagens.
4. **Confirmar** reenvia o mesmo `PUT` com a flag. Atualizar as linhas visíveis desta fatura.
5. **Cancelar** não chama a API de novo. Fica só a linha editada.

Não perguntar quando não houver outra compra do mesmo estabelecimento nesta fatura. Não perguntar fora da tela da fatura. Com subcategoria, o back não altera linha que já tem subcategoria. Só com categoria, não altera linha que já tem categoria.

---

## Resposta do primeiro `PUT`

O body leva `id` e `categoria_id`. `subcategoria_id` pode ir junto ou `null`. **Sem** `aplicar_subcategoria_estabelecimento`.

Quando há outras compras do mesmo estabelecimento nesta fatura ainda sem essa classificação, a resposta inclui `transacao.aplicar_subcategoria`:

```json
{
  "transacao": {
    "status": true,
    "message": "Transação alterada com sucesso!",
    "aplicar_subcategoria": {
      "perguntar": true,
      "estabelecimento_id": 104,
      "estabelecimento_nome": "Shopee",
      "linhas_nesta_fatura": 2,
      "parcelas_outras_faturas": 3,
      "somente_categoria": false
    }
  }
}
```

| Campo | Uso |
|-------|-----|
| `perguntar === true` | Abrir a pergunta. Sem o objeto, ou `perguntar === false`, não perguntar |
| `linhas_nesta_fatura` | Quantas outras linhas desta fatura receberiam a escolha |
| `parcelas_outras_faturas` | Quantas parcelas do mesmo grupo, em outras faturas, receberiam a escolha |
| `estabelecimento_nome` | Nome na pergunta |
| `somente_categoria` | `true` quando a linha ficou só com categoria. A pergunta fala de categoria, não de subcategoria |

Com subcategoria, a contagem inclui só linhas ainda sem subcategoria. Só com categoria, só linhas ainda sem categoria. Linha que já tem a classificação correspondente não entra na conta e não será alterada.

---

## Pergunta

Exemplo:

Com subcategoria (`somente_categoria === false`):

> Aplicar a mesma subcategoria nas outras 2 compras de Shopee nesta fatura? As 3 parcelas desta compra nas outras faturas também recebem essa categoria e subcategoria.

Só com categoria (`somente_categoria === true`):

> Aplicar a mesma categoria nas outras 2 compras de Shopee nesta fatura? As 3 parcelas desta compra nas outras faturas também recebem essa categoria.

Se `parcelas_outras_faturas` for 0, omitir a frase das outras faturas.

Botões: **Aplicar** e **Não aplicar** (cancelar).

---

## Retry — confirmar

O mesmo `PUT`, com os mesmos `id`, `categoria_id` e `subcategoria_id` (ou `null`), mais:

| Campo | Valor |
|-------|--------|
| `aplicar_subcategoria_estabelecimento` | `true` |

Não enviar `propagar_grupo` no lugar desta flag.

O back:

- com subcategoria: aplica o par nas outras compras do mesmo estabelecimento **nesta fatura** e nas parcelas da mesma compra em outras faturas que ainda estão sem subcategoria;
- só com categoria: aplica a categoria nessas mesmas linhas que ainda estão sem categoria, sem preencher subcategoria;
- grava categoria e subcategoria (esta pode ser nula) como padrão do estabelecimento.

Depois do 200, refetch das transações desta fatura. Não é preciso abrir as outras faturas agora.

Cancelar: fecha a pergunta. A linha já gravada no primeiro `PUT` permanece.

---

## Critérios

- [ ] A pergunta só aparece quando `perguntar === true`
- [ ] A contagem de linhas desta fatura e de parcelas em outras faturas aparece no texto
- [ ] Confirmar reenvia com `aplicar_subcategoria_estabelecimento=true` e atualiza as linhas visíveis
- [ ] Cancelar deixa só a linha editada
- [ ] A pergunta não abre fora da tela da fatura
