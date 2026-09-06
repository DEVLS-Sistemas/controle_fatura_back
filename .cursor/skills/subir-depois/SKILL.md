---
name: subir-depois
description: Depois do merge do PR de promoção, lê version.json e publica a tag vX.Y.Z na main. Usar quando o usuário pedir subir para depois, depois do merge, tag da release, publicar a tag ou executar o depois.
---

# Subir para depois (CTLFAT)

O passo **depois** do `create-release-pr`. Não abre PR e não faz merge. Só tag na `main` a partir de `version.json`.

O mesmo arquivo existe no back e no front, na raiz:

```json
{
    "name": "controle-fatura-back",
    "version": "1.0.0",
    "version_short": "1.0"
}
```

No front o `name` é `controle-fatura-front`. Os campos `version` e `version_short` são a fonte da tag nos dois repos.

## Quando executar

O usuário pediu explicitamente: *subir para depois*, *depois do merge*, *tag da release* ou equivalente.

## Passos

1. Ler `version.json` na raiz deste repo. Sem o arquivo, parar.
2. Resolver com `get-project-version`. Tag = `v{version}` (ex. `1.0.0` → `v1.0.0`).
3. `name` com `-back` → rótulo `Release back`. Com `-front` → `Release front`.
4. `git fetch origin --tags`.
5. Conferir que o PR `v1.0/dev` → `main` **já foi mergeado**:

```bash
gh pr list --base main --head v1.0/dev --state merged --limit 1
```

Se ainda estiver aberto (ou não existir merge), **parar**. Dizer que o depois só roda depois do merge. Não mergear.

6. `origin/main` precisa ter o mesmo `version.json` (mesmo `version`). Se a `main` ainda não tiver o arquivo ou a versão divergir, **parar**.
7. Working tree limpa. Senão, parar.

```bash
git checkout main
git pull origin main
```

8. Tag:

- Já existe `vX.Y.Z` **e** aponta para o `HEAD` da `main` → já publicado; não refazer.
- Já existe e aponta para **outro** commit → **parar**. Não mover tag (`-f` / `--force`) sem o usuário pedir.
- Não existe:

```bash
git tag -a vX.Y.Z -m "release X.Y.Z"
git push origin vX.Y.Z
```

## Não fazer

- Merge do PR de promoção
- Push da `main`
- `git tag -f` / `git push --force`
- Pular hooks

## Resposta

Devolver: repo (`name`), versão lida, tag, commit da `main` e se a tag foi criada, já existia ou foi bloqueada.
