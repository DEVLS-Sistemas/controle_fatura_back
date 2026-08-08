# Prompt — Frontend: Senha de PDF da fatura (cartão + modal)

Use este prompt no repositório do frontend para implementar cadastro de senha de PDF no cartão e o modal de desbloqueio ao processar faturas protegidas (ex.: C6 Bank).

---

## Contexto

Alguns bancos (hoje: **C6**) protegem o PDF da fatura com senha. No C6, a senha são os **6 primeiros dígitos do CPF ou CNPJ** do titular.

Fluxo desejado:

1. A senha pode ser cadastrada no **cartão (grupo)** — campo tipo senha, com ícone de olho para revelar/ocultar.
2. Se a senha já estiver no cartão, o processamento usa automaticamente — o usuário **não digita de novo**.
3. Se a senha estiver **ausente** ou **incorreta**, abrir um **modal** pedindo a senha, com texto de orientação conforme a regra do cartão.
4. No mesmo modal, perguntar se deseja **gravar a senha** no cartão para próximas faturas.
5. Cada cartão pode ter uma **regra de senha** (`senha_pdf_regra`) para orientar a digitação. Regras disponíveis: 4/5/6/8 primeiros dígitos do CPF/CNPJ, CPF completo (11) e CNPJ completo (14). C6 sugere `cpf_cnpj_6_digitos`.

A API **nunca devolve a senha em claro** — só `tem_senha_pdf: true|false`.

---

## API — Cartões

Base: `/api/v1/cartoes` (Bearer Sanctum)

### Lookups (`GET /lookups`)

Novo campo:

```json
{
  "senhas_pdf_regras": [
    {
      "value": "cpf_cnpj_4_digitos",
      "label": "4 primeiros dígitos do CPF/CNPJ",
      "orientacao": "Informe os 4 primeiros dígitos do CPF ou CNPJ do titular (somente números, sem pontos ou traços).",
      "digitos": 4,
      "bancos_sugeridos": []
    },
    {
      "value": "cpf_cnpj_5_digitos",
      "label": "5 primeiros dígitos do CPF/CNPJ",
      "orientacao": "...",
      "digitos": 5,
      "bancos_sugeridos": []
    },
    {
      "value": "cpf_cnpj_6_digitos",
      "label": "6 primeiros dígitos do CPF/CNPJ",
      "orientacao": "Informe os 6 primeiros dígitos do CPF ou CNPJ do titular. Essa é a senha usada nas faturas do C6 Bank.",
      "digitos": 6,
      "bancos_sugeridos": ["C6", "C6 Bank", "C6Bank"]
    },
    {
      "value": "cpf_cnpj_8_digitos",
      "label": "8 primeiros dígitos do CPF/CNPJ",
      "orientacao": "...",
      "digitos": 8,
      "bancos_sugeridos": []
    },
    {
      "value": "cpf_11_digitos",
      "label": "CPF completo (11 dígitos)",
      "orientacao": "...",
      "digitos": 11,
      "bancos_sugeridos": []
    },
    {
      "value": "cnpj_14_digitos",
      "label": "CNPJ completo (14 dígitos)",
      "orientacao": "...",
      "digitos": 14,
      "bancos_sugeridos": []
    }
  ]
}
```

Use `digitos` no front para `maxLength` / máscara do campo senha (somente números).

### Payload create / edit

Campos novos no raiz do grupo:

| Campo | Tipo | Observação |
|-------|------|------------|
| `senha_pdf` | string opcional | Só envie se o usuário **digitou** um valor novo. Não envie string vazia por engano (isso limpa a senha). |
| `senha_pdf_regra` | string\|null | Código da regra (`cpf_cnpj_6_digitos`). Se omitido no create e o `banco` for C6, o back sugere automaticamente. |
| `limpar_senha_pdf` | bool | No edit: `true` remove a senha salva. |

```json
{
  "nome": "C6",
  "banco": "C6",
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12,
  "senha_pdf": "123456",
  "senha_pdf_regra": "cpf_cnpj_6_digitos",
  "ativo": true,
  "bandeiras": []
}
```

### Resposta listar / detalhe / create / edit

```json
{
  "id": 1,
  "nome": "C6",
  "banco": "C6",
  "tem_senha_pdf": true,
  "senha_pdf_regra": "cpf_cnpj_6_digitos",
  "senha_pdf_orientacao": "Informe os 6 primeiros dígitos do CPF ou CNPJ do titular. Essa é a senha usada nas faturas do C6 Bank.",
  "senha_pdf_regra_label": "6 primeiros dígitos do CPF/CNPJ"
}
```

**Não existe** campo `senha_pdf` na resposta.

---

## UI — Formulário do cartão

Na seção do grupo (junto de nome/banco/ciclo):

1. **Regra da senha do PDF** — select com `senhas_pdf_regras` do lookup (+ opção “Nenhuma”).
   - Ao mudar `banco` para algo que case com `bancos_sugeridos` (ex.: C6), pré-selecionar a regra correspondente se ainda estiver vazia.
   - Abaixo do select, mostrar `orientacao` da regra escolhida (texto de ajuda).

2. **Senha do PDF** — input `type="password"` com botão/ícone de **olho** para alternar visibilidade (mesmo padrão do login).
   - Label: “Senha do PDF da fatura”
   - Placeholder quando `tem_senha_pdf === true`: `••••••` ou “Senha já cadastrada — digite para alterar”
   - Helper: “Usada automaticamente ao importar faturas deste cartão. Opcional.”
   - Checkbox/ação “Remover senha salva” → envia `limpar_senha_pdf: true` (e **não** envia `senha_pdf`).
   - No submit de edição: **só inclua `senha_pdf` no payload se o usuário digitou algo** no campo.

---

## API — Faturas

### Cadastro / upload com PDF

`POST /api/v1/faturas/cadastrar` e `POST /api/v1/faturas/upload-pdf` (multipart) aceitam opcionalmente:

| Campo | Tipo |
|-------|------|
| `senha_pdf` | string |
| `senha_pdf_regra` | string opcional — regra selecionada no modal; grava no cartão (create inline ou ao salvar senha) |
| `salvar_senha_pdf` | bool (`true`/`1`/`false`) — grava a senha no cartão **após** desbloqueio ok |

O cadastro **não falha** se o PDF precisar de senha: a fatura fica `status=erro` com metadados para o modal.

Resposta (trecho relevante):

```json
{
  "fatura": {
    "data": {
      "id": 10,
      "status": "erro",
      "erro_codigo": "pdf_senha_necessaria",
      "erro_mensagem": "Este PDF da fatura está protegido por senha. Informe a senha para continuar.",
      "precisa_senha_pdf": true,
      "senha_pdf": {
        "necessaria": true,
        "motivo": "ausente",
        "regra": "cpf_cnpj_6_digitos",
        "orientacao": "Informe os 6 primeiros dígitos do CPF ou CNPJ do titular. Essa é a senha usada nas faturas do C6 Bank.",
        "label_regra": "6 primeiros dígitos do CPF/CNPJ",
        "tem_senha_cadastrada": false,
        "cartao_id": 1
      }
    },
    "status": true,
    "message": "Fatura cadastrada com sucesso!",
    "precisa_senha_pdf": true
  }
}
```

> A estrutura exata aninha em `fatura` conforme o controller atual (`result->fatura`). Trate `precisa_senha_pdf` no nível do objeto de sucesso **ou** em `data`.

### Reprocessar com senha

```http
POST /api/v1/faturas/processar/{id}
Content-Type: application/json

{
  "senha_pdf": "123456",
  "senha_pdf_regra": "cpf_cnpj_6_digitos",
  "salvar_senha_pdf": true
}
```

- **Sucesso (200):** processamento ok (ou iniciado); `precisa_senha_pdf: false`.
- **Senha ausente/incorreta (422):**

```json
{
  "error": true,
  "message": "A senha informada para o PDF da fatura está incorreta.",
  "codigo": "pdf_senha_incorreta",
  "precisa_senha_pdf": true,
  "senha_pdf": {
    "necessaria": true,
    "motivo": "incorreta",
    "regra": "cpf_cnpj_6_digitos",
    "orientacao": "...",
    "label_regra": "...",
    "tem_senha_cadastrada": true,
    "cartao_id": 1
  }
}
```

### Listagem / detalhe

Quando `status === "erro"` e `erro_codigo` for `pdf_senha_necessaria` ou `pdf_senha_incorreta`:

- `precisa_senha_pdf: true`
- `senha_pdf` com o objeto acima (ou `null` se não for erro de senha)

Códigos:

| `erro_codigo` | `senha_pdf.motivo` | Significado |
|---------------|--------------------|-------------|
| `pdf_senha_necessaria` | `ausente` | PDF criptografado e não há senha (nem no request nem no cartão) |
| `pdf_senha_incorreta` | `incorreta` | Senha tentada (cartão ou digitada) está errada |

Lookups de fatura também expõem `senhas_pdf_regras` e, em cada cartão, `tem_senha_pdf` / `senha_pdf_regra` / `senha_pdf_orientacao`.

---

## UI — Modal de senha do PDF

### Quando abrir

Abrir automaticamente quando, após `cadastrar`, `upload-pdf` ou ao inspecionar fatura com erro:

- `precisa_senha_pdf === true`, **ou**
- `erro_codigo` ∈ (`pdf_senha_necessaria`, `pdf_senha_incorreta`), **ou**
- resposta 422 de `processar` com `precisa_senha_pdf`

Também oferecer ação “Informar senha” / “Desbloquear PDF” na listagem/detalhe quando `precisa_senha_pdf`.

### Conteúdo do modal

1. **Título:** “PDF protegido por senha”
2. **Texto explicativo** conforme `motivo`:
   - `ausente`: “Esta fatura está em um PDF com senha. Informe a senha para importar os lançamentos.”
   - `incorreta`: “A senha usada não desbloqueou o PDF. Verifique e tente novamente.”  
     Se `tem_senha_cadastrada`: acrescentar “A senha salva neste cartão pode estar desatualizada.”
3. **Orientação da regra** (`senha_pdf.orientacao` ou `label_regra`): destaque visual (alerta/info).  
   Ex. C6: “Use os 6 primeiros dígitos do CPF ou CNPJ do titular.”
4. **Campo senha** — `type="password"` + ícone de olho (mesmo padrão do login / cadastro do cartão).
5. **Checkbox:** “Salvar senha neste cartão para próximas faturas” → `salvar_senha_pdf`.
   - Default sugerido: marcado se `tem_senha_cadastrada === false`; desmarcado (ou marcado) se já havia senha e falhou — UX livre, mas o texto deve deixar claro que atualiza a senha do cartão.
6. **Ações:**
   - Primária: “Desbloquear e processar” → `POST /faturas/processar/{id}` com `senha_pdf` + `salvar_senha_pdf`
   - Secundária: “Agora não” / fechar (fatura permanece com erro; usuário pode voltar depois)

### Após sucesso

- Fechar modal
- Atualizar detalhe/listagem da fatura (`status=processada` ou recarregar)
- Toast de sucesso

### Após 422 no modal

- Manter modal aberto
- Mostrar `message` / nova `orientacao`
- Limpar ou selecionar o campo senha para nova tentativa

---

## Regras de UX (resumo)

| Situação | Comportamento |
|----------|----------------|
| Cartão com `tem_senha_pdf` e senha correta | Processa sem modal |
| Cartão sem senha + PDF com senha | Modal (`motivo=ausente`) |
| Senha do cartão errada | Modal (`motivo=incorreta`, `tem_senha_cadastrada=true`) |
| Usuário marca “salvar” e processa ok | Próximas faturas do cartão usam a senha sem perguntar |
| Usuário não marca “salvar” | Só vale para aquele processamento |

---

## Checklist

- [ ] Campo senha + olho no formulário de cartão
- [ ] Select de regra + texto de orientação; auto-sugestão para banco C6
- [ ] Não enviar `senha_pdf` no edit se o campo não foi alterado
- [ ] `limpar_senha_pdf` para remover senha salva
- [ ] Detectar `precisa_senha_pdf` / `erro_codigo` após upload/cadastro
- [ ] Modal com orientação, senha + olho, checkbox salvar, submit em `processar/{id}`
- [ ] Tratar 422 do processar sem fechar o modal
- [ ] Ação manual “Informar senha” na fatura com erro de senha

---

## Backend de referência

- `docs/modules/cartoes.md`
- `docs/modules/faturas.md`
- Regras: `App\Services\Pdf\PdfSenhaRegra`
