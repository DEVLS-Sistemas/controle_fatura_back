# Prompt — Frontend: Responsável automático ao importar fatura de outro titular

Use no repositório do **frontend**. Complementa [`frontend-prompt-pessoas.md`](frontend-prompt-pessoas.md) e [`frontend-prompt-compras.md`](frontend-prompt-compras.md).

Spec: [`modules/pessoas.md`](modules/pessoas.md) · [`modules/responsaveis.md`](modules/responsaveis.md).

---

## Objetivo

Ao cadastrar/importar fatura de **outra pessoa** (titular ≠ você), o back:

1. Cria (ou reutiliza) um **responsável** com o nome dessa pessoa.
2. Define esse responsável como **padrão da fatura**.
3. Aplica esse responsável em **todas as compras importadas** do PDF/CSV.

O front **não** pede cadastro manual de responsável nesse fluxo — só consome o resultado.

---

## Regra

| Titular da fatura | Responsável padrão |
|-------------------|--------------------|
| Pessoa principal (login) | `Eu` |
| Outra pessoa (ex. Maysa) | Responsável auto com nome completo dela |

Pessoa ≠ responsável (conceitos diferentes), mas para outro titular o padrão da fatura é o responsável espelhando a pessoa.

---

## O que muda na API (já no back)

### Pessoa

```json
{
  "id": 2,
  "nome": "Maysa",
  "sobrenome": "Araujo da Conceicao",
  "nome_completo": "Maysa Araujo da Conceicao",
  "responsavel_id": 15,
  "eh_principal": false,
  "ativo": true
}
```

### Fatura (listagem / detalhe)

```json
{
  "id": 591,
  "pessoa_id": 2,
  "pessoa_nome": "Maysa Araujo da Conceicao",
  "responsavel_id": 15,
  "responsavel_nome": "Maysa Araujo da Conceicao"
}
```

### Compras importadas

Cada `transacao` da fatura vem com `responsavel_id` / `responsavel_nome` = o padrão acima (até o usuário alterar manualmente).

---

## UX / front

1. **Modal de titular** (já existente): ao confirmar pessoa nova ou existente (não principal), **não** adicionar passo “criar responsável”.
2. Após sucesso do import:
   - Invalidar cache de `GET /api/v1/responsaveis/listar` e `responsaveis-list`.
   - Invalidar listagem/detalhe da fatura e das transações.
3. Toast opcional: `Responsável "Maysa Araujo da Conceicao" criado e aplicado nesta fatura.`
4. Detalhe da fatura: chip/linha **Responsável padrão: {responsavel_nome}** (quando vier).
5. Formulário de compra nessa fatura: pré-selecionar `responsavel_id` da fatura se o lookup/default do back já não trouxer; senão confiar no default do create (back usa `faturas.responsavel_id`).
6. Tela Responsáveis: o novo item aparece sozinho — listar normalmente.

---

## Fora de escopo

- Renomear o responsável `Eu` para o nome do perfil.
- Forçar todas as compras futuras do cartão sem fatura (só o padrão da fatura + import).
- Esconder o responsável `Eu` quando existir outro titular.

---

## Checklist

- [ ] Não pedir cadastro de responsável no fluxo de outro titular
- [ ] Invalidar cache de responsáveis após import bem-sucedido
- [ ] Mostrar `responsavel_nome` na fatura quando a API enviar
- [ ] Compras da fatura refletem o responsável do titular (conferir após processar PDF)
- [ ] Continuar permitindo trocar o responsável compra a compra
