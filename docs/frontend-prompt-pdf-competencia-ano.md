# Prompt — Frontend: ícone PDF só onde existe anexo

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

O **backend já está atualizado**. Não invente rotas.

Prompts relacionados (não substituir; complementar):

- Cadastro / modal de metadados: [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md)
- Listagem e detalhe: [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md)
- Ícone PDF na listagem: [`frontend-prompt-melhorias-faturas.md`](frontend-prompt-melhorias-faturas.md)
- Remover / trocar PDF: [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md)

Base: `/api/v1/faturas` (Bearer Sanctum).

---

## Problema principal — `pago` ≠ tem PDF

Importar o PDF de **agosto** cria/atualiza a fatura de **julho** (parcelas + quitação). Isso é correto:

- julho pode aparecer como **Paga** (`pago: true`) porque o pagamento vem no extrato de agosto
- julho pode ter transações (parcelas `2/10` etc.)

Isso **não** significa que julho tem arquivo. **Não** mostrar:

- ícone de PDF na listagem
- “Ver PDF” / preview
- “Remover PDF”

nesses casos.

| Campo | Significa | UI de anexo |
|-------|-----------|-------------|
| `pago` | Quitação pelos pagamentos de F+1 | **Não** |
| `status: processada` | Esta competência teve extrato importado | **Não** sozinho |
| `tem_pdf === true` | Existe arquivo PDF **nesta** fatura | Ícone + preview + remover |
| `pode_remover_anexo === true` | Tem PDF ou CSV e não está `processando` | Botão remover |

Regra: **só** `tem_pdf` / `tem_csv` / `pode_remover_anexo` / `pdf_url`. Nunca `pago`, nunca `status`, nunca “tem transação”.

```json
{
  "competencia": "07/2026",
  "status": "pendente",
  "tem_pdf": false,
  "tem_csv": false,
  "pdf_url": null,
  "pode_remover_anexo": false,
  "pago": true,
  "valor_pago": 2274.33,
  "valor_restante": 0
}
```

Julho paga, **sem** PDF: ícone cinza/“—”, sem preview, sem remover. Badge **Paga** continua.

O back deixou de restaurar PDF de fatura apagada quando agosto recria o stub de julho. Depois do deploy, um refetch da listagem já deve vir com `tem_pdf: false` nesse caso. Se o front hoje mostra PDF com base em `pago` ou `processada`, isso é bug do front.

---

## Problema extra — mês certo, ano errado

A listagem tem stubs por competência. Mês 7 existe em anos diferentes (`07/2024` e `07/2026`).

O back lê **mês e ano do arquivo** (não chuta o ano atual). Se clicar no stub de `07/2026` e enviar PDF de `07/2024`, vincula em **07/2024**. A resposta 200 traz a fatura **onde o arquivo ficou** (`data.id`, `data.mes`, `data.ano`).

---

## 1) Modal de metadados — ano visível e honesto

No 422 `precisa_confirmar_metadados`, `sugestao.mes` e `sugestao.ano` vêm do PDF.

| Campo | O que fazer |
|-------|-------------|
| `sugestao.ano` número (ex. `2024`) | Pré-preencher o select/input **com esse valor**. Não trocar por 2026. |
| `sugestao.ano` `null` / ausente | Deixar **vazio** e **obrigar** o usuário a preencher. **Proibido** default `new Date().getFullYear()`. |
| `sugestao.mes` `null` | Idem: vazio, obrigatório. Não default mês atual. |

UI:

- Mostrar a competência junta e grande: **`07/2024`**, não só o mês “Julho”.
- Label do ano: “Ano da fatura” (não “ano atual”).
- Se o usuário mudar o ano à mão, envie o valor **editado** no retry (`mes` / `ano`). O back ainda pode realocar se o PDF for claramente de outro ano — trate a resposta como fonte da verdade (seção 2).

Copy sugerida no modal:

> Conferira o **mês e o ano** lidos do arquivo. Um PDF de julho/2024 não deve ir para julho/2026.

---

## 2) Sucesso de `cadastrar` / `upload-pdf` — use o `id` devolvido

Envelope (já existente):

```json
{
  "status": true,
  "message": "PDF anexado à fatura existente com sucesso!",
  "precisa_senha_pdf": false,
  "data": {
    "id": 801,
    "mes": 7,
    "ano": 2024,
    "tem_pdf": true,
    "pdf_url": "http://…/api/v1/faturas/pdf/801",
    "status": "pendente"
  }
}
```

`data` é a fatura **que recebeu o arquivo**.

### Se o usuário clicou no stub `id=645` (`07/2026`) e o PDF era `07/2024`

| Errado | Certo |
|--------|--------|
| Poll `GET /listar/645` | Poll `GET /listar/{data.id}` (ex. `801`) |
| Manter a tela em 07/2026 e esperar o ícone PDF | 07/2026 fica **sem** PDF; 07/2024 passa a ter |
| Toast genérico “PDF enviado” | Toast com a competência real |

Toast quando `data.id` ≠ id clicado **ou** `data.mes`/`data.ano` ≠ competência da linha:

> PDF vinculado à fatura **07/2024**.

Se estava no **detalhe** da fatura clicada:

1. Refetch da listagem (as duas linhas mudam).
2. Navegar para `/faturas/{data.id}` **ou** avisar e oferecer “Abrir 07/2024”.
3. Não deixe o detalhe de 07/2026 como se o PDF tivesse entrado ali (`tem_pdf` vai ficar `false`).

Se estava na **listagem**:

1. Refetch da listagem completa (não só o grupo visível da página atual — a competência 07/2024 pode estar em outra página).
2. Ícone PDF some da linha clicada (se era o ano errado) e aparece na competência do arquivo.

Polling de processamento (já usado hoje): usar **`data.id`**, não o id do clique.

```http
GET /api/v1/faturas/listar/{data.id}
```

até `status` ser `processada` ou `erro`.

---

## 3) `POST /upload-pdf` no stub (fatura sem anexo)

Continua:

```http
POST /api/v1/faturas/upload-pdf
Content-Type: multipart/form-data
```

| Campo | Valor |
|-------|--------|
| `id` | id da linha clicada (stub) |
| `arquivo_pdf` | arquivo |
| `processar_automatico` | `true` (default) |

O back **pode ignorar esse `id` como competência** se o PDF for de outro mês/ano. O `id` ainda identifica o **cartão/bandeira**. A competência sai do arquivo.

Não trate 200 como “anexei nesta linha”. Sempre leia `data.id` / `data.mes` / `data.ano`.

`POST /cadastrar` só com o arquivo (sem cartão/mês/ano) também anexa no stub se o PDF identificar **um** cartão + competência que já existe sem anexo — **200**, sem `precisa_confirmar_metadados`.

---

## 4) Erro 422 — competência do PDF já tem anexo

Se o arquivo é de `07/2024` e **já existe** PDF nessa fatura:

```json
{
  "error": true,
  "message": "Este arquivo é da competência 07/2024, que já possui anexo. Remova o anexo de lá antes de enviar de novo."
}
```

- Toast / alerta com `message` (não engolir).
- **Não** marque a linha clicada como tendo PDF.
- CTA: ir para a fatura da competência citada (se o front já a tiver na lista) ou “Remover PDF” lá — ver [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md).

Não invente um modal novo de confirmação. O back realoca sozinho quando a competência alvo **não** tem anexo.

---

## 5) PDF já vinculado no ano errado (dado legado)

Fatura `07/2026` com `tem_pdf: true` mas o arquivo é de `07/2024`.

**Não** resolva com `POST /processar/{id}` nessa fatura — o extrato já foi importado lá; o back **não** move automaticamente para não duplicar lançamentos.

Fluxo:

1. Detalhe da fatura errada → **Remover PDF** (`motivo: "remover"`).
2. Enviar o mesmo arquivo de novo (cadastro ou upload no stub certo).
3. O back coloca em `07/2024`.

Copy opcional se `tem_pdf` e o usuário reclamar / tooltip no ícone:

> O ícone indica que esta competência tem arquivo. Se o PDF for de outro ano, remova e envie de novo — o sistema ancora pelo ano escrito no arquivo.

Não precisa detectar “PDF de outro ano” no front. Só não esconda o botão Remover PDF (`pode_remover_anexo`).

---

## 6) Listagem — ícone só nesta competência

Regras já existentes, agora com ênfase no **ano**:

- Ícone PDF **somente** se `tem_pdf === true` **nessa** fatura (`id` da linha).
- Não herdar ícone do cartão / do grupo / da linha vizinha.
- Dois julhos no mesmo cartão: um pode ter PDF e o outro não. Renderize por item de `faturas[]`.
- Depois de qualquer upload/cadastro: **refetch da listagem** (quitação das vizinhas também muda).

Competência na linha: sempre `competencia` (`07/2026`) ou `mes`+`ano`. Nunca só o mês.

---

## Checklist de aceite

- [ ] Ícone PDF / preview / remover **somente** se `tem_pdf` (ou `tem_csv`) nesta fatura — nunca por `pago` ou `status`
- [ ] Julho pode estar `pago: true` e `tem_pdf: false` ao mesmo tempo (pagamento veio no PDF de agosto)
- [ ] Modal de metadados **não** preenche ano com o ano corrente se `sugestao.ano` vier vazio
- [ ] Modal mostra competência completa (`07/2024`), mês e ano editáveis
- [ ] Retry envia o `ano` do modal (o valor confirmado, não `Date.now`)
- [ ] Após `cadastrar` / `upload-pdf`, poll e navegação usam `data.id` da resposta
- [ ] Cadastro só com PDF em stub existente (mesmo cartão/competência, sem anexo) → 200, anexa nessa fatura
- [ ] Toast se a competência da resposta ≠ linha clicada (“vinculado à 07/2024”)
- [ ] Listagem: ícone some da linha errada e aparece na competência do arquivo (refetch)
- [ ] No detalhe da linha clicada, se o PDF foi para outra fatura: não ficar como se tivesse anexo
- [ ] 422 de “já possui anexo” na competência do arquivo: toast com `message`, não marca a linha clicada
- [ ] PDF legado no ano errado: só **Remover PDF** + reenviar; não só reprocessar
- [ ] Duas faturas do mesmo mês em anos diferentes: ícone independente por `id`

---

## O que **não** fazer

- Não defaultar `ano` / `mes` com a data de hoje no modal ou no form de upload.
- Não assumir que `POST /upload-pdf` com `id=645` deixou o PDF na 645.
- Não criar rota nova. Não criar campo `forcar_periodo`.
- Não calcular `tem_pdf` no front a partir de transações ou de `pago`.
- Não pedir confirmação extra “o PDF é de 2024, confirmar?” — o back já decide; o front só reflete `data`.
