---
name: create-release-pr
description: Cria o PR de promoção v1.0/dev → main (publicação) com changelog por card CTLFAT e move todos os cards em Aguardando Publicação para Feito. Usar quando o usuário pedir deploy, publicar, promover para main, release ou PR de v1.0/dev para main.
---

# PR de promoção (CTLFAT)

Não usar `create-pr` aqui. Aquele skill é feature → `v1.0/dev`. Este é só `v1.0/dev` → `main`.

## Base

- Head: `v1.0/dev` (nunca uma branch de card)
- Base: `main`
- Nunca desenvolver na `main`

## Preparar

1. Resolver versão com `get-project-version` (`version.json`).
2. Conferir que `v1.0/dev` está limpa e alinhada com `origin/v1.0/dev`.
3. Changelog: `git log --format='%h %s' origin/main..origin/v1.0/dev`. Ignorar commits `Merge pull request`.
4. Agrupar o que entrou por `CTLFAT-XXXX` (commits + `detect-jira-card`). Texto do que foi commitado, não o card inteiro.
5. Jira: `project = CTLFAT AND status = "Aguardando Publicação" ORDER BY key ASC`. Listar keys antes de transicionar.

Se já existir PR aberto `v1.0/dev` → `main`, atualizar o body em vez de abrir outro.

## Criar

Título:

```
release: promove v1.0/dev (X.Y.Z) para main
```

`X.Y.Z` = `version` do `version.json` (ex. `1.0.0`).

Body (HEREDOC, português):

```
## Versão

X.Y.Z (`version_short` X.Y)

## O que entra nesta publicação

### CTLFAT-XXXX — <resumo curto>
- <o que entrou neste repo>

## Cards

- CTLFAT-XXXX — <título curto>

## Test plan

- [ ] GET /api/v1 responde api_version X.Y.Z e version_short X.Y
- [ ] Fluxos principais das mudanças desta publicação
```

```bash
gh pr create --base main --head v1.0/dev --title "..." --body "..."
```

Guardar a URL. Devolver a URL na resposta.

## Jira — concluir publicação

Depois que o PR nasceu, **todos** os cards em **Aguardando Publicação** (não só os do changelog) vão para **Feito** (coluna de concluído do board).

Por card:

1. Comentar (`addOrEditJiraIssueComment`, `contentFormat: markdown`):

```
Release back: <url>

Publicado na versão X.Y.Z.
```

Neste repo use **sempre** `Release back:`. No front, `Release front:`.

2. `getTransitionsForJiraIssue` / `listJiraIssueTransitions` — usar o **id** da transição cujo destino é **Feito**. Não chutar id. Não usar nome "Concluído" (o status do board é **Feito**).
3. `transitionJiraIssue` com esse id.

Se a busca não achar card em Aguardando Publicação, dizer isso e não transicionar outros status.

## Depois do merge

Não executar aqui. Se o usuário pedir *subir para depois*, *depois do merge* ou *tag da release*, usar `subir-depois` (lê `version.json`, igual no front).
