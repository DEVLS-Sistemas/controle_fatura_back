---
name: create-jira-card
description: Cria cards no Jira CTLFAT (devlssistemas.atlassian.net) com título back/front vX.Y, Story ou Bug, status inicial A fazer. Usar quando o usuário pedir para abrir, criar ou registrar um card.
---

# Criar card Jira (CTLFAT)

Site: `https://devlssistemas.atlassian.net`  
Projeto: `CTLFAT`  
Board: `https://devlssistemas.atlassian.net/jira/software/projects/CTLFAT/boards/2`

MCP: Atlassian Rovo (`atlassian` / `jira`). Se o namespace estiver `needsAuth`, rode `mcp_auth` e peça o login na conta do site Devls. Na primeira sessão: `getAccessibleAtlassianResources` → `cloudId` → `getVisibleJiraProjects` / tipos / campos. Não invente `cloudId`, status nem campo.

## Título

Resolver versão com `get-project-version`. Escopo pelo contexto: `back`, `front` ou os dois.

```
back v1.0: Arquivo de controle de versão na API
front v1.0: Versão no footer
back v1.0 / front v1.0: Versionamento do sistema
```

## Tipo

- `Story` — padrão
- `Bug` — comportamento incorreto ou pedido explícito

## Atributos

- Sem responsável, salvo se o usuário indicar alguém
- Status inicial: **A fazer**
- Fix Version `v1.0` só se a versão já existir no Jira; senão omitir
- Não inventar estimativa nem campo customizado

## Descrição (markdown)

- **Resumo**
- **Escopo** (incluído / fora)
- **Critérios de aceite** (`- [ ]`)
- **Como testar**

## Fluxo

1. Montar rascunho (tipo, título, descrição) e confirmar com o usuário, salvo se ele já tiver pedido para criar direto.
2. `createJiraIssue` com `projectKey: CTLFAT`, `contentFormat: markdown`.
3. Se não nascer em **A fazer**, `getTransitionsForJiraIssue` e transicionar pelo **id** devolvido.
4. Devolver o link: `https://devlssistemas.atlassian.net/browse/{key}`.

## Board

A fazer → Fazendo → Parado → Review → Aguardando Merge → Merge Feito → Testando → Testado → Aguardando Publicação → Feito

Ao abrir o PR: **Aguardando Merge**. **Merge Feito** só o usuário, depois do merge.
