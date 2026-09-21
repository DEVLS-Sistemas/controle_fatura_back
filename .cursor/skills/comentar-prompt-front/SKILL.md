---
name: comentar-prompt-front
description: Comenta no card CTLFAT o caminho do prompt de front (docs/frontend-prompt-*.md). Usar sempre que criar ou atualizar um prompt de frontend neste back, ao implementar ou ao iniciar o card.
---

# Comentar prompt de front no card

O Cursor do `controle_fatura_front` **não** herda esta skill. Lá a spec entra pelo comentário e a skill `ler-prompt-front`.

## Quando

- Criou ou alterou `docs/frontend-prompt-*.md` para o card atual.
- Imediatamente após gravar o arquivo. Não esperar commit, push ou PR.

## Como

1. `detect-jira-card`. Sem `card_key`, perguntar.
2. `getJiraIssue` (`view: full`) — se já existir comentário `Prompt front:` para o **mesmo** caminho, editar (`commentId`) em vez de criar outro.
3. `addOrEditJiraIssueComment` com `contentFormat: markdown`.

```
Prompt front: docs/frontend-prompt-….md

Branch back: `<branch atual>`
Repo: `controle_fatura_back`

Copiar o arquivo inteiro no chat do `controle_fatura_front` e implementar só o Front.

**O que o front faz:**
- <1–4 bullets do objetivo da tela>
```

Primeira linha começa exatamente com `Prompt front:` + caminho relativo à raiz deste repo.

## Não fazer

- Não omitir o comentário porque “já está na descrição do card”.
- Não usar outro rótulo (`Prompt:`, `Front:`, `docs:`).
- Não comentar se o card não tem Front.
