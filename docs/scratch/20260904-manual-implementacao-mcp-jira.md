# Manual de implementação: MCP de conexão com o Jira

Documento portátil para conectar qualquer IDE com suporte a MCP ao **Jira Cloud** (e demais apps Atlassian do mesmo site) usando o servidor oficial **Atlassian Rovo MCP**.

Este guia **não depende de um projeto, empresa ou fluxo de trabalho específico**. Antes de usar em um cliente novo, preencha a seção [Dados do ambiente-alvo](#0-dados-do-ambiente-alvo) com os valores daquela organização.

---

## 0. Dados do ambiente-alvo

Copie este bloco para o projeto destino e substitua os placeholders.

```text
Site Atlassian:          https://<SUA_EMPRESA>.atlassian.net
Chave do projeto Jira:   <CHAVE>          (ex.: PROJ, FIN, OPS)
Tipos de item usados:    Story | Bug | Task | Sub-task | Epic
Colunas / status:        <Backlog, A Fazer, Em Progresso, Em Revisão, Concluído>
Sprint / quadro:         <nome do board>
Regra de título:         <como o time nomeia cards>
Regra de branch:         <como o time nomeia branches a partir da chave>
Assinatura em comentário (opcional): (enviado via assistente da IDE)
```

A IA age **no nome do usuário autenticado** e só consegue o que esse usuário já pode fazer no Jira.

---

## 1. Objetivo

Integrar a IA da IDE ao Jira Cloud para, sem sair do editor:

- ler cards (descrição, comentários, status, anexos, histórico);
- buscar itens com JQL;
- criar e editar cards;
- transicionar status (ex.: *A Fazer* → *Em Progresso*);
- comentar e registrar worklog;
- consultar projetos, boards, sprints, filtros e usuários;
- quando o site tiver os produtos habilitados: Confluence, Jira Service Management, Bitbucket, Compass e Loom.

---

## 2. O que é MCP e qual servidor usar

**MCP (Model Context Protocol)** é o padrão aberto que permite a uma IA chamar ferramentas externas de forma padronizada.

**Servidor recomendado:** [Atlassian Rovo MCP Server](https://github.com/atlassian/atlassian-mcp-server) (oficial, hospedado pela Atlassian).

| Item | Valor atual |
|------|-------------|
| URL oficial (v2) | `https://mcp.atlassian.com/v2/mcp` |
| URL legado (v1) | `https://mcp.atlassian.com/v1/mcp` |
| Autenticação | OAuth 2.1 no navegador (padrão). Token de API é opcional e depende do admin. |
| Instalação local | Não é necessário npm nem pacote local para o caminho oficial. |
| Produtos | Jira, Confluence, JSM, Bitbucket, Compass, Loom (conforme licença do site) |

Prefira **v2**. A Atlassian anunciou que, em **1º de março de 2027**, clientes ainda em v1 passam a expor ferramentas v2 automaticamente. Clientes incompatíveis precisarão limpar `clientIds` ou credenciais `.well-known` em cache.

Não use servidores comunitários de terceiros (`@aashari/...`, forks não oficiais) em ambiente corporativo, a menos que o time de segurança tenha aprovado explicitamente. O servidor oficial não exige gravar e-mail, senha ou token no JSON.

---

## 3. Pré-requisitos

Para cada pessoa que for usar a integração:

1. Conta no **Jira Cloud** do site-alvo, com permissão nos projetos desejados.
2. IDE ou agente com suporte a MCP (Cursor, VS Code + GitHub Copilot, Claude Code, Claude Desktop, Codex, Windsurf, etc.).
3. Navegador moderno para o fluxo OAuth na primeira conexão.
4. **Node.js 18+** apenas se for necessário o fallback `mcp-remote` (clientes antigos que não aceitam URL HTTP).
5. Se a organização restringe MCP: o administrador precisa liberar o Rovo MCP e, se houver allowlist de domínios, incluir o cliente (ex.: Cursor).
6. Créditos **Rovo**: buscas enriquecidas e algumas ferramentas de contexto consomem a cota Rovo da organização. Confirme com o admin se isso está habilitado e qual é o limite.

**Jira Data Center / Server (on-prem):** este servidor remoto oficial é para **Cloud**. Ambiente on-prem exige outro caminho (servidor MCP auto-hospedado apontando para a API interna), fora do escopo deste manual.

---

## 4. Configuração por cliente

Use o nome `atlassian` ou `jira` — o importante é a URL. Se o arquivo de configuração já tiver outros servidores MCP, apenas acrescente o bloco, com vírgula válida no JSON.

### 4.1 Cursor (recomendado)

#### Pelo Marketplace (mais simples)

1. Abra o [plugin Atlassian para Cursor](https://cursor.com/marketplace) e busque **Atlassian**.
2. Clique em **Add to Cursor**.
3. Conclua o login OAuth quando solicitado.

#### Pela interface

1. Configurações do Cursor (`Ctrl + Shift + J` no Linux/Windows, `Cmd + Shift + J` no macOS) → **Tools & MCP**.
2. **Add new MCP server**.
3. Tipo **URL**.
4. **Name:** `atlassian`
5. **URL:** `https://mcp.atlassian.com/v2/mcp`
6. Salve e reinicie o Cursor.

#### Pelo `mcp.json`

Há dois lugares possíveis:

| Escopo | Caminho | Quando usar |
|--------|---------|-------------|
| Global (todas as pastas do usuário) | `~/.cursor/mcp.json` (Windows: `%USERPROFILE%\.cursor\mcp.json`) | Um único site Atlassian para todos os projetos locais |
| Só este repositório | `<raiz-do-projeto>/.cursor/mcp.json` | Projetos de empresas diferentes no mesmo computador |

```json
{
  "mcpServers": {
    "atlassian": {
      "url": "https://mcp.atlassian.com/v2/mcp"
    }
  }
}
```

Não coloque e-mail, senha nem token nesse arquivo no fluxo OAuth.

#### Fallback para Cursor antigo (sem transporte HTTP)

```json
{
  "mcpServers": {
    "atlassian": {
      "command": "npx",
      "args": ["-y", "mcp-remote@latest", "https://mcp.atlassian.com/v2/mcp"]
    }
  }
}
```

Exige Node.js 18+.

### 4.2 VS Code / GitHub Copilot

1. View **Extensions** → busque `@mcp Atlassian`.
2. Instale **Atlassian MCP server**.
3. Autentique quando o VS Code abrir o fluxo OAuth.

### 4.3 Claude Code

```bash
claude mcp add --transport http atlassian https://mcp.atlassian.com/v2/mcp
```

Depois, numa sessão do Claude Code, rode `/mcp` e conclua o login.

### 4.4 Claude Desktop

Pela UI: **Settings → Extensions → Browse extensions → Plugins → Atlassian**.

Ou no arquivo de configuração do Claude Desktop:

```json
{
  "mcpServers": {
    "atlassian": {
      "url": "https://mcp.atlassian.com/v2/mcp"
    }
  }
}
```

### 4.5 Codex

```bash
codex mcp add atlassian --url https://mcp.atlassian.com/v2/mcp
```

No Codex Desktop: **Plugins / Connectors → Atlassian Rovo**.

### 4.6 Windsurf

**Settings → Cascade → MCP servers → Add Server → Add custom server:**

```json
{
  "mcpServers": {
    "atlassian": {
      "serverUrl": "https://mcp.atlassian.com/v2/mcp/"
    }
  }
}
```

### 4.7 Outros clientes MCP

URL:

```text
https://mcp.atlassian.com/v2/mcp
```

Gateways que precisam da lista plana de todas as ferramentas (sem `discover` / `execute`):

```text
https://mcp.atlassian.com/v2/mcp?tools=all
```

### 4.8 Prompt pronto para o agente configurar sozinho

Cole no chat da IDE:

```text
Configure o Atlassian Rovo MCP neste agente usando o guia oficial
https://support.atlassian.com/atlassian-rovo-mcp-server/docs/getting-started-with-the-atlassian-remote-mcp-server/
e a URL https://mcp.atlassian.com/v2/mcp.
Em seguida inicie o fluxo de autenticação Atlassian para eu entrar com a minha conta.
```

---

## 5. Primeira autenticação

1. Reinicie o cliente por completo (não basta recarregar o chat).
2. Peça algo simples: *“liste os sites Atlassian aos quais eu tenho acesso”* ou *“quem sou eu no Jira?”*.
3. O cliente pode exibir a ferramenta `mcp_auth`. Autorize.
4. No navegador, faça login na conta Atlassian **do site-alvo** e clique em **Autorizar**.
5. Volte à IDE. O servidor deve aparecer como conectado (indicador verde no Cursor, em **Tools & MCP**).

OAuth 2.1 é o caminho padrão. Token de API só entra se o administrador da organização tiver habilitado essa opção. Geração: [tokens de API Atlassian](https://id.atlassian.com/manage-profile/security/api-tokens). Escopos e política: documentação *Configure authentication via API token* da Atlassian.

Se o usuário tiver acesso a **vários sites** Cloud, a primeira chamada útil é `getAccessibleAtlassianResources`: ela devolve os `cloudId`. Quase todas as outras ferramentas exigem esse `cloudId`.

---

## 6. Como a IA deve operar em um projeto novo

Sem regras da empresa no repositório, o agente precisa **descobrir** o padrão antes de criar ou mover cards.

### 6.1 Ordem obrigatória na primeira sessão

1. Autenticar (`mcp_auth` se o namespace estiver `needsAuth`).
2. Listar sites (`getAccessibleAtlassianResources`) e escolher o `cloudId` correto.
3. Confirmar o usuário (`atlassianUserInfo` / `getJiraCurrentUser`).
4. Listar projetos visíveis (`listJiraProjects`) e achar a chave `<CHAVE>`.
5. Listar tipos de item e campos obrigatórios (`listJiraProjectIssueTypesMetadata`, `getJiraIssueTypeMetaWithFields`).
6. Listar status e transições reais (`listJiraStatuses`, `listJiraIssueTransitions` num card exemplo).
7. Só então criar, editar ou transicionar.

Nunca invente chave de projeto, status ou campo customizado. Se o time tiver um documento interno de cards, leia-o; senão, pergunte ao usuário.

### 6.2 JQL úteis (genéricos)

Substitua `<CHAVE>`, o e-mail e o nome do sprint.

```text
projeto = <CHAVE> AND sprint in openSprints() AND assignee = currentUser()
projeto = <CHAVE> AND type = Bug AND status != Done ORDER BY priority DESC
projeto = <CHAVE> AND text ~ "login" ORDER BY updated DESC
key = <CHAVE>-123
```

### 6.3 Transições

1. `listJiraIssueTransitions` no card.
2. Use o **id da transição** devolvido — não o nome amigável chutado.
3. Só então `transitionJiraIssue`.

### 6.4 Comentários e worklog

Peça confirmação antes de escrever no Jira. Se o time quiser rastreabilidade, acrescente uma assinatura curta no comentário, por exemplo: `(enviado via assistente da IDE)`.

---

## 7. Ferramentas (visão prática)

O v2 **não envia o catálogo inteiro** de uma vez. Expõe um conjunto *Primary* e o restante via `discover` + `executeRead` / `executeWrite` / `executeDestructive`. Isso reduz contexto e exige confirmação extra em escrita e exclusão.

Grupos `delete_jira` e `manage_jira` vêm **desligados**. Um admin precisa habilitá-los.

### 7.1 Comuns (Primary)

| Ferramenta | Uso |
|------------|-----|
| `atlassianUserInfo` | Conta autenticada |
| `getAccessibleAtlassianResources` | Sites e `cloudId` |
| `discover` | Achar ferramenta que não está no conjunto inicial |
| `executeRead` / `executeWrite` / `executeDestructive` | Executar ferramenta descoberta |

### 7.2 Jira — leitura

`getJiraIssue`, `searchJiraIssuesUsingJql`, `listJiraProjects`, metadados de tipo/campo, transições, worklogs, comentários, changelogs, usuários atribuíveis, boards, sprints, filtros, dashboards, anexos.

### 7.3 Jira — escrita

`createJiraIssue`, `editJiraIssue`, `transitionJiraIssue`, `addOrEditJiraIssueComment`, `addOrEditJiraIssueWorklog`, links entre itens, sprints, versões, anexar arquivo.

### 7.4 Jira — exclusão e gestão (off por padrão)

`deleteJiraIssue`, `deleteJiraComment`, `deleteJiraIssueAttachment`, `createJiraProject`, `updateJiraProject`.

Lista completa e escopos OAuth: [Supported tools](https://support.atlassian.com/atlassian-rovo-mcp-server/docs/supported-tools/).

---

## 8. Exemplos de conversa (neutros)

Use a chave real do projeto no lugar de `PROJ`.

**Começo do dia**

> Quais itens estão atribuídos a mim no sprint ativo do projeto PROJ? Resuma status e prioridade.

**Antes de implementar**

> Leia o PROJ-123. Extraia objetivo, critérios de aceite e restrições. Não altere o card.

**Criar card**

> Crie uma Story no projeto PROJ com o título “Exportar relatório mensal em CSV”, descrição com critérios de aceite em lista, sem responsável, no backlog.

**Comentar**

> Comente no PROJ-123 que o PR foi aberto e peça confirmação do critério X. Mostre o texto antes de enviar.

**Worklog**

> Registre 1h 30min no PROJ-123 com o comentário “implementação do endpoint de exportação”.

**Mover status**

> Liste as transições possíveis do PROJ-123 e, se existir uma para “Em Revisão”, aplique e comente o link do PR.

**Busca**

> Busque bugs abertos do projeto PROJ atualizados nos últimos 7 dias, prioridade alta.

---

## 9. Vários clientes / vários sites no mesmo computador

O MCP Atlassian autentica **uma conta**. O `cloudId` escolhe o site.

| Situação | O que fazer |
|----------|-------------|
| Vários repositórios da **mesma** empresa | `mcp.json` global em `~/.cursor/mcp.json` |
| Repositórios de **empresas diferentes** | `mcp.json` no projeto **ou** reconectar OAuth com a conta certa |
| Mesma pessoa, dois sites Cloud | `getAccessibleAtlassianResources` e passar o `cloudId` certo em cada chamada |
| Precisa trocar de conta | Revogue o app em [id.atlassian.com](https://id.atlassian.com/manage-profile/apps) e rode `mcp_auth` de novo |

Não commite `mcp.json` com segredos. No fluxo oficial OAuth o arquivo só tem a URL — ainda assim, se o time versionar `.cursor/mcp.json`, use só a URL pública, nunca token.

---

## 10. Segurança e governança

- A IA herda as permissões da conta. Sem permissão de apagar projeto, ela não apaga.
- Toda criação, comentário, transição e worklog aparece no Jira como ação **da pessoa autenticada**.
- Peça confirmação humana antes de write/delete.
- Admins: habilite só os grupos necessários (`read_jira`, `write_jira`, `search_jira`). Deixe `delete_jira` e `manage_jira` desligados salvo necessidade.
- Se houver allowlist de MCP no Cursor Enterprise, libere `https://mcp.atlassian.com/v2/mcp`.
- Ferramentas de grafo / busca unificada consomem créditos Rovo da organização.
- Revise logs de auditoria do site Atlassian se houver atividade inesperada.

---

## 11. Validação (checklist)

Depois de configurar, peça à IA e confira o resultado no navegador do Jira:

1. Servidor aparece conectado no cliente.
2. Lista pelo menos um site e um `cloudId`.
3. Lê um card conhecido (`PROJ-123`) e o resumo bate com a tela do Jira.
4. Busca JQL devolve itens do projeto certo.
5. (Opcional, em card de teste) comentário aparece no card.
6. (Opcional, em card de teste) transição vai para o status esperado.
7. (Opcional) worklog aparece na aba de tempo.

Se a leitura funciona e a escrita falha: permissão da conta, grupo `write_jira` desligado ou transição inexistente naquele fluxo.

---

## 12. Problemas comuns

| Sintoma | Causa provável | Ação |
|---------|----------------|------|
| Namespace `needsAuth` / só existe `mcp_auth` | OAuth não concluído ou expirado | Rodar `mcp_auth` e autorizar no navegador |
| Ferramentas não aparecem após salvar o JSON | Cliente não recarregou MCP | Fechar e abrir a IDE |
| Erro de autorização / domínio | Admin restringiu clientes ou o site | Pedir liberação do Rovo MCP e do domínio da IDE |
| Card de outro site / projeto vazio | `cloudId` ou chave errados | Relistar sites e projetos |
| Transição rejeitada | Nome de status inventado | `listJiraIssueTransitions` e usar o id |
| Create issue falha em campo obrigatório | Campo customizado exigido | `getJiraIssueTypeMetaWithFields` |
| `deleteJiraIssue` / `createJiraProject` indisponível | Grupo desligado no admin | Não forçar; pedir ao admin se for realmente necessário |
| Cliente antigo não aceita `"url"` | Sem transporte HTTP | Fallback `mcp-remote` |
| Depois de migrar de v1 para v2 a auth quebra | Cache de `clientId` / `.well-known` | Limpar credenciais MCP do cliente e autenticar de novo |

Logs no Cursor: painel **Output** (`Ctrl + Shift + U`) → **MCP Logs**.

---

## 13. O que adaptar em cada empresa (e o que não copiar)

Este arquivo é o **como conectar**. O **como o time usa o Jira** fica no repositório daquele cliente.

Ao chegar num projeto novo, documente à parte (README interno, skill ou regra da IDE):

- chave do projeto e site;
- tipos de item e campos obrigatórios;
- mapeamento de status;
- padrão de título, labels, componentes, Fix Version;
- se cards nascem no backlog ou numa sprint;
- se a IA pode transicionar sozinha ou só com confirmação;
- texto padrão de comentário / worklog.

Não leve para o cliente novo: chaves de projeto, URLs de site, fluxos, skills ou exemplos de outra empresa.

---

## 14. Referências oficiais

- [Atlassian Rovo MCP Server (GitHub)](https://github.com/atlassian/atlassian-mcp-server)
- [Getting started](https://support.atlassian.com/atlassian-rovo-mcp-server/docs/getting-started-with-the-atlassian-remote-mcp-server/)
- [Supported tools](https://support.atlassian.com/atlassian-rovo-mcp-server/docs/supported-tools/)
- [Authentication — OAuth 2.1](https://support.atlassian.com/atlassian-rovo-mcp-server/docs/configure-oauth-2-1/)
- [Authentication — API token](https://support.atlassian.com/atlassian-rovo-mcp-server/docs/configure-authentication-via-api-token/)
- [Cursor — MCP](https://cursor.com/docs/context/mcp)
- [Tokens de API Atlassian](https://id.atlassian.com/manage-profile/security/api-tokens)
