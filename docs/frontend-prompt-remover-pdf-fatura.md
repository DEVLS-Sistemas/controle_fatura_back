# Prompt — Frontend: Remover / trocar PDF da fatura

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Spec do back (mesmas etapas): [`modules/fatura-anexo-desvincular.md`](modules/fatura-anexo-desvincular.md).

Implementar **uma etapa por vez**, no mesmo número que o back. Quando o back começar a etapa N, o front faz a etapa N. Não invente rotas. Não crie módulo `compras`.

Prompts relacionados (não substituir; complementar):

- Tela de faturas: [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md)
- Ícones PDF/CSV, quitação, navegação: [`frontend-prompt-melhorias-faturas.md`](frontend-prompt-melhorias-faturas.md)
- Upload / metadados do PDF: [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md)
- Senha de PDF: [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md)
- Conciliação de compra manual: [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md)
- Detalhe da compra: [`frontend-prompt-visualizacao-compra.md`](frontend-prompt-visualizacao-compra.md)

---

## Problema que isso resolve

Hoje, ao anexar um PDF, o back cria as transações daquela fatura **e** copia parcelas para faturas anteriores e futuras. Se o PDF for o **errado** (competência vizinha, cartão certo mas mês errado, etc.):

- não dá para “desvincular” o anexo sem deixar lixo nas outras competências
- compras manuais já conciliadas com aquele extrato ficam presas / somem
- trocar o arquivo pelo upload atual **não** desfaz as parcelas geradas nas vizinhas

O fluxo novo: **perguntar o motivo** → mostrar o impacto → remover **ou** trocar o PDF com preview → devolver as compras manuais para “precisa conciliar” → se trocou, conciliar de novo no extrato certo.

---

## Etapas (visão geral)

| Etapa | O que o usuário vê | API que precisa existir |
|-------|--------------------|-------------------------|
| **1** | Botão **Remover PDF** no detalhe. Modal: “por que?” + lista do que vai acontecer. **Ainda não confirma** a remoção (CTAs da etapa 2/3 desabilitados se a API de POST ainda não existir — ver abaixo). | `GET /faturas/impacto-remover-anexo/{id}` + `pode_remover_anexo` no detalhe |
| **2** | Confirmar **Apenas remover**. Depois, modal das compras que voltaram a precisar conciliar. | `POST /faturas/remover-anexo` `{ motivo: "remover" }` |
| **3** | Escolher **PDF incorreto**. File picker + **preview** do PDF novo. Envia o arquivo junto. | `POST /faturas/remover-anexo` multipart `motivo=trocar_pdf` + `arquivo_pdf` (**API já existe**) |
| **4** | Depois do PDF certo processar: modal para conciliar as compras da fatura errada na fatura certa. | `GET /faturas/compras-para-reconcilia/{id}` + `POST /transacoes/conciliar` (já existe) |

Fallback se a etapa N do back ainda não estiver no ar: não quebrar a tela. Esconda o botão se `pode_remover_anexo` não vier; se o GET impacto 404, não mostre o modal.

Base: `/api/v1/faturas` (Bearer Sanctum). Envelope de sucesso: `{ "status": true, "message": "...", "data": { ... } }`. Erro: `{ "error": true, "message": "..." }`.

---

# Etapa 1 — Botão, motivo e preview de impacto

## Objetivo

1. No **detalhe da fatura**, oferecer **Remover PDF** (e/ou CSV) quando `pode_remover_anexo === true`.
2. Ao clicar, perguntar o **motivo** (dois caminhos claros).
3. Mostrar **o que vai acontecer** com parcelas de outras faturas e com compras já conciliadas — **antes** de apagar qualquer coisa.

## Onde fica o botão

Detalhe da fatura, no bloco do anexo (junto do ícone PDF / “Ver anexo” / Reprocessar):

```
[📄 Ver PDF]   [Remover PDF]
```

- Só renderiza se `pode_remover_anexo === true` (tem PDF ou CSV **e** a fatura não está `processando`).
- Se só tem CSV: label **Remover CSV**.
- Se tem os dois: um menu “Remover anexo…” com PDF / CSV / Ambos — a etapa 2 envia `tipo`. Na etapa 1 o GET já cobre os dois; o recorte `tipo` entra no POST.
- Listagem: **não** precisa do botão (fácil clicar errado). Ícone de anexo continua só informativo.
- Fatura `pendente` **sem** anexo (stub de parcela): **não** mostra o botão.

Tooltip: “Desfaz o extrato deste arquivo: apaga lançamentos importados e parcelas que este PDF criou em outras competências.”

## Abrir o modal → GET impacto

```http
GET /api/v1/faturas/impacto-remover-anexo/{id}
Authorization: Bearer {token}
```

Loading no modal enquanto busca. Se 422 (“não possui anexo” / “em processamento”) ou 404: toast com `message` e fecha.

Não calcule impacto no front. Não some transações locais. Confie no `data`.

## Layout do modal (etapa 1)

Título: **Remover o PDF desta fatura?**

Subtítulo: `{cartao_nome} · {bandeira} · {competencia}`

### Passo A — Motivo (obrigatório, duas opções grandes, não um select escondido)

```
┌─────────────────────────────────────────────┐
│  ○  PDF incorreto — quero trocar            │
│     Você vai escolher o arquivo certo       │
│     e conferir no preview.                  │
│                                             │
│  ○  Apenas remover                          │
│     Tira o extrato. Compras manuais         │
│     voltam a precisar de conciliação.       │
└─────────────────────────────────────────────┘
```

`motivos[]` da API traz `value` + `label`. Usar:

| `value` | Título na UI | Texto de apoio |
|---------|--------------|----------------|
| `trocar_pdf` | PDF incorreto — quero trocar | O arquivo desta competência está errado. Você escolhe o PDF certo, vê o preview e as compras conciliadas neste extrato poderão ser vinculadas de novo no arquivo correto. |
| `remover` | Apenas remover | Remove o extrato desta fatura. Lançamentos importados e parcelas que este PDF criou em faturas anteriores/futuras são apagados. Compras que você cadastrou **não** são apagadas — voltam a aparecer como “precisa conciliar”. |

Não permitir confirmar sem escolher um motivo.

### Passo B — Impacto (sempre visível depois do GET, mesmo antes de escolher o motivo)

Bloco de aviso (âmbar), alimentado por `avisos[]` (um item por linha). Se `avisos` vier vazio, montar a partir dos totais (fallback):

- `{lancamentos_deste_anexo.quantidade} lançamentos deste PDF serão apagados nesta fatura`
- `{parcelas_geradas_outras_faturas.quantidade} parcelas automáticas em outras competências serão apagadas`
- `{compras_que_voltam_a_conciliar.length} compras manuais voltam a precisar de conciliação`

#### Faturas vizinhas afetadas

Se `parcelas_geradas_outras_faturas.faturas_afetadas.length > 0`, tabela compacta:

| Competência | Parcelas | Valor | Depois |
|-------------|----------|-------|--------|
| 05/2026 | 3 | R$ 450,00 | Permanece |
| 09/2026 | 5 | R$ 890,00 | Fatura vazia será removida |

`ficara_vazia === true` → badge “Será removida” (era só stub, sem PDF próprio).  
`ficara_vazia === false` → “Permanece” (tem outras compras ou o próprio anexo).

Clique na competência (opcional): abre o detalhe daquela fatura em outra aba — não é obrigatório na etapa 1.

#### Compras que voltam a precisar conciliar

Se a lista `compras_que_voltam_a_conciliar` não for vazia, seção **“Estas compras voltam ao que eram”**:

Cada linha:

```
Mouse Logitech
R$ 249,90 · 23/08/2026 · Conciliada → Precisa conciliar
```

| Campo API | UI |
|-----------|----|
| `texto_compra` | Título |
| `valor` + `data` | Subtítulo |
| `parcela_atual` / `parcelas_total` | Se `parcelas_total > 1`, mostrar `3/10` |
| `status_conciliacao_atual` → `status_conciliacao_depois` | Chip: Conciliada → Não conciliada |
| `origem_restauracao` | Não precisa de label técnico. `match_exato` e `desvinculo` e `sugestao` caem no mesmo texto humano: “volta a precisar de conciliação” |

Lista vazia: texto “Nenhuma compra manual estava conciliada com este PDF.” (positivo, não um alerta).

Clique na compra (opcional): `GET /transacoes/visualizar/{id}` / rota já existente de visualização.

### Rodapé do modal (etapa 1)

| Botão | Comportamento |
|-------|----------------|
| Cancelar | Fecha. Nada é alterado. |
| Continuar | **Etapa 1:** se o POST ainda não existir, desabilitar com hint “Em breve: confirmar remoção”. **A partir da etapa 2:** segue conforme o motivo (ver etapas 2 e 3). |

Não chame `DELETE /faturas/excluir/{id}` neste fluxo. Remover PDF **não** é excluir a fatura.

## Campos novos no detalhe (e listagem)

Já vêm no `GET /faturas/listar/{id}` (e na listagem):

```json
{
  "tem_pdf": true,
  "tem_csv": false,
  "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/73",
  "pode_remover_anexo": true,
  "status": "processada"
}
```

| Campo | Uso |
|-------|-----|
| `pode_remover_anexo` | Mostrar o botão. Fallback se a API antiga não mandar: ` (tem_pdf \|\| tem_csv) && status !== 'processando'` |
| `status === 'processando'` | Esconder o botão; a fatura está no job |

## O que **não** fazer na etapa 1

- Não enviar `POST /remover-anexo` (etapa 2).
- Não abrir file picker (etapa 3).
- Não conciliar de novo (etapa 4).
- Não apagar fatura.
- Não inferir parcelas pelo `compra_grupo_id` no client.

## Checklist etapa 1

- [ ] Botão só no detalhe, só com `pode_remover_anexo`
- [ ] GET impacto ao abrir o modal; loading + erro tratados
- [ ] Duas opções de motivo visíveis (`trocar_pdf` / `remover`)
- [ ] Avisos da API renderizados
- [ ] Tabela de competências vizinhas com badge “será removida”
- [ ] Lista de compras que voltam a “precisa conciliar”
- [ ] Cancelar não chama API de escrita
- [ ] Stub sem anexo não tem o botão

---

# Etapa 2 — Confirmar “Apenas remover”

## Objetivo

1. Se o motivo for `remover`, pedir **confirmação explícita** (ação destrutiva).
2. Chamar o POST.
3. Mostrar o resultado: compras manuais de novo como **precisa conciliar**.

## Confirmação

Depois de Continuar com motivo `remover`, um segundo passo (mesmo modal ou dialog de confirmação):

Título: **Remover o PDF de {competencia}?**

Texto: “Isso apaga o extrato importado e as parcelas que este arquivo criou em outras faturas. As compras que você cadastrou **não** serão apagadas — elas voltam a aparecer para conciliar.”

Botões: **Voltar** · **Remover PDF** (destrutivo / outline vermelho).

Não usar `window.confirm`.

## API

```http
POST /api/v1/faturas/remover-anexo
Authorization: Bearer {token}
Content-Type: application/json
```

```json
{
  "id": 73,
  "motivo": "remover",
  "tipo": "pdf"
}
```

`tipo`: omitir se só existe um anexo; `csv` se o botão era Remover CSV; `ambos` só se o usuário escolheu os dois.

Loading no botão. 422/404 → toast `message`, permanece no modal.

## Depois do sucesso

1. Fechar o modal de confirmação.
2. Refetch `GET /faturas/listar/{id}` **e** `GET /transacoes/listar?fatura_id={id}` **e** a listagem de faturas (competências vizinhas mudaram).
3. Se `compras_que_voltaram_a_conciliar.length > 0`, abrir o **modal de apontamento** (obrigatório — é o item 2 do pedido).
4. Se a lista for vazia: toast `message` da API (ex.: “Anexo removido.”) e fica no detalhe, agora sem PDF (`tem_pdf: false`).

## Modal / tela de apontamento (obrigatório)

Título: **Compras que voltaram a precisar de conciliação**

Introdução (fixa, humana):

> O PDF foi removido. As compras abaixo estavam conciliadas com aquele extrato e **voltaram ao estado original**: continuam cadastradas, mas outra vez como compra que ainda precisa ser conciliada quando o PDF certo existir.

Lista (reusa o mesmo card da etapa 1):

```
Mouse Logitech
R$ 249,90 · 23/08/2026
Badge: Compra manual · conciliar com a fatura
```

Use `precisa_conciliar_label` se o refetch da transação já trouxer; senão o texto fixo acima.

Ações por linha:

- **Ver compra** → rota/modal de visualização já existente (`/compras/{id}` ou equivalente)
- Não pedir conciliar **agora** (não há extrato). O badge âmbar na fatura e na listagem de compras cuida do resto.

Rodapé: **Entendi** (fecha). Não pular este modal na primeira vez após a remoção. Não é um toast de 3 segundos — o usuário precisa **ver** o que aconteceu.

Se `faturas_stub_excluidas` vier preenchido, uma linha discreta no mesmo modal: “A competência 09/2026 era só projeção deste PDF e foi removida.”

## UI da fatura depois

- Ícone PDF some (`tem_pdf: false`).
- `status` volta para `pendente`.
- Compras manuais restauradas aparecem no grupo da fatura com o mesmo destaque âmbar de sempre (`precisa_conciliar === true`). Ver [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md).
- `valor_nao_conciliado` / `tem_compras_nao_conciliadas` devem atualizar no refetch — não calcule.

## Checklist etapa 2

- [ ] Confirmação explícita só para `motivo=remover`
- [ ] POST com `id` + `motivo: "remover"`
- [ ] Refetch detalhe + transações + listagem
- [ ] Modal de apontamento se houver compras restauradas
- [ ] Texto deixa claro que a compra **não foi apagada**
- [ ] Badge “precisa conciliar” reaparece nessas linhas
- [ ] Fatura permanece (não redireciona para exclusão)

---

# Etapa 3 — “PDF incorreto” com preview

## Objetivo

1. Motivo `trocar_pdf`: **não** remove na cega. O usuário escolhe o arquivo novo **antes** de confirmar.
2. Preview do PDF (e nome/tamanho) para confirmar que está o arquivo certo.
3. Um único submit: o back desfaz o PDF errado e anexa/processa o certo.

## UI depois de Continuar com `trocar_pdf`

Substituir o rodapé da etapa 1 por um passo **Trocar arquivo**:

Título: **Enviar o PDF correto**

1. Dropzone / input `accept=".pdf,.csv,application/pdf,text/csv"` — mesmo critério de [`frontend-prompt-melhorias-faturas.md`](frontend-prompt-melhorias-faturas.md).
2. Depois de escolher o arquivo:
   - Nome, tamanho, tipo.
   - **Preview:**
     - PDF: `<iframe>` ou viewer com `URL.createObjectURL(file)`. Revogar a URL ao fechar o modal / trocar o arquivo (`URL.revokeObjectURL`).
     - CSV: não precisa de iframe; mostrar as primeiras linhas em `<pre>` (FileReader).
   - Botão **Escolher outro arquivo**.
3. Checkbox opcional só se o cartão exigir senha (reusar o fluxo de [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md)): campo senha + `salvar_senha_pdf`.

O preview é **local**. Não existe `POST /preview-pdf`. Não faça upload só para “ver”.

Altura sugerida do preview: ~60vh no desktop; no mobile, tela cheia no passo do preview.

## Confirmar troca

Botão **Usar este PDF** (desabilitado até haver arquivo). Clique:

```http
POST /api/v1/faturas/remover-anexo
Authorization: Bearer {token}
Content-Type: multipart/form-data
```

Campos:

| Campo | Valor |
|-------|--------|
| `id` | id da fatura |
| `motivo` | `trocar_pdf` |
| `arquivo_pdf` | o arquivo (nome do campo **não muda** — igual ao upload atual) |
| `processar_automatico` | `true` |
| `senha_pdf` / `salvar_senha_pdf` | se o modal de senha estiver no fluxo |

422 `precisa_senha_pdf`: mesmo tratamento do cadastro/upload (abrir modal de senha e retry). Ver [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md).

422 sem arquivo: o botão nem deveria estar habilitado.

## Depois do 200

`data.aguardando_processamento === true` (ou `status` da fatura `pendente` / `processando`):

1. Fechar o passo do arquivo.
2. Se `compras_que_voltaram_a_conciliar` não for vazio, **não** pular o apontamento: mostre um estado intermediário curto (“Compras desfeitas; processando o PDF certo…”) **ou** vá direto ao poll e abra o apontamento **junto** da etapa 4 (preferir: um único modal com duas seções — “estas compras voltaram” + “conciliar no extrato novo” quando o job terminar).
3. Poll `GET /faturas/listar/{id}` a cada ~2s até `status` ser `processada` ou `erro` (máx. ~2 min). Reusar o padrão já usado depois de `POST /upload-pdf` / `POST /processar/{id}`.
4. `erro` → toast + bloco de erro já existente da fatura. Não abrir etapa 4.
5. `processada` → refetch transações e **abrir etapa 4**.

Invalidar também a listagem (quitação / stubs vizinhos).

## Checklist etapa 3

- [ ] File picker só no caminho `trocar_pdf`
- [ ] Preview PDF via object URL; revoke no unmount
- [ ] CSV: preview textual simples
- [ ] Submit multipart com `motivo=trocar_pdf` + `arquivo_pdf`
- [ ] Senha 422 tratada como no upload
- [ ] Poll até processada/erro
- [ ] Compras restauradas não são escondidas durante o processamento

---

# Etapa 4 — Conciliar de novo na fatura correta

## Objetivo

As compras que pertenciam ao PDF **errado** agora precisam casar com o extrato **certo**. O usuário vê a lista, o candidato sugerido, confirma (ou escolhe outro / deixa para depois).

## Quando abrir

Somente após `status === processada` no caminho **trocar PDF**.  
Não abrir na remoção pura (etapa 2) — lá não há extrato novo.

## API

```http
GET /api/v1/faturas/compras-para-reconcilia/{id}
Authorization: Bearer {token}
```

Se 404 (etapa 4 do back ainda não subiu): fallback — para cada id em `compras_que_voltaram_a_conciliar` chamar o já existente:

```http
GET /api/v1/transacoes/candidatos-conciliacao/{id}
```

e montar a mesma UI.

### Resposta

```json
{
  "status": true,
  "data": {
    "fatura_id": 73,
    "status": "processada",
    "compras_para_conciliar": [
      {
        "id": 901,
        "texto_compra": "Mouse Logitech",
        "valor": 249.90,
        "data": "2026-08-23",
        "precisa_conciliar": true,
        "candidatos": [
          {
            "id": 1204,
            "estabelecimento": "PAG*LOJA XYZ",
            "valor": 249.90,
            "data": "2026-08-23",
            "score": 0.92,
            "sugestao": true
          }
        ]
      }
    ]
  }
}
```

Lista vazia (match exato do job já conciliou tudo): **não** abrir o modal. Toast: “PDF atualizado. As compras foram conciliadas automaticamente.”

## UI do modal

Título: **Conciliar compras no PDF correto**

Introdução:

> Estas compras estavam no PDF anterior. Confira se o lançamento do arquivo novo é o mesmo e confirme.

Para cada compra:

```
Mouse Logitech · R$ 249,90 · 23/08/2026

Candidato sugerido:
PAG*LOJA XYZ · R$ 249,90 · 23/08/2026

[Confirmar]  [Escolher outro]  [Deixar para depois]
```

| Ação | API |
|------|-----|
| Confirmar | `POST /api/v1/transacoes/conciliar` `{ "compra_id": 901, "lancamento_id": 1204 }` — mesmo contrato de [`frontend-prompt-cadastro-manual-compra.md`](frontend-prompt-cadastro-manual-compra.md) |
| Escolher outro | abre o fluxo já existente de candidatos (`GET /transacoes/candidatos-conciliacao/{id}`) |
| Deixar para depois | não chama API; a compra permanece `precisa_conciliar` na fatura |

Marcar a linha como feita após 200 do conciliar (some da lista ou vira check verde). Quando a lista zerar (ou o usuário fechar): **Concluir** → refetch detalhe + transações.

Não invente matching no front. Não envie `estabelecimento` no conciliar além do que a API já espera.

## Checklist etapa 4

- [ ] Só abre no fluxo trocar PDF, fatura `processada`
- [ ] Usa `compras-para-reconcilia` (ou fallback de candidatos)
- [ ] Lista vazia → não abre modal, só toast
- [ ] Confirmar usa `POST /transacoes/conciliar` existente
- [ ] Deixar para depois mantém o badge âmbar na fatura
- [ ] Refetch ao fechar

---

## Regras transversais

1. **Compra ≠ lançamento do PDF.** Remover o PDF apaga o lançamento importado e as parcelas **geradas por ele**. A compra que o usuário cadastrou permanece.
2. Não criar tela/rota/model `Compra`. Ids são de `transacoes`.
3. Não usar `DELETE /faturas/excluir/{id}` para “tirar o PDF”.
4. `POST /upload-pdf` **solto** (sem `remover-anexo`) continua existindo para fatura **sem** anexo (stub). Não substitua esse caminho. O fluxo novo é para fatura **que já tem** PDF errado.
5. Isolamento: nunca enviar `user_id`.
6. Depois de qualquer escrita: refetch detalhe da fatura, lista de transações da fatura, listagem de faturas (vizinhas / quitação).
7. Acessibilidade: as duas opções de motivo são `radio` (ou botões com `aria-pressed`), não um dropdown.
8. Mobile: modal em sheet/tela cheia; preview do PDF em tela cheia.

## Copy (pt-BR)

| Situação | Texto |
|----------|--------|
| Botão | Remover PDF |
| Motivo A | PDF incorreto — quero trocar |
| Motivo B | Apenas remover |
| Confirmação remover | Remover o PDF desta competência? Compras cadastradas por você não serão apagadas. |
| Apontamento | Compras que voltaram a precisar de conciliação |
| Troca | Enviar o PDF correto |
| Preview vazio | Escolha o arquivo para visualizar |
| Etapa 4 | Conciliar compras no PDF correto |

## Ordem de implementação no front

1. Etapa 1 completa e revisada com API real de impacto.
2. Etapa 2 (POST remover + modal de apontamento).
3. Etapa 3 (preview + multipart).
4. Etapa 4 (reconciliação).

Não entregue o botão Remover PDF na produção apontando para `DELETE /excluir/{id}`.
