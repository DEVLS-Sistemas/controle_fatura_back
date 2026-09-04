---
name: detect-jira-card
description: Detecta o card CTLFAT-XXXX da branch atual e dos commits. Usar antes de commit, PR ou transição de status no Jira.
---

# Detectar card Jira (CTLFAT)

## Fontes

### 1. Branch (prioritária)

`git branch --show-current`

| Padrão | Exemplo | Resultado |
|---|---|---|
| `CTLFAT-\d+` | `v1.0/dev-raiox-CTLFAT-9999` | `CTLFAT-9999` |
| `-CTLFAT-` + dígitos no fim | `v1.0/dev-versionamento-CTLFAT-1` | `CTLFAT-1` |

Classificar como **ligado à branch**.

### 2. Commits

`git log v1.0/dev..HEAD --oneline` (ou `HEAD~10` se a base for desconhecida). Mesmos padrões. Só nesta fonte = **ligado aos commits**.

## Saída

| Campo | Descrição |
|---|---|
| `card_key` | Ex. `CTLFAT-9999`, ou `null` |
| `card_source` | `branch` ou `commits` |
| `linked_to_branch` | `true` se veio do nome da branch |
| `all_cards` | Todas as keys, sem duplicata |

Prioridade: branch > commit mais frequente. Empate sem branch → `card_key: null` e listar `all_cards`.

Enriquecer com `getJiraIssue` só se o chamador precisar do título/descrição.
