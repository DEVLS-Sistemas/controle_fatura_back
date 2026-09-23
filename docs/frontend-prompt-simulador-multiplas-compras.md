# Prompt — Frontend: Somar simulações e finalizar como compras manuais

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Card: [CTLFAT-17](https://devlssistemas.atlassian.net/browse/CTLFAT-17). Épico: [CTLFAT-16](https://devlssistemas.atlassian.net/browse/CTLFAT-16).

Não é tela nova. Continua em **Posso comprar?** (`/simulador`). Não abre N modais de Nova compra. Não chama `POST /transacoes/cadastrar` em loop.

Backend **já implementado** e **não muda**: `POST /api/v1/transacoes/cadastrar-lote`. A soma e a repetição da simulação são só no front (overlay em memória). Nada de endpoint novo.

Contrato de cada item = compra rápida: [`frontend-prompt-compra-rapida.md`](frontend-prompt-compra-rapida.md). Veredito de uma simulação: [`frontend-prompt-posso-comprar.md`](frontend-prompt-posso-comprar.md) e [`frontend-prompt-simulador-compra.md`](frontend-prompt-simulador-compra.md). Compra nasce manual: [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).

---

## Problema

O fluxo errado deixa a **primeira** simulação congelada em tamanho cheio e pede para ir encaixando as próximas num formulário menor, embaixo.

Quem simula a segunda, a terceira, a décima tem que passar pelo **mesmo** processo da primeira: o mesmo form, o mesmo **Posso comprar?**, o mesmo veredito. As que já foram feitas aparecem só como lista, com **Editar**. No fim, **Finalizar** grava todas como compras manuais.

---

## Objetivo

1. Cada simulação — a primeira e as seguintes — ocupa a área principal e segue o fluxo de uma simulação só.
2. No resultado, a ação **Somar com outra simulação** guarda essa e devolve o form limpo, igual ao abrir a tela.
3. As já somadas ficam **abaixo**, em linha compacta, com **Editar**.
4. **Finalizar** cria as compras manuais num único `POST /transacoes/cadastrar-lote`.

O veredito vermelho **não** esconde nem desabilita Somar nem Finalizar.

---

## Fluxo

```
Abrir /simulador
  → form igual ao de sempre (fase idle)
  → Posso comprar?
  → veredito desta simulação (tela cheia, como a primeira)

        Somar com outra simulação          Finalizar
                 │                              │
                 ▼                              ▼
        esta vai para a lista            confirmação + POST lote
        form limpo de novo               (a atual + as já listadas)
        (próxima = mesmo processo)
```

A simulação que está na área principal **não** fica congelada quando a pessoa soma a próxima. Ela desce para a lista. A área principal volta a ser o form, e o próximo veredito **substitui** o anterior — não empilha outro painel igual embaixo.

---

## A simulação da vez (igual à primeira)

Mesma fase idle e mesma fase resultado de [`frontend-prompt-posso-comprar.md`](frontend-prompt-posso-comprar.md):

- Form: titular (se 2+ pessoas), cartão filtrado pelo titular, responsável, valor, parcelas, data (default hoje).
- Mais um campo, no **mesmo** form, em todas as vezes: **O que foi comprado** (`observacoes`). Sem ele não dá para finalizar. Não abrir outro modal para pedir a descrição.
- Botão **Posso comprar?** dispara o veredito. Sem GET de projeção no mount.
- Resultado na ordem de sempre: veredito 🟢🟡🔴, hero da parcela, resumo do responsável, accordion de detalhes.
- Mudou um campo depois do veredito: esconder o resultado até simular de novo.

### O que a soma muda no cálculo (não na tela)

O veredito da simulação da vez olha as próximas faturas **mais** as parcelas das simulações **já listadas** abaixo. A frase pode dizer, numa linha, `Somando com 2 simulações`, quando a lista não está vazia.

Isso não desenha N vereditos. A tela cheia é só a da vez. As anteriores não repetem semáforo, hero nem tabela.

Sem nada na lista, o veredito é o de uma simulação só — o comportamento de hoje.

---

## Somar com outra simulação

Visível no **resultado** da simulação da vez (não na fase idle).

Ao clicar:

1. Exige **O que foi comprado** preenchido. Se vazio, `is-invalid` nesse campo e não soma.
2. Guarda a simulação atual na lista de baixo (descrição, valor, parcelas, cartão, responsável, data e o restante que o form já tiver).
3. Limpa o form e **esconde** o veredito. A área principal fica como ao abrir `/simulador`.
4. A pessoa preenche e clica **Posso comprar?** de novo. Mesmos campos, mesmo botão, mesmo veredito.

Não manter o painel da anterior aberto. Não trocar o form por uma linha de “adicionar item”.

Limite visível: no máximo **20** simulações no conjunto (lista + a da vez, se já tiver veredito). Na 20ª, esconder **Somar** e deixar só **Finalizar**.

---

## Lista das já feitas

Abaixo da área principal, só se houver alguma somada. Título curto: **Simulações** e o total (`2 simulações · R$ 3.249,90` — soma dos `valor_compra`, não da parcela).

Cada linha:

| Mostra | Ação |
|--------|------|
| Descrição, valor total, parcelas, cartão | **Editar** |

Linha compacta. Sem veredito, sem tabela de faturas, sem form aberto.

### Editar

1. Tira essa simulação da lista (ela deixa de entrar na soma até ser simulada de novo).
2. Devolve os dados dela no **mesmo** form da primeira.
3. A área principal fica na fase idle, preenchida, sem veredito, até a pessoa clicar **Posso comprar?** outra vez.
4. As outras linhas continuam na lista e continuam na soma.
5. Se já havia uma simulação da vez ainda não somada, não descartar em silêncio: confirmar antes de substituir pelo item editado.

Depois do novo veredito, **Somar com outra simulação** devolve o item à lista (não duplica).

**Remover** na linha, discreto: tira da lista e da soma. Não grava. Não mexe na simulação da vez.

---

## Finalizar

Visível quando existe algo para gravar: a simulação da vez **com veredito** e/ou pelo menos uma linha na lista.

Não aparece na idle vazia (nada simulado e lista vazia).

Entra no POST:

- todas as linhas da lista
- a simulação da vez, se o veredito está na tela

Form preenchido sem veredito (a pessoa mudou o campo e não simulou de novo) **não** entra. Se for o caso, avisar numa linha: a simulação aberta ainda não entra até clicar em **Posso comprar?**.

### Confirmação

Antes do POST, sem form e sem modal de compra:

> 3 compras · R$ 4.200,00

- Quantidade = itens que vão no POST.
- Total = soma dos `valor_compra`.
- **Confirmar** dispara o lote. **Cancelar** fecha e permanece onde estava (veredito e lista intactos).

Enquanto o POST está em voo: desabilitar Finalizar / Confirmar. Não disparar de novo.

---

## Request

Um request só. **Proibido** N vezes `POST /transacoes/cadastrar`.

```
POST /api/v1/transacoes/cadastrar-lote
```

```json
{
  "compras": [
    {
      "cartao_id": 1,
      "observacoes": "Mouse Logitech",
      "valor_compra": "249,90",
      "data": "2026-08-27",
      "tipo": "purchase",
      "parcelas_total": 1
    },
    {
      "cartao_id": 2,
      "observacoes": "Notebook",
      "valor_compra": "3.000,00",
      "data": "2026-08-27",
      "tipo": "purchase",
      "parcelas_total": 10,
      "responsavel_id": 15
    }
  ]
}
```

Ordem do array = ordem da lista e, por último, a simulação da vez (se entrar).

Regras do item (compra rápida):

- `tipo`: sempre `"purchase"`.
- Obrigatório: `observacoes`, `valor_compra`, `data` (`Y-m-d`), `cartao_id`, `parcelas_total` (1..36; default 1).
- Opcionais, só se o form tiver: `cartao_numero_id`, `origem_compra`, `plataforma_id`, `categoria_id`, `subcategoria_id`, `responsavel_id`, `fatura_id`, `eh_assinatura`, `parcelas[]`.
- Campo vazio: **omitir** a chave. Não enviar `""`.
- **Não** enviar `estabelecimento` nem `estabelecimento_id`.
- **Não** enviar anexo.
- Parcelado: só `parcelas_total`, sem `parcelas[]`, salvo se a pessoa tiver ajustado os valores.

Limite da API: **1 a 20** itens. O front não envia 0 nem 21+.

---

## Resposta 200

Array `compras` na **mesma ordem** do request. Cada item tem o formato de `POST /transacoes/cadastrar`:

```json
{
  "compras": [
    {
      "transacao": {
        "data": {
          "compra_grupo_id": null,
          "valor_compra": 249.9,
          "parcelas_total": 1,
          "transacoes": []
        },
        "status": true,
        "message": "Transação cadastrada com sucesso!"
      }
    }
  ]
}
```

Em cada compra gravada:

- `compra_manual: true`
- `precisa_conciliar: true` enquanto não casar com o lançamento da fatura
- à vista: `compra_grupo_id` null
- parcelada: um `compra_grupo_id` só das parcelas **daquela** compra
- estabelecimento `null` (UI **—**)

O front **não** infere manual pelo status da fatura. Usa os booleanos da API.

### Sucesso

- Toast de sucesso (quantidade gravada). O toast pode oferecer **Ver compras** (`/compras`). Não redirecionar sozinho.
- Lista vazia, form limpo, fase idle. O conjunto acabou.

Não abrir a visualização de cada compra. Não conciliar neste clique.

---

## Erro

Qualquer item inválido: a API faz rollback. **Nada** foi gravado. Corpo 422 com o índice (0 = primeiro item de `compras`):

```json
{
  "error": true,
  "message": "Valor da compra é obrigatório",
  "indice": 1
}
```

0 ou 21+ itens: 422 **sem** `indice`. Mensagem: `Envie entre 1 e 20 compras`.

No 422 com `indice`:

- Toast com `message`.
- Destacar **só** esse item (a linha da lista, ou a simulação da vez se ela for a última do array).
- Lista, veredito e form **permanecem**. Não limpar.
- A pessoa corrige (Editar, se for linha) e finaliza de novo. Novo POST do conjunto inteiro.

401: limpar sessão e ir ao login.

---

## Veredito vermelho

O nível `alto` (🔴) é conselho. **Somar com outra simulação** e **Finalizar** continuam visíveis e habilitados.

---

## Checklist

- [ ] A segunda simulação usa o mesmo form, o mesmo **Posso comprar?** e o mesmo veredito da primeira
- [ ] **Somar com outra simulação** manda a atual para a lista e limpa a área principal — a anterior não fica congelada em tamanho cheio
- [ ] O veredito da vez considera as parcelas das já listadas, numa tela só
- [ ] Lista abaixo com descrição, valor, parcelas, cartão e **Editar**
- [ ] Editar devolve o item ao mesmo form e tira ele da soma até simular de novo
- [ ] **Finalizar** pede confirmação (`3 compras · R$ 4.200,00`) e chama só `POST /transacoes/cadastrar-lote`
- [ ] Sucesso: toast, lista vazia, fase idle; compras nascem manuais e pedem conciliação
- [ ] 422: toast, item do `indice` destacado, nada novo gravado, lista intacta
- [ ] Veredito vermelho não esconde Somar nem Finalizar

---

## Fora deste prompt

- Empilhar o resultado da primeira e cadastrar as próximas num form reduzido embaixo
- `POST /dashboard/simular-compra` ou qualquer endpoint novo de simulação
- Persistir a lista de simulação no servidor
- Conciliar no mesmo clique
- Anexos no lote
- Inventar estabelecimento a partir da descrição
