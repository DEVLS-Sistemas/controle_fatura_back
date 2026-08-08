# Prompt — Frontend: Cadastro de fatura com detecção de cartão / mês / ano pelo anexo

Use este prompt no repositório do frontend para alinhar o **cadastro de fatura** à API do `controle_fatura_back`.

Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) e o modal de senha em [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md).

---

## Objetivo

Simplificar o cadastro:

1. **Nada é obrigatório de cara** (nem cartão, mês, ano, nem anexo).
2. **Sem anexo** → cartão + mês + ano passam a ser **obrigatórios**.
3. **Com anexo (PDF/CSV)** → cartão + mês + ano **não** são obrigatórios no formulário inicial.
4. Se o back ler dados do arquivo → abrir **modal na mesma tela** (nunca mandar o usuário para outra rota cadastrar cartão e voltar).
5. Se o cartão **não** foi identificado na conta → o modal deixa **explícito** que dá para **cadastrar o cartão ali** (nome + bandeira), junto com mês/ano.
6. Após confirmar, reenviar o `POST /cadastrar` (multipart) e concluir tudo de uma vez.

---

## Regras de formulário

| Situação | Cartão | Mês | Ano | Anexo |
|----------|--------|-----|-----|-------|
| Formulário inicial | opcional | opcional | opcional | opcional |
| Submit **sem** anexo | **obrigatório** | **obrigatório** | **obrigatório** | — |
| Submit **com** anexo | opcional* | opcional* | opcional* | obrigatório para este fluxo |

\* Se o back não conseguir detectar, 422 pedindo preenchimento manual.

UI sugerida do formulário:

1. Dropzone / input de arquivo em destaque (PDF ou CSV).
2. Abaixo (ou colapsado): selects de cartão, mês e ano — úteis sem anexo.
3. Botão “Cadastrar”.

---

## Fluxo completo

```
[Usuário escolhe PDF/CSV] ──► POST /api/v1/faturas/cadastrar
                                      │
          ┌───────────────────────────┼───────────────────────────┐
          ▼                           ▼                           ▼
   422 senha PDF              422 metadados                 200 sucesso
   (precisa_senha)            (confirmar / cadastrar)
          │                           │
          ▼                           ▼
   Modal senha                 Modal na MESMA tela
          │                    (ver modos abaixo)
          └────────────┬──────────────┘
                       ▼
              Reenviar multipart completo
```

Ordem dos modais:

1. **Senha do PDF** (`precisa_senha_pdf`)
2. **Metadados** (`precisa_confirmar_metadados`) — modo `confirmar_cartao` **ou** `cadastrar_cartao`
3. Legados (só se ainda faltar algo): `precisa_selecionar_bandeira` / `precisa_selecionar_final`
4. Sucesso

---

## API

```http
POST /api/v1/faturas/cadastrar
Authorization: Bearer {token}
Content-Type: multipart/form-data
```

### Request — só anexo

| Campo | Tipo | Obrigatório |
|-------|------|-------------|
| `arquivo_pdf` | file (PDF/CSV) | sim neste fluxo |
| `processar_automatico` | bool | não (default `true`) |
| `senha_pdf` | string | se PDF protegido |
| `salvar_senha_pdf` | bool | não |

### Request — retry modo `confirmar_cartao` (cartão já existe)

| Campo | Tipo | Notas |
|-------|------|-------|
| `arquivo_pdf` | file | mesmo arquivo |
| `cartao_id` | int | cartão existente |
| `mes` / `ano` | int | confirmados |
| `cartao_bandeira_id` | int | se bandeira já existe |
| `bandeira` | string | se precisa criar bandeira no cartão (`criar: true`) |
| `senha_pdf` | string | se já desbloqueou |

### Request — retry modo `cadastrar_cartao` (cartão ainda não existe)

**Não envie `cartao_id`.** Cadastre o cartão **nesta mesma request**:

| Campo | Tipo | Obrigatório | Notas |
|-------|------|-------------|-------|
| `arquivo_pdf` | file | sim | mesmo arquivo |
| `cadastrar_cartao` | bool | recomendado (`true`) | deixa explícito o fluxo inline (opcional se `cartao_nome` + `bandeira` vierem) |
| `cartao_nome` | string | **sim** | nome do grupo (ex.: `Inter`, `C6`) |
| `bandeira` | string | **sim** | label do lookup (`Visa`, `Mastercard`, …) |
| `mes` / `ano` | int | **sim** | da sugestão (editáveis) |
| `banco` | string | não | default = `cartao_nome` |
| `dia_limite_fatura` | int | não | default `5` (`sugestao.dia_limite_fatura_padrao`) |
| `dia_vencimento_fatura` | int | não | default `10` |
| `senha_pdf_regra` | string | se selecionada | grava a regra no cartão novo (ex.: `cpf_cnpj_6_digitos`) |
| `senha_pdf` / `salvar_senha_pdf` | — | se PDF com senha | senha pode ser gravada no cartão novo |

O back cria o cartão + bandeira e em seguida a fatura com o PDF — **tudo numa request**. O usuário **não** precisa ir para a tela de cartões.

---

## Modal — confirmar metadados

Dispara em **422** com `codigo = precisa_confirmar_metadados`.

Leia **`modo`** (ou `pode_cadastrar_cartao`) para montar a UI correta.

### Modo A — `cadastrar_cartao` (cartão não está na conta)

Use quando `modo === "cadastrar_cartao"` ou `pode_cadastrar_cartao === true` (em geral `sugestao.cartao_id === null`).

**Não** mostre um select vazio de cartões como ação principal — isso parece que a pessoa precisa sair, cadastrar e voltar.

#### UI obrigatória

1. **Título:** algo como “Concluir cadastro da fatura”
2. **Texto de orientação** (use `orientacao` / `message` da API), deixando claro:
   - Identificamos **mês** e **ano** da fatura
   - Este cartão **ainda não está cadastrado**
   - Você pode **cadastrar o cartão aqui neste modal** (nome + bandeira) e finalizar — **sem sair desta tela**
3. Campos:
   - **Mês** / **Ano** — pré-preenchidos com `sugestao.mes` / `sugestao.ano` (editáveis)
   - **Nome do cartão** — input texto; pré-preencher com `sugestao.cartao_nome_sugerido` (ex.: `Inter`, `C6`, `Sofisa`)
   - **Bandeira** — select com `bandeiras[]` (itens `criar: true`); pré-selecionar `sugestao.bandeira_sugerida` se houver
4. Opcional informativo: finais detectados, valor da fatura, parser
5. Se `sugestao.conferencia` existir e `bate === false`, avisar que o total do PDF diverge da soma das transações (o back já usa a soma). Exibir `valor_cabecalho` vs `soma_transacoes`.
6. Botão primário: **“Cadastrar cartão e fatura”** (não só “Confirmar”)
6. **Atalho secundário (opcional, colapsado):** “Já tenho este cartão” → aí sim mostra `cartoes[]` para vincular a um existente (`modo` passa a se comportar como confirmar: envia `cartao_id` em vez de `cadastrar_cartao`)

#### Exemplo de resposta (modo cadastrar)

```json
{
  "error": true,
  "message": "Identificamos mês e ano da fatura. Cadastre o cartão nesta mesma tela (nome e bandeira) para concluir — não é preciso sair desta tela.",
  "codigo": "precisa_confirmar_metadados",
  "precisa_confirmar_metadados": true,
  "modo": "cadastrar_cartao",
  "pode_cadastrar_cartao": true,
  "precisa_selecionar_bandeira": true,
  "orientacao": "O cartão desta fatura ainda não está na sua conta. Informe o nome e a bandeira aqui no modal; o cadastro do cartão e da fatura são concluídos juntos, sem ir para outra tela.",
  "sugestao": {
    "cartao_id": null,
    "cartao_nome": "Inter",
    "cartao_nome_sugerido": "Inter",
    "mes": 7,
    "ano": 2026,
    "parser": "inter",
    "ultimos_digitos": ["1668"],
    "bandeira_sugerida": "Mastercard",
    "cartao_bandeira_id": null,
    "valor_fatura": 6137.69,
    "conferencia": {
      "valor_cabecalho": 6137.69,
      "soma_transacoes": 6137.69,
      "bate": true,
      "diferenca": 0
    },
    "confianca": "baixa",
    "dia_limite_fatura_padrao": 5,
    "dia_vencimento_fatura_padrao": 10
  },
  "cartoes": [
    { "value": 12, "label": "SOFISA", "banco": null }
  ],
  "bandeiras": [
    { "value": null, "label": "Visa", "criar": true },
    { "value": null, "label": "Mastercard", "criar": true }
  ],
  "candidatos_cartao": []
}
```

#### Retry (cadastrar)

```http
POST /api/v1/faturas/cadastrar
Content-Type: multipart/form-data
```

- `arquivo_pdf` = arquivo
- `cadastrar_cartao` = `true`
- `cartao_nome` = `"Inter"`
- `bandeira` = `"Mastercard"`
- `mes` = `7`
- `ano` = `2026`
- (+ `senha_pdf` se necessário)

---

### Modo B — `confirmar_cartao` (cartão já existe)

Use quando `modo === "confirmar_cartao"` (`sugestao.cartao_id` preenchido).

1. Select **Cartão** com `cartoes[]` (pré-selecionar `sugestao.cartao_id`)
2. **Mês** / **Ano**
3. **Bandeira** se `precisa_selecionar_bandeira`
4. Botão: **“Confirmar e cadastrar fatura”**

```json
{
  "codigo": "precisa_confirmar_metadados",
  "modo": "confirmar_cartao",
  "pode_cadastrar_cartao": false,
  "sugestao": {
    "cartao_id": 17,
    "cartao_nome": "C62",
    "mes": 7,
    "ano": 2026,
    "confianca": "alta"
  }
}
```

Retry: `cartao_id` + `mes` + `ano` + arquivo (+ bandeira se preciso). **Não** envie `cadastrar_cartao`.

---

## Anti-padrões (não fazer)

- ❌ Select de cartão vazio como único caminho quando `modo = cadastrar_cartao`
- ❌ Texto/link “cadastre o cartão em Cartões e volte”
- ❌ Fechar o modal e redirecionar para `/cartoes` no meio do fluxo de upload
- ❌ Exigir que o usuário anexe o PDF de novo depois de cadastrar o cartão em outra tela

---

## Quando o back não detecta

```json
{
  "error": true,
  "message": "Não foi possível identificar cartão, mês e ano pelo arquivo. Informe esses campos manualmente."
}
```

1. Toast com a mensagem  
2. Exigir cartão/mês/ano no formulário (ou permitir o mesmo bloco “cadastrar cartão” no formulário)  
3. Manter o arquivo selecionado  

---

## Senha de PDF

Mesmo contrato de [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md). Ordem: senha → metadados → sucesso.

No modo `cadastrar_cartao`, envie `senha_pdf_regra` se o usuário escolheu a regra. Se marcar “salvar senha”, envie também `senha_pdf` + `salvar_senha_pdf=true` — senha e regra ficam no cartão **novo**.

---

## Checklist de aceite

- [ ] Formulário inicial sem obrigatoriedade de cartão/mês/ano/anexo
- [ ] Submit só com PDF abre modal de metadados quando o back detecta dados
- [ ] Se `modo = cadastrar_cartao`: UI mostra mês/ano + **nome do cartão** + **bandeira**, com texto explícito de que o cadastro é **neste modal**
- [ ] Botão primário deixa claro: “Cadastrar cartão e fatura”
- [ ] Retry com `cadastrar_cartao=true` + `cartao_nome` + `bandeira` + `mes` + `ano` + arquivo → 200 sem ir a outra tela
- [ ] Se `modo = confirmar_cartao`: select de cartão existente + mês/ano (+ bandeira se preciso)
- [ ] Não há CTA que mande o usuário sair para cadastrar cartão e voltar anexar
- [ ] PDF com senha: modal de senha antes; depois metadados
- [ ] Após sucesso: refetch da listagem
- [ ] Fluxo antigo (já com `cartao_id`/`mes`/`ano`) continua sem abrir o modal

---

## Notas

- Competência sugerida = fechamento/vencimento do extrato; usuário pode ajustar.
- Match de cartão existente: finais `••••` cadastrados, senão nome/banco do parser.
- Defaults de ciclo no cartão novo: fechamento dia 5, vencimento dia 10 (ajustáveis depois na tela de cartões).
- Finais detectados no PDF são criados na bandeira da fatura no processamento automático.
