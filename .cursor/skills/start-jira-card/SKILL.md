---
name: start-jira-card
description: Inicia um card CTLFAT — cria branch vX.Y/dev-{tela}-CTLFAT-{n}, move o card para Fazendo. Usar quando o usuário pedir para começar, iniciar ou pegar um card.
---

# Iniciar card Jira (CTLFAT)

## Passos

1. Obter a key (`CTLFAT-9999` ou só o número). Sem key, usar `create-jira-card` antes.
2. `getJiraIssue` — conferir título e status.
3. Resolver versão com `get-project-version`.
4. Definir `tela` em minúsculo, sem hífen (`raiox`, `versionamento`). Inferir do título/contexto; se ambíguo, perguntar.
5. Partir de `main` atualizado e criar:

```bash
git checkout main
git pull
git checkout -b v1.0/dev-raiox-CTLFAT-9999
```

Padrão: `v{major}.{minor}/{ambiente}-{tela}-CTLFAT-{numero}`

6. Transicionar para **Fazendo**: `getTransitionsForJiraIssue` e usar o **id** da transição. Nunca chutar id.
7. Push só se o usuário pedir.

## Variações

- Sem Jira: usuário pode pedir só a branch.
- Sem pull: se `main` local já estiver ok e não houver remoto atualizado.
