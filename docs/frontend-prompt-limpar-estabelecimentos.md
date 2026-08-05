# Prompt — Frontend: Limpar estabelecimentos, categorias e subcategorias (reset de testes)

Use este prompt no repositório do frontend para adicionar uma ação de **reset** que apaga todos os estabelecimentos, categorias e subcategorias do usuário logado.

---

## Objetivo

Facilitar testes locais: zerar o cadastro de estabelecimentos e a taxonomia (categorias/subcategorias) de uma vez, depois recomeçar (import PDF, cadastro rápido, padrões, etc.).

**Escopo:** só dados do usuário autenticado. Soft-delete de estabelecimentos + categorias + subcategorias (+ vínculos N:N). **Não** apaga faturas, transações, cartões nem responsáveis.

**Pré-requisito:** não pode haver transações ativas. Se ainda existirem, o backend retorna 422 pedindo para limpar faturas/transações antes (`DELETE /api/v1/faturas/excluir-todas`).

---

## API

```http
DELETE /api/v1/estabelecimentos/excluir-todos
Authorization: Bearer {token}
Content-Type: application/json

{
  "confirmar": true
}
```

Também aceita query: `DELETE /api/v1/estabelecimentos/excluir-todos?confirmar=true`.

### Resposta 200

```json
{
  "estabelecimento": {
    "data": {
      "estabelecimentos_excluidos": 40,
      "categorias_excluidas": 12,
      "subcategorias_excluidas": 18
    },
    "status": true,
    "message": "Todos os estabelecimentos, categorias e subcategorias foram excluídos com sucesso!"
  }
}
```

### Erros

| Status | Quando |
|--------|--------|
| 422 | `confirmar` ausente/false → `"Envie confirmar=true para excluir todos os estabelecimentos, categorias e subcategorias"` |
| 422 | Ainda há transações → `"Exclua as faturas e transações antes de limpar estabelecimentos e categorias"` |
| 401 | Sem token |

---

## UI sugerida

Colocar em **Estabelecimentos** (ou em Categorias / tela de ferramentas), **não** como botão primário da listagem.

1. Botão secundário/destrutivo: **“Limpar estabelecimentos”** ou **“Zerar estabelecimentos e categorias”**
2. Modal de confirmação obrigatório:
   - Título: “Excluir todos os estabelecimentos?”
   - Texto: “Isso remove **todos os estabelecimentos, categorias e subcategorias**. Faturas/transações e cartões permanecem. Se ainda houver compras, limpe as faturas antes. Esta ação é para testes e não pode ser desfeita pela UI.”
   - Checkbox ou digitar `EXCLUIR` (opcional, recomendado)
   - Botões: Cancelar / **Excluir tudo**
3. Só então chamar `DELETE /excluir-todos` com `{ "confirmar": true }`
4. Loading no botão; toast de sucesso com contagens
5. Invalidar/refetch: estabelecimentos, categorias, subcategorias, lookups de compra/fatura

### Ordem recomendada no fluxo de reset

1. Limpar faturas/transações (`/faturas/excluir-todas`)
2. Limpar estabelecimentos/categorias (`/estabelecimentos/excluir-todos`)

### Visibilidade (recomendado)

- Exibir só em ambiente de desenvolvimento (`import.meta.env.DEV` / flag de config), **ou**
- Sempre disponível, mas escondido em menu secundário (“Ferramentas” / “Testes”)

Não colocar no fluxo principal de uso diário sem confirmação forte.

---

## Checklist

- [ ] Botão “Limpar estabelecimentos” fora do fluxo principal
- [ ] Modal de confirmação antes do DELETE
- [ ] Payload com `confirmar: true`
- [ ] Toast com contagens da resposta
- [ ] Tratar 422 de “ainda há transações” (sugerir limpar faturas antes)
- [ ] Refetch de estabelecimentos, categorias, subcategorias e lookups após sucesso
- [ ] Faturas/cartões **não** são apagados
