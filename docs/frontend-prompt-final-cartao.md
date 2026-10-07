# Prompt — Frontend: Modal para aplicar o final do cartão em todas as parcelas

Use este prompt no repositório do **frontend**. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) (seção “Atribuir / corrigir final na edição”).

Card: CTLFAT-30.

---

## Objetivo

Ao escolher o final do cartão numa compra parcelada, a tela abre o `confirm` nativo do navegador:

`Esta compra é parcelada. Deseja aplicar o final a todas as parcelas?`

Trocar por um modal do sistema. **Sim** aplica o final em todas as parcelas. **Não** aplica só nesta.

Compra à vista (uma parcela) troca o final direto, sem modal.

---

## O que o backend faz

`PUT /api/v1/transacoes/editar`

Com `propagar_grupo: true` e `parcelas_total > 1`, o back garante o `compra_grupo_id` **antes** de copiar o final. `cartao_numero_id` chega em todas as parcelas do grupo, inclusive as que ainda não tinham grupo e as que estão em outras faturas.

Sem a flag, só a parcela editada muda. Valor, data e fatura das irmãs não mudam.

### Sim — todas as parcelas

```json
{
  "id": 123,
  "cartao_numero_id": 10,
  "propagar_grupo": true
}
```

### Não — só esta parcela

```json
{
  "id": 123,
  "cartao_numero_id": 10
}
```

Não enviar `propagar_grupo: false` no lugar de omitir a flag.

---

## O que o front faz

1. Na fatura, ao trocar o **Final do cartão** de uma compra com `parcelas_total > 1`, abrir um modal do sistema (o mesmo componente de modal já usado nas outras telas).
2. Texto no mesmo sentido do confirm atual: aplicar o final a todas as parcelas, ou só nesta.
3. **Sim** envia o PUT com `cartao_numero_id` e `propagar_grupo: true`. Depois atualiza as parcelas visíveis desta fatura (refetch do detalhe). As parcelas dos outros meses já vêm certas no próximo carregamento.
4. **Não** envia o PUT só com `id` e `cartao_numero_id`. Atualiza só a linha atual.
5. Compra à vista (`parcelas_total` ausente ou `1`): salva direto, sem modal.
6. O modal vale só para o final. Não perguntar de categoria, estabelecimento, plataforma nem outros campos.

Não usar `window.confirm` nem `window.alert` nesse fluxo.

---

## O que não fazer

- Modal em compra à vista.
- Propagar valor, data ou fatura.
- Aplicar o final em compras que não são parcelas desta.

---

## Checklist

- [ ] Não aparece o confirm nativo do navegador
- [ ] Sim aplica o final nas parcelas da compra
- [ ] Não aplica só na linha atual
- [ ] À vista não abre o modal
