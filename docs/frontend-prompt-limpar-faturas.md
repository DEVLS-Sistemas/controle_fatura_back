# Prompt — Frontend: Limpar faturas e transações (reset de testes)

Use este prompt no repositório do frontend para adicionar uma ação de **reset** que apaga todas as faturas e transações do usuário logado.

---

## Objetivo

Facilitar testes locais: em vez de excluir fatura por fatura, o usuário pode zerar faturas + compras de uma vez e recomeçar (import PDF, cadastro manual, projeção, etc.).

**Escopo:** só dados do usuário autenticado. **Não** apaga cartões, bandeiras, números, categorias, estabelecimentos nem responsáveis.

---

## API

```http
DELETE /api/v1/faturas/excluir-todas
Authorization: Bearer {token}
Content-Type: application/json

{
  "confirmar": true
}
```

Também aceita query: `DELETE /api/v1/faturas/excluir-todas?confirmar=true`.

### Resposta 200

```json
{
  "fatura": {
    "data": {
      "faturas_excluidas": 12,
      "transacoes_excluidas": 84
    },
    "status": true,
    "message": "Todas as faturas e transações foram excluídas com sucesso!"
  }
}
```

### Erros

| Status | Quando |
|--------|--------|
| 422 | `confirmar` ausente/false → `"Envie confirmar=true para excluir todas as faturas e transações"` |
| 401 | Sem token |

---

## UI sugerida

Colocar em **Faturas** (toolbar / menu “⋯” / área de desenvolvimento), **não** como botão primário da listagem.

1. Botão secundário/destrutivo: **“Limpar faturas”** ou **“Zerar faturas e compras”**
2. Modal de confirmação obrigatório:
   - Título: “Excluir todas as faturas?”
   - Texto: “Isso remove **todas as faturas e transações**. Cartões e cadastros (estabelecimentos, categorias…) permanecem. Esta ação é para testes e não pode ser desfeita pela UI.”
   - Checkbox ou digitar `EXCLUIR` (opcional, recomendado)
   - Botões: Cancelar / **Excluir tudo**
3. Só então chamar `DELETE /excluir-todas` com `{ "confirmar": true }`
4. Loading no botão; toast de sucesso com contagens (`faturas_excluidas` / `transacoes_excluidas`)
5. Invalidar/refetch: listagem de faturas, detalhe, transações, dashboard/projeção

### Visibilidade (recomendado)

- Exibir só em ambiente de desenvolvimento (`import.meta.env.DEV` / flag de config), **ou**
- Sempre disponível, mas escondido em menu secundário (“Ferramentas” / “Testes”)

Não colocar no fluxo principal de uso diário sem confirmação forte.

---

## Checklist

- [ ] Botão “Limpar faturas” fora do fluxo principal
- [ ] Modal de confirmação antes do DELETE
- [ ] Payload com `confirmar: true`
- [ ] Toast com contagens da resposta
- [ ] Refetch de faturas, transações e dashboard após sucesso
- [ ] Cartões/cadastros auxiliares **não** são apagados
`}