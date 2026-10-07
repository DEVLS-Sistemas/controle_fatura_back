# Replicar as skills de Jira neste projeto

Cole este arquivo inteiro no chat do **outro** repositório. O agente deve gravar as skills e as regras **aqui** (no projeto aberto), apontando para o Jira deste projeto. Não altere o repositório de origem.

Fonte do contrato (só leitura, se o caminho existir na máquina):

`/home/leonardosilva/Projetos/Léo/controle_fatura_back/.cursor/skills/`

Regras de origem para adaptar, não copiar cegamente:

- `.cursor/rules/versionamento.mdc`
- `.cursor/rules/prompt-front-no-card.mdc`

Não copie `.cursor/rules/anexos-sem-arquivo-no-banco.mdc` nem nada de fatura, anexo, auth ou `VersaoSistema`. Isso é domínio do controle de faturas.

## Antes de gravar qualquer arquivo

Se o usuário não tiver preenchido o bloco abaixo, pergunte **numa mensagem só** e pare. Não invente project key, site, board, repos nem branch.

```
SITE_JIRA=
PROJECT_KEY=
BOARD_URL=
REPO_DESTE=          # nome do repositório git aberto agora
PAPEL=               # back | front | unico
REPO_PAR=            # o outro lado, se PAPEL for back ou front; vazio se unico
BRANCH_INTEGRACAO=   # ex. v1.0/dev
BRANCH_PRODUCAO=     # ex. main
NOME_FANTASIA=       # rodapé / comentários; vazio se não houver
```

Confira no Jira de verdade (MCP Atlassian). Na primeira chamada: `getAccessibleAtlassianResources` → `cloudId` do `SITE_JIRA`. Não reuse cloudId de outro site. Não invente status, tipo de issue nem campo.

- O `PROJECT_KEY` tem que existir nesse site.
- Liste os tipos de issue. Use o **nome que existe** (`História` ou `Story`, `Bug`). Não force o nome em inglês se o projeto estiver em português.
- Liste os status do board. O fluxo esperado é:

  A fazer → Fazendo → Parado → Review → Aguardando Merge → Merge Feito → Testando → Testado → Aguardando Publicação → Feito

  Se algum nome não existir, pare e mostre os status reais. Não crie transição chutando id.
- Fix Version `vX.Y` só entra no card se a versão já existir no projeto. Sem versões no Jira, omita.
- Board: use o `BOARD_URL` informado. Se estiver vazio, descubra o board do projeto e grave a URL encontrada.

## O que manter igual

- Um card só, o mesmo número nos dois repos quando houver par back/front.
- Título: `back vX.Y / front vX.Y: …` quando os dois tiverem trabalho. Se um lado não tiver, o corpo diz `Nenhuma alteração neste card.`
- Descrição em markdown com **Resumo**, **Back** e **Front** separados. Não misture API e tela no mesmo parágrafo.
- Sem responsável, salvo se o usuário indicar. Sem estimativa inventada. Sem campo customizado inventado.
- Status inicial ao criar: **A fazer**. Se o card nascer em outro status, transicione pelo **id** devolvido.
- Branch: `v{major}.{minor}/{ambiente}-{tela}-{PROJECT_KEY}-{numero}` a partir de `BRANCH_INTEGRACAO`. `tela` em minúsculo, sem hífen. Ambiente de desenvolvimento: `dev`.
- Fonte da versão: `version.json` na raiz (`version`, `version_short`). Não hardcodar versão. Se o projeto **não** tiver `version.json`, pergunte antes de criar.
- `BRANCH_PRODUCAO` é só deploy. Feature sai de `BRANCH_INTEGRACAO` e o PR volta para ela. Promoção é `BRANCH_INTEGRACAO` → `BRANCH_PRODUCAO`.
- Commit em português do Brasil, Conventional Commits, escopo = key do card (`PROJ-12`), imperativo.
- PR de feature: comentar no card e mover para **Aguardando Merge**. **Merge Feito** só o usuário, depois do merge. Não transicionar para Merge Feito.
- PR de promoção: comentar a release e mover **todos** os cards em **Aguardando Publicação** para **Feito**.
- Tag `vX.Y.Z` só depois do merge, lendo `version.json`. Não mergear PR. Não forçar tag.
- Comentários com rótulo fixo na primeira linha. Não invente outro rótulo.

| Situação | Primeira linha neste repo |
|---|---|
| PAPEL = back | `PR back: <url>` / `Release back: <url>` / `Prompt front: <caminho>` |
| PAPEL = front | `PR front: <url>` / `Release front: <url>` |
| PAPEL = unico | `PR: <url>` / `Release: <url>` — e **não** crie skill de prompt de front |

## O que trocar em todo arquivo

Substitua o que for do controle de faturas pelo bloco preenchido:

| Origem | Destino |
|---|---|
| `CTLFAT` | `PROJECT_KEY` |
| `https://devlssistemas.atlassian.net` | `SITE_JIRA` |
| board `.../CTLFAT/boards/2` | `BOARD_URL` |
| `controle_fatura_back` / `controle_fatura_front` | `REPO_DESTE` / `REPO_PAR` |
| `v1.0/dev` | `BRANCH_INTEGRACAO` |
| `main` (quando for a branch de deploy) | `BRANCH_PRODUCAO` |
| `Devls Sistemas` | `NOME_FANTASIA` (omita o rodapé se estiver vazio) |
| menção a `GET /api/v1`, `VersaoSistema`, faturas | o equivalente deste projeto, ou corte se não existir |

O link do card fica `{SITE_JIRA}/browse/{key}`.

## Skills para gravar

Copie cada `SKILL.md` da origem e adapte. Se a origem não estiver no disco, recrie com o contrato abaixo. Frontmatter `name` e `description` no mesmo formato, com `PROJECT_KEY` no lugar de CTLFAT.

1. `get-project-version` — versão por `version.json` e pelo prefixo `v{major}.{minor}/` da branch. Saídas: `version_full`, `version_short`, `version_branch_prefix`, `version_jira`. Branch sem padrão (ex. a de produção): derive de `version.json`. Se branch e arquivo divergirem, prefira `version.json` para `version_full` e avise.
2. `create-jira-card` — criar card direto se o usuário já pediu para criar. Senão, rascunho e confirmação. `createJiraIssue` com `projectKey` deste projeto e `contentFormat: markdown`. Tipo padrão: o de história que existir; `Bug` quando for comportamento incorreto. Devolver o link. Se houver prompt de front, a descrição aponta o arquivo, mas o comentário `Prompt front:` é outra skill.
3. `start-jira-card` — key do card ou só o número. Sem key, usar `create-jira-card` antes. Ler o card. Branch a partir de `BRANCH_INTEGRACAO` atualizada. Mover para **Fazendo** pelo id da transição. Push só se o usuário pedir. Na resposta: branch, status e se tem Front (sim e o quê, ou não). Sem seção Front, ou `Nenhuma alteração neste card.`, não tem front. Se o lado deste repo for `Nenhuma alteração neste card.` e não houver arquivo para gravar, não abrir branch.
4. `detect-jira-card` — key na branch (prioridade) e depois nos commits `BRANCH_INTEGRACAO..HEAD`. Saída: `card_key`, `card_source`, `linked_to_branch`, `all_cards`. Empate sem branch → `card_key: null`.
5. `create-commit` — só se o usuário pedir. Não dar push. Escopo = key do card. Sem card, escopo técnico. Se pediu commit e PR juntos, seguir `create-pr` depois.
6. `create-pr` — feature → `BRANCH_INTEGRACAO`, nunca para a branch de produção. `gh pr create`. Comentar no card com o rótulo do PAPEL e `**Implementado:**` só do que foi commitado neste repo. Mover para **Aguardando Merge**. Deploy, promover ou release → `create-release-pr`.
7. `create-release-pr` — só `BRANCH_INTEGRACAO` → `BRANCH_PRODUCAO`. Changelog por key a partir dos commits, não do card inteiro. Título `release: promove {BRANCH_INTEGRACAO} (X.Y.Z) para {BRANCH_PRODUCAO}`. Comentar `Release back:` / `Release front:` / `Release:` conforme o PAPEL. Mover todos em **Aguardando Publicação** para **Feito**. Não mergear. Tag é `subir-deploy`.
8. `subir-deploy` — só quando o usuário pedir deploy ou tag. Ler `version.json`. Tag `v{version}` anotada na branch de produção, depois do PR de promoção mergeado e com o mesmo `version` no remoto. Não mover tag existente. Não fazer merge. Não forçar push.
9. `comentar-prompt-front` — **somente se PAPEL = back** e existir repo de front. Ao criar ou atualizar `docs/frontend-prompt-*.md`, comentar na hora, sem esperar commit. Primeira linha exata: `Prompt front: docs/frontend-prompt-….md`. Se o mesmo caminho já estiver comentado, editar o comentário. Sem Front no card, não comentar. PAPEL = front ou unico: não crie esta skill.

## Regras alwaysApply

- `versionamento.mdc` — o mesmo fluxo (branches, board, skills, rótulos de PR/release). Troque projeto, site, repos, branches e nome fantasia.
- `prompt-front-no-card.mdc` — só se PAPEL = back. Mesmo texto da skill `comentar-prompt-front`, em regra curta.
- Commit message gerada pelo Cursor: português do Brasil, assunto curto (~72 caracteres), foco no porquê. Pode ir em `.cursorrules` se este repo ainda não tiver essa regra. Não apague regras que já existam; só acrescente.

## Fechamento

Liste os arquivos criados, o `PROJECT_KEY`, o site e se este repo é back, front ou único. Diga se tem par de front. Não abra card, branch, commit nem PR nesta tarefa.
