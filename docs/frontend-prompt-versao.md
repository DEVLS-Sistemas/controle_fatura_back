# Prompt — Frontend: versão 1.0.15

Use este prompt no repositório `controle_fatura_front`. Só a versão. Sem tela nova.

## Objetivo

Subir `version.json` de **1.0.14** para **1.0.15**. `version_short` continua **1.0**.

```json
{
    "name": "controle-fatura-front",
    "version": "1.0.15",
    "version_short": "1.0"
}
```

O rodapé já lê `api_version` de `GET /api/v1`. Não alterar `Footer`, `VersaoService` nem textos.

## Fora de escopo

- Layout, menu, chamadas de API
- `version_short`, major ou minor

## Como testar

- `version.json` do front está em `1.0.15`.
- Com a API em `1.0.15`, o rodapé mostra `1.0.15`.
