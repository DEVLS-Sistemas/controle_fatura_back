# Prompt — Frontend: Nome do arquivo anexado na fatura

Use este prompt no repositório do **frontend**. Copie o arquivo inteiro para o chat do front.

Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) e [`frontend-prompt-melhorias-faturas.md`](frontend-prompt-melhorias-faturas.md).

Card: CTLFAT-31.

O back passa a devolver o nome original do arquivo. O front só exibe esse nome. Não edita, não renomeia e não usa o caminho interno do storage.

---

## Problema

Ao anexar um arquivo na fatura (PDF, CSV ou outro tipo que o upload já aceite), a tela só mostra que existe anexo (`tem_pdf` / `tem_csv`) ou o caminho interno (`faturas/1/....pdf`). Não dá para ver qual arquivo a pessoa escolheu.

---

## O que o backend devolve

Na listagem (`GET /api/v1/faturas/listar`) e no detalhe (`GET /api/v1/faturas/listar/{id}`), cada fatura ganha:

| Campo | Quando vem preenchido | Quando vem `null` |
|-------|------------------------|-------------------|
| `anexo_pdf_nome` | Existe PDF no catálogo `anexos` | Sem PDF, ou fatura antiga sem linha no catálogo |
| `anexo_csv_nome` | Existe CSV (ou o outro tipo que ocupa esse slot) | Sem arquivo nesse slot |

O valor é o nome enviado no upload (ex.: `Fatura Nubank setembro.pdf`). Não é o caminho do blob, nem o hash, nem `arquivo_pdf` / `arquivo_csv`.

Troca de anexo devolve o nome do arquivo novo. Remoção zera o campo (`null`).

`arquivo_pdf` e `arquivo_csv` continuam sendo caminho interno. Não usar esses campos como rótulo.

---

## O que o front faz

1. Na **listagem** e no **detalhe** da fatura, mostrar o nome ao lado do ícone de PDF e/ou CSV.
2. Usar `anexo_pdf_nome` no ícone de PDF e `anexo_csv_nome` no ícone de CSV. Se os dois existirem, mostrar os dois.
3. Nome longo: truncar com reticências e o nome completo no tooltip.
4. Sem nome (`null`): manter só o ícone, como hoje.

Exemplo com os dois anexos:

```
[PDF] Fatura Nubank setembro.pdf    [CSV] nubank-09-2026.csv
```

---

## O que não fazer

- Não deixar o usuário editar o nome.
- Não mostrar nome de anexo de compra.
- Não exibir `arquivo_pdf` / `arquivo_csv` (path `faturas/1/....pdf`) no lugar do nome.
- Não inventar um nome quando o campo vier `null`.

---

## Como testar

- [ ] Anexar um PDF com nome reconhecível: o nome aparece na listagem e no detalhe, junto do ícone
- [ ] Anexar um CSV em outra fatura: o nome do CSV aparece no ícone de CSV
- [ ] Fatura com PDF e CSV mostra os dois nomes
- [ ] Nome longo trunca com reticências e o tooltip traz o nome inteiro
- [ ] Fatura sem anexo continua só com a ausência do anexo, sem nome inventado
- [ ] O caminho interno do storage não aparece como rótulo
