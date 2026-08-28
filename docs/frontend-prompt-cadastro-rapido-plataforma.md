# Prompt — Frontend: Cadastro rápido de Plataforma

Use este prompt no repositório do frontend para o botão **+** de plataforma no formulário de compra / fatura → transações — o mesmo espírito do modal de categoria.

Espelhar: [`frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](frontend-prompt-cadastro-rapido-categoria-subcategoria.md).

---

## API

```http
POST /api/v1/plataformas/cadastrar-rapido
```

```json
{
  "nome": "Rappi",
  "cor": "#ff441f"
}
```

- `nome` — obrigatório (trim)
- `cor` — opcional (HEX tema). Omitida → preto `#000000`
- Match **case-insensitive** por usuário
- Soft-deleted → restaura e reativa
- **Não** retorna 422 por duplicidade — reutiliza

**Resposta:**

```json
{
  "plataforma": {
    "data": {
      "id": 12,
      "user_id": 1,
      "nome": "Rappi",
      "cor": "#ff441f",
      "ativo": true
    },
    "status": true,
    "criado": true,
    "message": "Plataforma cadastrada com sucesso!"
  }
}
```

- `criado: true` → acabou de criar
- `criado: false` → já existia (reutilizada)

Cores: `GET /api/v1/plataformas/lookups` → `temas[]` / `cores[]` / `cor_padrao`. Quadrados: [`frontend-prompt-cores-tema.md`](frontend-prompt-cores-tema.md).

### Vincular na transação

Compra existente:

```http
PUT /api/v1/transacoes/editar
```

```json
{ "id": 123, "plataforma_id": 12 }
```

Parcelada: `propagar_grupo: true` (já marcado se houver `compra_grupo_id`).

Nova compra: só setar o select e enviar `plataforma_id` no `POST /transacoes/cadastrar`.

---

## UX

1. Select de plataforma + botão **“+” / “Nova plataforma”**
2. Modal: input **Nome** + quadrados de cor tema (default preto com anel)
3. Confirmar → `POST /plataformas/cadastrar-rapido` (**nunca** `POST /cadastrar` nesse fluxo)
4. Inserir/selecionar `plataforma.data.id` no select
5. Compra já salva: `PUT /transacoes/editar`
6. Toast com `message` + `criado`

Nome vazio → 422 `"O nome da plataforma é obrigatório"`.

Não redirecionar para `/plataformas`.

---

## Checklist

- [ ] Botão + ao lado do select de plataforma (form compra + fatura)
- [ ] Modal só com nome + quadrados de cor
- [ ] `cadastrar-rapido` (não `cadastrar`)
- [ ] Após sucesso: item selecionado
- [ ] Compra existente persiste na hora
- [ ] Toast distingue criado vs reutilizado
