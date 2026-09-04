---
name: start-jira-card
description: Inicia um card CTLFAT — cria branch vX.Y/dev-{tela}-CTLFAT-{n}, move o card para Fazendo. Usar quando o usuário pedir para começar, iniciar ou pegar um card.
---

# Iniciar card Jira (CTLFAT)

## Passos

1. Obter a key (`CTLFAT-9999` ou só o número). Sem key, usar `create-jira-card` antes.
2. `getJiraIssue` — conferir título, status e as seções **Back** e **Front**.
3. Resolver versão com `get-project-version`.
3b. Neste repo implementar **somente a seção Back**. No front, só a seção **Front**. Sempre começar pelo back; o front dá continuidade depois. Se a seção do repo atual for `Nenhuma alteração neste card.`, não abrir branch.
3c. Na resposta de início, **sempre** dizer se tem Front: sim (resumir o quê) ou não. Sem seção Front / `Nenhuma alteração neste card.` / escopo só back → não tem front.
4. Definir `tela` em minúsculo, sem hífen (`raiox`, `versionamento`). Inferir do título/contexto; se ambíguo, perguntar.
5. Partir de `v1.0/dev` atualizado e criar:

```bash
git checkout v1.0/dev
git pull
git checkout -b v1.0/dev-raiox-CTLFAT-9999
```

Padrão: `v{major}.{minor}/{ambiente}-{tela}-CTLFAT-{numero}`  
PR sempre para `v1.0/dev`. `main` só no deploy.

6. Transicionar para **Fazendo**: `getTransitionsForJiraIssue` e usar o **id** da transição. Nunca chutar id.
7. Push só se o usuário pedir.
8. Fechar a resposta com branch, status e se tem Front para fazer.

## Variações

- Sem Jira: usuário pode pedir só a branch.
- Sem pull: se `v1.0/dev` local já estiver ok e não houver remoto atualizado.
