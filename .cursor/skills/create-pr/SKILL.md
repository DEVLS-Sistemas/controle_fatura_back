---
name: create-pr
description: Cria PR para v1.0/dev e comenta o link no card CTLFAT. Neste repo o comentário é PR back; no front é PR front. Usar quando o usuário pedir para abrir ou criar um PR.
---

# Criar PR (CTLFAT)

## Base

- Head: branch atual (`git branch --show-current`)
- Base: `v1.0/dev`
- `main` só se o usuário pedir deploy

## Card

Usar `detect-jira-card`. Sem `card_key` na branch, perguntar antes de comentar no Jira.

## Criar

`gh pr create --base v1.0/dev --head <branch>`. Guardar a URL.

## Comentar no card

Obrigatório quando o PR nasceu e a branch tem `CTLFAT-XXXX`.

`addOrEditJiraIssueComment` com `contentFormat: markdown`. Primeira linha com o rótulo fixo; em seguida o que foi implementado **neste repo** (só a seção Back aqui).

```
PR back: <url>

**Implementado:**
- <o que entrou, em bullets curtos>
```

| Repo | Primeira linha |
|---|---|
| `controle_fatura_back` | `PR back: <url>` |
| `controle_fatura_front` | `PR front: <url>` |

Neste repositório use **sempre** `PR back:`. Não invente outro rótulo (`Pull Request`, `PR:`, etc.).

O texto de **Implementado** vem do que realmente foi commitado (`git diff` / commits da branch), não do card inteiro. No back descreva só o Back. Sem o que ficou de fora e sem copiar critérios de aceite.

## Status no Jira

Depois do comentário, mover o card para **Aguardando Merge**:

1. `listJiraIssueTransitions` / `getTransitionsForJiraIssue`
2. Usar o **id** da transição `Aguardando Merge`
3. `transitionJiraIssue`

Não mover para **Merge Feito** — o usuário faz isso após o merge.
