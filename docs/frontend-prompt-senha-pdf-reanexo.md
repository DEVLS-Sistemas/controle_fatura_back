# Prompt — Frontend: senha salva no cartão no reanexo e no cadastro (CTLFAT-14)

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Complementa [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md) (modal de senha + campo no cartão) e [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md) (retry multipart).

A API do back **já trata** senha do cartão no parse de `cadastrar` / `upload-pdf` / preview. Este card é o fluxo do front que ainda pedia senha de novo ou cadastrava fatura **sem o PDF**.

---

## Problema

Caso 777 (Sofisa, cadastro pela **lista**):

1. Modal de metadados acertou nome/bandeira.
2. Pediu senha; o usuário digitou, escolheu a regra e marcou **salvar**.
3. A fatura nasceu **zerada**: `pendente`, R$ 0, `tem_pdf=false`, 0 transações, sem preview.
4. No detalhe, ao anexar o mesmo PDF **sem** redigitar senha: 422 “protegido por senha” — o cartão **já tinha** `tem_senha_pdf`.

O back agora:

- usa senha do **request** e, se vazia, a senha **salva no cartão** da fatura/alvo;
- `POST /upload-pdf` com `id` + arquivo, **sem** `senha_pdf`: desbloqueia com o cartão;
- 422 `pdf_senha_necessaria` só se não há senha no request **nem** no cartão;
- senha salva errada → 422 `pdf_senha_incorreta` (`tem_senha_cadastrada: true`);
- cadastro que manda senha **sem** arquivo → 422 (não cria stub);
- `GET /faturas/pdf/{id}` devolve o PDF **já aberto** quando o cartão tem senha.

O front precisa **reenviar o mesmo PDF** depois dos modais e **não** abrir modal de senha se `tem_senha_pdf`.

---

## Regras

### 1. Retry multipart sempre com o mesmo arquivo

Depois do modal de senha **e** do de metadados (e titular, se houver): o `POST /cadastrar` de retry é **multipart** com:

- o **mesmo** `arquivo_pdf` da tentativa original;
- senha + regra + `salvar_senha_pdf` se o usuário acabou de desbloquear;
- campos do modal (`cartao_id` / `cartao_nome` + `bandeira`, `mes`, `ano`, …).

Não cadastrar só com cartão/mês/ano depois que o fluxo começou com PDF. Isso é o que gerou a 777 zerada.

Guarde o `File` no state da tela até o 200 final. Não dependa do `<input type="file">` ainda estar preenchido.

### 2. 200 com PDF no fluxo não pode abrir detalhe vazio

Se o usuário escolheu um arquivo e a resposta for 200 com `tem_pdf !== true` (no envelope: `fatura.data.tem_pdf` ou `data.tem_pdf`):

- tratar como **erro** (toast);
- **não** navegar para o detalhe vazio;
- manter o arquivo no state para novo envio.

### 3. Detalhe sem anexo

Se `tem_pdf !== true` e `tem_csv !== true`:

- dropzone / “Anexar PDF” visível;
- **não** montar iframe/preview quebrado (`pdf_url` vem `null`).

### 4. Quando **não** abrir o modal de senha

No **detalhe** e no **reanexo** (`POST /upload-pdf`):

| Situação | UI |
|----------|-----|
| Cartão da fatura com `tem_senha_pdf === true` | Enviar só `id` + `arquivo_pdf`. **Não** abrir modal de senha. |
| 200 | Preview (`pdf_url`) + recarregar lançamentos/total. |
| 422 `codigo=pdf_senha_incorreta` | Aí sim o modal (senha salva falhou). `tem_senha_cadastrada` vem `true`. |
| 422 `codigo=pdf_senha_necessaria` | Modal (`motivo=ausente`). Cartão ainda não tem senha. |

Não use o texto da `message` (“protegido por senha”) como gatilho. Use `codigo` / `precisa_senha_pdf` / `erro_codigo`.

No **cadastro**, se o cartão selecionado já tem `tem_senha_pdf`, o primeiro `POST /cadastrar` também vai **sem** `senha_pdf`. Só abra o modal no 422 de senha.

### 5. Preview

`GET /api/v1/faturas/pdf/{id}` (Bearer) com `tem_pdf`:

- 200: PDF inline, já sem senha se o cartão tinha senha certa — iframe/object normalmente;
- 422 `pdf_senha_*`: não quebrar o preview; oferecer anexar de novo / informar senha.

---

## API (lembrete)

```http
POST /api/v1/faturas/upload-pdf
Content-Type: multipart/form-data

id={faturaId}
arquivo_pdf={file}
# senha_pdf só se o usuário digitou neste passo
```

Sucesso: 200, `fatura.data.tem_pdf === true`.

```http
POST /api/v1/faturas/cadastrar
# multipart com arquivo + confirmação dos modais (nunca sem o PDF se o fluxo começou com arquivo)
```

422 senha (cadastrar, upload-pdf, processar, GET pdf):

```json
{
  "error": true,
  "message": "Este PDF da fatura está protegido por senha. Informe a senha para continuar.",
  "codigo": "pdf_senha_necessaria",
  "precisa_senha_pdf": true,
  "senha_pdf": {
    "necessaria": true,
    "motivo": "ausente",
    "regra": "cpf_11_digitos",
    "orientacao": "...",
    "label_regra": "CPF completo (11 dígitos)",
    "tem_senha_cadastrada": false,
    "cartao_id": 46
  }
}
```

`codigo=pdf_senha_incorreta` → `motivo=incorreta`, em geral `tem_senha_cadastrada=true`.

---

## Como testar

1. Abrir http://localhost:3000/faturas/view/777 (ou stub Sofisa com `tem_senha_pdf`). Anexar o PDF Sofisa **sem** preencher senha. Conferir preview e total.
2. Cadastro pela lista: senha + salvar + arquivo → detalhe com PDF e lançamentos (não nasce outra fatura zerada).
3. Cartão sem senha + PDF com senha → modal `pdf_senha_necessaria`.
4. Senha salva errada → modal `pdf_senha_incorreta`, sem cair no detalhe vazio.

---

## Checklist

- [ ] Cadastro lista + senha + salvar → detalhe com PDF e lançamentos
- [ ] Retry após senha/metadados inclui o **mesmo** PDF
- [ ] 200 com `tem_pdf !== true` depois de fluxo com arquivo = erro, não abre detalhe vazio
- [ ] Reanexar na 777 (cartão com `tem_senha_pdf`) sem redigitar senha
- [ ] Modal só em 422 `pdf_senha_necessaria` / `pdf_senha_incorreta`
- [ ] Preview visível quando `tem_pdf`; dropzone quando não tem anexo
