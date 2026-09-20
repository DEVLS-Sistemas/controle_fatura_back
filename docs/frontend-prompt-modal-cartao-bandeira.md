# Prompt — Frontend: modal de cartão/bandeira pelo PDF, não pela rota

Use este prompt no repositório do **frontend**. Backend **já implementado** (CTLFAT-13). Copie o arquivo inteiro para o chat do front.

Complementa [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md). Não substitui esse prompt.

---

## Problema

Estar na tela da fatura **PicPay** e clicar em **Adicionar fatura** não significa que o PDF é PicPay. Caso real: anexo `Fatura_10092026` (Sofisa, outra bandeira). O modal sugeriu **PicPay** e o select de bandeira veio só **Mastercard** (as do cartão da rota).

O `cartao_id` da URL é **hint**. Depois do 422, o modal usa **só o payload**. A tela de origem é atalho, não trava cartão nem bandeira.

Sem anexo, Adicionar na tela do PicPay **pode** continuar pré-selecionando o PicPay.

---

## Regras

1. Adicionar fatura a partir de um cartão **pode** mandar `cartao_id` como hint no primeiro `POST /cadastrar`.
2. No 422 `precisa_confirmar_metadados`, **não** use a rota para nome/bandeira. Fonte: `sugestao` + `bandeiras[]` + `modo`.
3. Se `modo=cadastrar_cartao`:
   - **Não** recolocar o PicPay (nem qualquer cartão) da URL no nome.
   - Nome = `sugestao.cartao_nome_sugerido` (ex.: Sofisa).
   - Select de bandeira com **toda** `bandeiras[]` da resposta, editável. Não filtrar pelas bandeiras do cartão da tela.
   - Pré-selecionar `sugestao.bandeira_sugerida` se existir (bandeira **deste** PDF).
4. Retry de **cadastrar cartão**: `cartao_nome` + `bandeira` escolhida + `mes` / `ano` + arquivo. **Não** reenviar o `cartao_id` do PicPay (nem `cadastrar_cartao` com o id da rota).
5. Se `modo=confirmar_cartao`: pré-selecionar `sugestao.cartao_id` (pode ser o Sofisa, não o da URL). Select de bandeira = `bandeiras[]` da resposta.
6. Sem PDF, Adicionar no PicPay: pré-select PicPay ok.

---

## API (sem mudança de contrato)

```http
POST /api/v1/faturas/cadastrar
```

Primeiro envio (atalho da tela): `arquivo_pdf` + `cartao_id` opcional (hint). Sem `mes`/`ano` se o PDF for informar.

### 422 — cartão da tela ≠ banco do PDF, cartão do PDF ainda não existe

```json
{
  "codigo": "precisa_confirmar_metadados",
  "modo": "cadastrar_cartao",
  "pode_cadastrar_cartao": true,
  "precisa_selecionar_bandeira": true,
  "sugestao": {
    "cartao_id": null,
    "cartao_nome_sugerido": "Sofisa",
    "parser": "sofisa",
    "bandeira_sugerida": "Mastercard",
    "confianca": "baixa"
  },
  "bandeiras": [
    { "value": null, "label": "Visa", "criar": true },
    { "value": null, "label": "Mastercard", "criar": true }
  ]
}
```

`bandeiras[]` é o lookup **completo**, não a Mastercard do PicPay.

### Retry cadastrar Sofisa

- `arquivo_pdf`
- `cadastrar_cartao=true`
- `cartao_nome=Sofisa`
- `bandeira=` a escolhida no select (não só Mastercard)
- `mes` / `ano` da sugestão (editáveis)
- **sem** `cartao_id`

### 422 — Sofisa já cadastrado

`modo=confirmar_cartao`, `sugestao.cartao_id` = id do **Sofisa**. Retry com esse id, não o da rota PicPay.

### Mesmo `cartao_id` + PDF PicPay de verdade

Pode confirmar o PicPay (`sugestao.cartao_id` do hint, `parser=picpay`).

---

## Anti-padrões

- ❌ Depois do 422, preencher nome/bandeira com o cartão da URL
- ❌ Filtrar `bandeiras[]` pelas bandeiras do cartão da tela
- ❌ No retry de `cadastrar_cartao`, reenviar o `cartao_id` do PicPay
- ❌ Remover o atalho Adicionar fatura da tela do cartão (fora deste card)

---

## Checklist

- [ ] Tela PicPay → Adicionar → PDF Sofisa → modal Sofisa + select completo
- [ ] Dá para trocar a bandeira e concluir sem sair da tela
- [ ] Retry sem o `cartao_id` do PicPay quando for cadastrar Sofisa
- [ ] Sem PDF, Adicionar no PicPay: pré-select PicPay ok
