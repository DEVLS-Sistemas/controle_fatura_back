---
name: get-project-version
description: Resolve a versão atual do controle de faturas a partir de version.json e da branch Git. Usar ao criar branch, card Jira, bump de versão ou expor a versão na API/front.
---

# Resolução de versão

## Fontes (por prioridade)

1. Branch atual (`git branch --show-current`): padrão `v{major}.{minor}/...` → `version_short` e prefixo `v{major}.{minor}`.
2. `version.json` na raiz (fonte da versão completa):

```json
{
    "name": "controle-fatura-back",
    "version": "1.0.0",
    "version_short": "1.0"
}
```

## Saídas

| Campo | Exemplo | Uso |
|---|---|---|
| `version_full` | `1.0.0` | API (`api_version`), bump, tag |
| `version_short` | `1.0` | título Jira, prefixo de branch |
| `version_branch_prefix` | `v1.0` | `v1.0/dev-{tela}-CTLFAT-{n}` |
| `version_jira` | `v1.0` | Fix Version no Jira quando existir |

## Regras

- Se a branch não tiver o padrão (ex. `main`), derive `version_short` de `version.json`.
- Se branch e arquivo divergirem, prefira `version.json` para `version_full` e avise.
- A API `GET /api/v1` deve ler `App\Support\VersaoSistema`, nunca literal.
