# Prompt — Frontend: Cores preset dos cartões

Use este prompt no repositório do frontend para alinhar o **cadastro de cartão** ao preset de cores oficiais dos bancos/cartões mais comuns.

Backend já implementado: `GET /api/v1/cartoes/lookups` + auto-apply no `POST /cadastrar` se `cor_fundo`/`cor_texto` vierem vazios.

---

## Objetivo

1. O seletor de cores do cadastro mostra as **cores oficiais** (Nubank, Inter, C6, Itaú, …) — não a paleta genérica antiga.
2. Ao digitar um nome/banco conhecido, o par fundo+texto é **aplicado sozinho**.
3. Cartão que **não** está na lista usa **cinza claro** (`#e5e7eb` + texto `#111827`).
4. O usuário ainda pode trocar a cor manualmente (override).

---

## API — lookups

```http
GET /api/v1/cartoes/lookups
Authorization: Bearer {token}
```

Campos novos / atualizados:

| Campo | Uso |
|-------|-----|
| `cor_padrao` | Fallback cinza claro (`chave: "padrao"`, `padrao: true`) |
| `presets_cores[]` | Catálogo para match no front (`chave`, `label`, `aliases`, `cor_fundo`, `cor_texto`) |
| `pares_cores[]` | Swatches do formulário: **Padrão primeiro**, depois um chip por banco |
| `cor_personalizada` | Título da seção “Cor personalizada” — **dois** seletores (Fundo e Texto), não um chip só. Ver [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md) item 1 |
| `cores_fundo[]` / `cores_texto[]` | HEX únicos (se ainda houver picker separado) |

Exemplo:

```json
{
  "cor_padrao": {
    "chave": "padrao",
    "label": "Padrão",
    "cor_fundo": "#e5e7eb",
    "cor_texto": "#111827",
    "padrao": true
  },
  "pares_cores": [
    { "chave": "padrao", "label": "Padrão", "cor_fundo": "#e5e7eb", "cor_texto": "#111827", "padrao": true },
    { "chave": "nubank", "label": "Nubank", "cor_fundo": "#820ad1", "cor_texto": "#ffffff", "padrao": false },
    { "chave": "inter", "label": "Inter", "cor_fundo": "#ff7a00", "cor_texto": "#ffffff", "padrao": false }
  ],
  "presets_cores": [
    {
      "chave": "nubank",
      "label": "Nubank",
      "aliases": ["nubank", "nu bank", "nu pagamentos", "roxinho"],
      "cor_fundo": "#820ad1",
      "cor_texto": "#ffffff"
    }
  ]
}
```

HEX sempre em minúsculo (`#820ad1`).

### Catálogo (label → fundo / texto)

Nubank `#820ad1`/`#ffffff` · Inter `#ff7a00`/`#ffffff` · C6 Bank `#111111`/`#ffffff` · Sofisa `#008f5a`/`#ffffff` · Itaú `#003b70`/`#ffffff` · Santander `#ec0000`/`#ffffff` · Bradesco `#cc092f`/`#ffffff` · Banco do Brasil `#f8d117`/`#003da5` · Caixa `#005ca9`/`#ffffff` · PicPay `#21c25e`/`#000000` · Mercado Pago `#009ee3`/`#ffffff` · Neon `#00e676`/`#000000` · BTG `#001e62`/`#ffffff` · XP `#111111`/`#ffffff` · PagBank `#ffb800`/`#000000` · PAN `#00aeef`/`#ffffff` · Will Bank `#6c2bd9`/`#ffffff` · Original `#00a859`/`#ffffff` · Next `#00a859`/`#ffffff` · Amazon Card `#146eb4`/`#ffffff` · Sam's Club `#0067a0`/`#ffffff` · Pão de Açúcar `#00843d`/`#ffffff` · Carrefour `#004b93`/`#ffffff` · Magalu `#0086ff`/`#ffffff` · Renner `#d71920`/`#ffffff` · Riachuelo `#e30613`/`#ffffff` · Americanas `#e60012`/`#ffffff` · Shopee `#ee4d2d`/`#ffffff` · **Padrão** `#e5e7eb`/`#111827`

C6 e XP compartilham o preto; Original e Next compartilham o verde — **dois chips**, labels diferentes.

**PDF homologado ≠ ter cor.** Nubank/Inter/C6/Sofisa/PicPay/Itaú Click têm `importacao_pdf_homologada: true` no chip; Santander/Bradesco etc. têm cor e `false`. UI: [`frontend-prompt-fatura-parser-homologado.md`](frontend-prompt-fatura-parser-homologado.md).

---

## UX — Formulário de cartão (create / edit)

### Estado inicial (create)

- `cor_fundo` / `cor_texto` = `lookups.cor_padrao`
- Chip de preview com esse cinza
- Flag local `coresManuais = false`

### Swatches (`pares_cores`)

Grade de chips (não lista de HEX soltos):

- Cada item: retângulo/círculo com `background: cor_fundo`, `color: cor_texto`, texto = `label` (ou só a bolinha + tooltip com o label)
- Primeiro item: **Padrão** (cinza claro)
- Em seguida os bancos, na ordem da API
- Selecionado: anel/borda no chip cujo par bate com o form
- Ao clicar: preenche `cor_fundo` + `cor_texto` e marca `coresManuais = true`

Não voltar à paleta antiga (Roxo/Verde/Âmbar genéricos).

### Auto-aplicar ao digitar nome ou banco

Quando `nome` ou `banco` mudar **e** `coresManuais === false`:

1. Normalizar o texto (minúsculo, sem acento) — ou simplesmente:
2. Procurar em `presets_cores` o item cujo `aliases` (ou `label`) aparece no nome **ou** no banco (preferir o alias **mais longo**)
3. Achou → aplicar `cor_fundo`/`cor_texto` daquele preset e destacar o chip
4. Não achou → aplicar `cor_padrao`

Exemplos:

| Digitação | Resultado |
|-----------|-----------|
| `Nubank` / `Nubank Principal` | roxo `#820ad1` |
| `Itaú` | azul `#003b70` |
| `Magazine Luiza` | Magalu `#0086ff` |
| `Cartão da empresa` | cinza `#e5e7eb` |

Se o usuário **já escolheu um swatch** (`coresManuais`), **não** sobrescrever ao continuar digitando. Botão opcional “Usar cor do banco” reseta a flag e reaplica.

No **edit**: iniciar `coresManuais = true` (não trocar a cor salva só porque o nome já casa com um preset). Se o usuário limpar e quiser o auto, aí sim.

### Preview

Chip ao lado do seletor, estilo cartão:

```
background: cor_fundo
color: cor_texto
texto: nome do cartão (ou “Novo cartão”)
```

### Payload

Continua enviando `cor_fundo` e `cor_texto`. Se o front omitir no create, o backend aplica o mesmo preset — mas o front **deve enviar** o par visível no preview.

---

## Match no front (opcional; backend já faz no create)

Se quiser preview instantâneo igual ao back:

- lowercase, remover acentos (`Itaú` → `itau`)
- alias curto (≤ 3: `c6`, `bb`, `xp`, `pan`) só com palavra inteira
- alias maior: contém a string (`nubank` em `nubank principal`)
- empate: alias mais longo vence (`will bank` > `will`)

Não precisa reimplementar se só consumir `presets_cores` com `includes` no nome+banco já cobre 95%.

---

## Critérios de aceite

- [ ] Swatches = `pares_cores` da API (Padrão + bancos), não a paleta genérica antiga
- [ ] Digitar Nubank / Inter / Itaú / Sofisa / C6 etc. aplica o par oficial **enquanto o usuário não escolheu cor na mão**
- [ ] Nome desconhecido → cinza claro `#e5e7eb` / `#111827`
- [ ] Clique no chip é override e para o auto-apply
- [ ] Preview do chip usa fundo + texto juntos
- [ ] Create envia o par visível; edit não troca cor sozinho
- [ ] C6 e XP (mesmo preto) e Original/Next (mesmo verde) aparecem como chips distintos

---

## Fora de escopo

- Recolorir cartões já cadastrados automaticamente
- Cor por bandeira/número (a cor é do **grupo**)
- Color picker livre (hex custom) — **dois** blocos (fundo e texto): [`frontend-prompt-ajustes-ux-cores-periodo.md`](frontend-prompt-ajustes-ux-cores-periodo.md) item 1. **Não** remover os presets desta spec.

---

## Backend

`App\Services\Cartao\CartaoCoresPreset`  
Lookups: `GET /api/v1/cartoes/lookups`  
Create aplica preset se cores vierem vazias.

Docs: [`docs/modules/cartoes.md`](modules/cartoes.md) · formulário geral: [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md)
