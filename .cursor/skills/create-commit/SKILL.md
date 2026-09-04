---
name: create-commit
description: Gera commit Conventional Commits com escopo CTLFAT-XXXX extraído da branch e descrição em português do Brasil no imperativo. Usar quando o usuário pedir commit.
---

# Commit (CTLFAT)

Só commitar se o usuário pediu. Não dar push.

1. Conferir `git status` e `git diff` / `git log -5`.
2. Detectar card com `detect-jira-card`.
3. Mensagem:

```
<tipo>(<escopo>): <descrição em português no imperativo>
```

Tipos: `feat`, `fix`, `refactor`, `chore`, `docs`, `style`, `test`, `perf`, `ci`, `build`.

- Com `card_key`: escopo `CTLFAT-XXXX`
- Sem card: escopo técnico (`api`, `version`, `docs`, `auth`)

Exemplos:

```
feat(CTLFAT-1): expõe a versão do sistema a partir de version.json
fix(CTLFAT-12): corrige total da fatura no raio-x
```
