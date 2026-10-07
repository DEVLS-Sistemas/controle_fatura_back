# Prompt — Frontend: Substituir Velzon e Centralizza pela marca correta

Use este prompt no repositório do **frontend**. Card [CTLFAT-21](https://devlssistemas.atlassian.net/browse/CTLFAT-21). Épico [CTLFAT-19](https://devlssistemas.atlassian.net/browse/CTLFAT-19).

Não há API neste card. O back não muda.

| De | Para |
|----|------|
| Velzon (`Velzon`, `velzon`, `VELZON`) | **Devls Sistemas** |
| Centralizza (`Centralizza`, `centralizza`, `CENTRALIZZA`) | **Controle de Faturas** |

Nome fantasia: **Devls Sistemas**. Não usar razão social.

---

## Objetivo

Busca global (case-insensitive) e troca de **todo texto que o usuário vê**:

- título da aba (`title`)
- footer e copyright
- nome no login e na sidebar
- placeholders e mensagens de tela
- manifest / PWA, se o nome aparecer na interface

Onde era Velzon, o usuário lê **Devls Sistemas**. Onde era Centralizza, o usuário lê **Controle de Faturas**.

## Fora

- Renomear pastas, classes CSS ou pacotes npm do template (`velzon` como path interno) se isso quebrar o build. Só o texto visível. Se o path aparecer na tela, aí sim troca.
- Fundo e imagem das telas ([CTLFAT-20](https://devlssistemas.atlassian.net/browse/CTLFAT-20)).

## Como testar

1. No repo do front, buscar `Velzon` e `Centralizza` (qualquer capitalização).
2. Abrir login, home autenticada, título da aba e rodapé.
3. Conferir os dois nomes novos e que nada do template antigo aparece para o usuário.

Critérios:

- [ ] Nenhuma ocorrência visível de Velzon (login, footer, título da aba, sidebar)
- [ ] Nenhuma ocorrência visível de Centralizza
- [ ] Onde era Velzon, o usuário lê Devls Sistemas
- [ ] Onde era Centralizza, o usuário lê Controle de Faturas
