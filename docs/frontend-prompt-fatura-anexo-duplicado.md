# Prompt — Frontend: Anexo duplicado (mesmo conteúdo já importado)

Use este prompt no repositório do **frontend**. Backend **já implementado**. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md), [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md) e [`frontend-prompt-pessoas.md`](frontend-prompt-pessoas.md).

Trocar PDF de uma fatura **já aberta** (arquivo errado): [`frontend-prompt-remover-pdf-fatura.md`](frontend-prompt-remover-pdf-fatura.md) — fluxo diferente; não misturar.

---

## Problema

Na listagem (`GET /faturas/listar?mes=8&ano=2026`) o mesmo cartão/titular pode aparecer com **duas faturas da mesma competência**, cada uma com o **mesmo PDF**. Exemplo real:

- Cartão Nubank · titular LEONARDO S FERREIRA · competência `08/2026`
- Fatura `id=643` (R$ 6.138,97) e fatura `id=695` (R$ 5.358,62) — mesmo período, mesmo vencimento, mesmo arquivo.

Isso infla `total_faturas`, `valor_total` do grupo e duplica lançamentos.

---

## O que pode e o que não pode

| Situação | Permitido? |
|----------|------------|
| Mesma competência, **titulares diferentes** (Nubank do Leonardo **e** Nubank da Maysa) | **Sim** — são cartões/pessoas distintas. Já tratado em `precisa_cartao_do_titular` |
| Mesma competência, **mesmo titular**, PDFs **diferentes** | Não é o caso deste prompt (unicidade por cartão/bandeira+mês continua valendo) |
| **Mesmo arquivo** (mesmo conteúdo) já anexado em outra fatura da conta | **Não criar segunda fatura** — abrir o modal deste prompt |

O front **não calcula hash**. O back gera o hash do conteúdo do arquivo (depois de desbloquear a senha, se houver) e compara com anexos já gravados do usuário.

---

## Objetivo

No **cadastro** (`POST /cadastrar`) e no **upload** (`POST /upload-pdf` / `POST /remover-anexo` com `motivo=trocar_pdf`):

1. Se o arquivo for o **mesmo conteúdo** de uma fatura que já tem anexo → **422** `anexo_duplicado`.
2. Abrir modal na **mesma tela**: “esta fatura já foi anexada”.
3. Duas saídas explícitas:
   - **Substituir anexo** — usa este arquivo na fatura que já existe (reprocessa nela). **Não cria** outra linha.
   - **Salvar sem substituir** — mantém o anexo que já está lá. **Não cria** outra fatura. Encerra o fluxo apontando para a existente.

Não oferecer “importar mesmo assim” / “criar outra fatura”. É isso que gerou a duplicata.

Não tratar como `precisa_cartao_do_titular` (isso é **outra pessoa**, PDF diferente, outro cartão).

---

## Ordem dos 422 (atualizada)

```
1. precisa_senha_pdf
2. precisa_confirmar_metadados
3. precisa_confirmar_titular
4. precisa_cartao_do_titular     ← outra pessoa, PDF diferente, mesmo cartão/mês
5. anexo_duplicado               ← ESTE PROMPT (mesmo conteúdo já anexado)
6. precisa_selecionar_bandeira / precisa_selecionar_final
7. 200 sucesso
```

`anexo_duplicado` vem **depois** de senha (precisa ler o arquivo) e **depois** de titular/cartão-do-titular (para não misturar os dois casos). Se o hash bater, este modal tem prioridade sobre criar fatura nova.

**Não dispara** quando o arquivo é o anexo **da própria** fatura alvo (`POST /upload-pdf` com `id` = fatura que já tem esse hash, ou reprocessar). Reprocessar o mesmo PDF na mesma linha continua normal.

---

## API — 422

Dispara em `POST /api/v1/faturas/cadastrar` e `POST /api/v1/faturas/upload-pdf`.

```http
422 Unprocessable Entity
```

```json
{
  "error": true,
  "message": "Este arquivo já foi anexado em outra fatura. Deseja substituir o anexo ou manter o que já está salvo?",
  "codigo": "anexo_duplicado",
  "anexo_duplicado": true,
  "orientacao": "O conteúdo deste PDF/CSV é o mesmo da fatura Nubank 08/2026 (LEONARDO S FERREIRA). Substituir atualiza aquela fatura. Salvar sem substituir mantém o anexo atual e não cria outra fatura.",
  "fatura_existente": {
    "id": 643,
    "cartao_id": 33,
    "cartao_nome": "Nubank",
    "bandeira": "Mastercard",
    "pessoa_id": 6,
    "pessoa_nome": "LEONARDO S FERREIRA",
    "mes": 8,
    "ano": 2026,
    "competencia": "08/2026",
    "periodo_inicio": "2026-07-06",
    "periodo_fim": "2026-08-05",
    "data_vencimento": "2026-08-10",
    "valor_total": "6138.97",
    "status": "processada",
    "total_transacoes": 34,
    "tem_pdf": true,
    "tem_csv": false,
    "pdf_url": "http://10.0.0.164:5000/api/v1/faturas/pdf/643",
    "processado_em": "2026-08-27T20:42:25.000000Z",
    "created_at": "2026-08-26 11:57:57"
  }
}
```

| Campo | Uso |
|-------|-----|
| `codigo === "anexo_duplicado"` **ou** `anexo_duplicado === true` | Abrir este modal (não toast-and-forget) |
| `message` / `orientacao` | Título e texto do modal |
| `fatura_existente` | Card da fatura que já tem o arquivo — **obrigatório na UI** |
| `fatura_existente.id` | Retry + link “ver fatura” |
| `fatura_existente.pdf_url` | Link “ver anexo atual” (Bearer, igual ao detalhe) |

Se `fatura_existente.status === "processando"`: não oferecer Substituir; só **Salvar sem substituir** + aviso “aguarde o processamento terminar”.

---

## Modal

Título: **Esta fatura já foi anexada**

Não usar `window.confirm`.

### Corpo

1. Texto de `orientacao` (ou `message`).
2. Card da `fatura_existente` (somente leitura):
   - Cartão + bandeira (`Nubank · Mastercard`)
   - Titular (`pessoa_nome`)
   - Competência (`08/2026`) + ciclo (`periodo_inicio`–`periodo_fim`) + vencimento
   - Valor (`valor_total`) e `total_transacoes`
   - Badge do `status`
   - Link **Ver anexo atual** → `pdf_url` (nova aba / viewer já usado no detalhe)
   - Link **Abrir fatura** → rota de detalhe `id` (opcional; não fecha o modal sozinho)
3. Deixar claro o efeito de cada botão (uma linha cada).

### Botões

| Botão | Estilo | Efeito |
|-------|--------|--------|
| **Cancelar** | terciário | Fecha o modal, **não** chama API, arquivo permanece no dropzone (usuário pode escolher outro) |
| **Salvar sem substituir** | secundário / outline | Mantém o anexo da fatura existente. **Não cria** fatura nova |
| **Substituir anexo** | primário | Troca o anexo da fatura existente por este arquivo e reprocessa. **Não cria** fatura nova |

Copy sugerida sob os botões:

- Substituir: “O PDF atual da fatura 08/2026 será trocado por este arquivo e processado de novo.”
- Salvar sem substituir: “Nada muda na fatura que já existe. Este arquivo não será importado de novo.”

Se a fatura existente já tem PDF, Substituir é equivalente a “trocar PDF” **naquela** linha — o front **não** chama `POST /remover-anexo` à parte. O retry abaixo resolve.

---

## Retry

Mesmo endpoint que disparou o 422 (`/cadastrar` ou `/upload-pdf`). Multipart **completo**: arquivo + senha + metadados/titular já confirmados + o campo novo.

### Substituir anexo

| Campo | Valor |
|-------|--------|
| `arquivo_pdf` | o mesmo arquivo |
| `confirmar_anexo_duplicado` | `substituir` |
| `fatura_duplicada_id` | `fatura_existente.id` (ex.: `643`) |
| demais | o que já ia no retry (senha, `cartao_id`, `mes`, `ano`, `pessoa_id`, …) |

**Não** envie `id` de uma fatura **nova** no `/upload-pdf` para “criar em cima”. O alvo é sempre `fatura_duplicada_id`.

Resposta **200**: `data.id` = fatura existente (643, não um id novo). Poll / refetch / navegação usam **esse** id.

```json
{
  "fatura": {
    "data": {
      "id": 643,
      "mes": 8,
      "ano": 2026,
      "status": "processando"
    },
    "status": true,
    "message": "Anexo substituído. A fatura está sendo processada."
  }
}
```

Envelope igual ao cadastro/upload atuais (`fatura.data`). Depois: refetch da listagem + detalhe `643`. Se `status=processando`, o poll que já existe no upload.

### Salvar sem substituir

| Campo | Valor |
|-------|--------|
| `confirmar_anexo_duplicado` | `manter` |
| `fatura_duplicada_id` | `fatura_existente.id` |
| `arquivo_pdf` | **pode omitir** neste retry (o back não vai gravar) |

Resposta **200**: `data` = fatura existente, sem reprocessar.

```json
{
  "fatura": {
    "data": { "id": 643, "mes": 8, "ano": 2026, "status": "processada" },
    "status": true,
    "message": "Anexo mantido. Nenhuma fatura nova foi criada."
  }
}
```

Depois: fechar o modal, toast com `message`, ir ao detalhe `643` (ou só refetch da lista se o usuário já estava nela). **Não** deixar uma linha extra na listagem.

Atalho aceitável: **Cancelar** e **Salvar sem substituir** podem ter o mesmo efeito visual se o front não quiser o retry de `manter` — desde que **não** dispare um `POST /cadastrar` sem a flag (isso criaria a duplicata). Preferir o retry `manter` para o back registrar a decisão e devolver o `id` canônico.

---

## Onde encaixa no fluxo atual

```
[PDF no dropzone / upload na linha]
        │
        ▼
POST /cadastrar  ou  POST /upload-pdf
        │
        ▼
422 anexo_duplicado  ──► modal
        │                    │
        │     Substituir     │     Salvar sem substituir
        │     retry flag     │     retry flag manter
        │     substituir     │            │
        ▼                    ▼            ▼
   200 id=existente     200 id=existente (sem arquivo novo)
        │
        ▼
listagem: UM card Nubank 08/2026 daquele titular (não dois)
```

Upload numa **linha stub** (fatura `pendente` sem anexo) cujo arquivo é hash de **outra** fatura já processada: mesmo 422. Substituir atualiza a que **já tem** o conteúdo (`fatura_existente`), não anexa no stub. Salvar sem substituir deixa o stub como está.

---

## Listagem (regressão)

Depois deste fluxo, `GET /faturas/listar?mes=&ano=` **não** deve ganhar um segundo item no array `faturas[]` do mesmo cartão/titular/competência por causa do mesmo PDF.

O grupo do cartão já pode ter `total_faturas > 1` quando há **titulares/cartões** diferentes — isso é válido. O bug é **dois ids no mesmo titular + mesmo conteúdo**.

Não precisa de UI nova na listagem para “fundir” duplicatas antigas neste prompt. Só impedir a criação da próxima.

---

## Anti-padrões (não fazer)

- ❌ Calcular hash no browser e comparar por conta própria
- ❌ Toast “já existe” sem modal de escolha
- ❌ Botão “importar mesmo assim” / criar segunda fatura
- ❌ Tratar `anexo_duplicado` como `precisa_cartao_do_titular` (cadastrar outro cartão)
- ❌ Chamar `POST /remover-anexo` no front para o caso Substituir — o retry com a flag basta
- ❌ Navegar / pollar um `id` novo depois do 200 de substituir/manter
- ❌ Fechar o dropzone apagando o arquivo no Cancelar sem o usuário pedir (ele pode querer outro PDF)

---

## Checklist de aceite

- [ ] `codigo === "anexo_duplicado"` abre modal (cadastro **e** upload na linha)
- [ ] Card mostra cartão, titular, competência, valor e link do anexo atual
- [ ] **Substituir anexo** → retry `confirmar_anexo_duplicado=substituir` + `fatura_duplicada_id` + arquivo → 200 com `data.id` da existente → refetch/poll nesse id
- [ ] **Salvar sem substituir** → não cria fatura nova; listagem continua com uma linha daquele titular/competência
- [ ] **Cancelar** não chama API de escrita
- [ ] Não há CTA de “criar outra fatura com o mesmo PDF”
- [ ] Mesma competência + **outro titular** continua no fluxo `precisa_cartao_do_titular` (não neste modal)
- [ ] Reprocessar / upload do **mesmo** arquivo na **mesma** fatura não abre este modal
- [ ] Ordem: senha → metadados → titular → cartão do titular → **anexo duplicado**
- [ ] PDF com senha: hash/modal só depois de `senha_pdf` válido
- [ ] Fatura existente `processando`: Substituir desabilitado
- [ ] Após sucesso, `data.mes` / `data.ano` / `data.id` da resposta (competência lida do arquivo pode diferir da linha clicada)

---

## Notas

- Hash é detalhe de backend (arquivo já desbloqueado). Dois downloads byte-a-byte iguais = duplicata. Front só reage ao 422.
- Valores diferentes nas duas linhas duplicadas atuais (ex.: 6138.97 vs 5358.62) podem ser reprocessamento parcial do **mesmo** PDF — mais um motivo para não criar a segunda linha.
- Envelope de erro 422 segue o padrão dos outros gates (`error`, `message`, `codigo` + flag booleana).
