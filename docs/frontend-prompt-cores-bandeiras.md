# Prompt — Frontend: Cores preset das bandeiras

Use este prompt no repositório do frontend para alinhar o **select de bandeira** (cadastro de cartão, fatura, chips) às cores oficiais Visa / Mastercard / Elo / etc.

Backend já implementado: `GET /api/v1/cartoes/lookups` + auto-apply ao criar bandeira se `cor_principal`/`cor_secundaria` vierem vazios.

---

## Objetivo

1. O select de bandeira lista as bandeiras **com chip de duas cores** (principal + secundária).
2. Ao escolher Visa, Mastercard, Elo… o par de cores é **adotado sozinho**.
3. **Outra** (ou nome desconhecido) usa cinza claro `#e5e7eb` + `#9ca3af`.
4. Chips de bandeira em listagens/faturas usam `cor_principal` / `cor_secundaria` da API — **não** as cores do banco (essas continuam em `cor_fundo` / `cor_texto` do grupo).

---

## API — lookups

```http
GET /api/v1/cartoes/lookups
Authorization: Bearer {token}
```

| Campo | Uso |
|-------|-----|
| `bandeiras` | Labels do select: Visa, Mastercard, Elo, American Express, Hipercard, Diners Club, Discover, JCB, UnionPay, Maestro, Banricompras, Aura, Cabal, Sorocred, **Outra** |
| `presets_bandeiras[]` | Catálogo (`chave`, `label`, `aliases`, `cor_principal`, `cor_secundaria`) |
| `pares_cores_bandeiras[]` | Mesmo catálogo + Outra no final (swatches / opções visuais) |
| `cor_padrao_bandeira` | Fallback cinza (`chave: "outra"`) |

`Amex` legado continua **válido no POST**, mas **não aparece** no select (use `American Express`).

Exemplo:

```json
{
  "bandeiras": ["Visa", "Mastercard", "Elo", "American Express", "Hipercard", "Outra"],
  "cor_padrao_bandeira": {
    "chave": "outra",
    "label": "Outra",
    "cor_principal": "#e5e7eb",
    "cor_secundaria": "#9ca3af",
    "padrao": true
  },
  "pares_cores_bandeiras": [
    { "chave": "visa", "label": "Visa", "cor_principal": "#1a1f71", "cor_secundaria": "#f7b600", "padrao": false },
    { "chave": "mastercard", "label": "Mastercard", "cor_principal": "#eb001b", "cor_secundaria": "#ff5f00", "padrao": false }
  ]
}
```

HEX em minúsculo.

### Catálogo

| Bandeira | Principal | Secundária |
|----------|-----------|------------|
| Visa | `#1a1f71` | `#f7b600` |
| Mastercard | `#eb001b` | `#ff5f00` |
| Elo | `#000000` | `#ffcb05` |
| American Express | `#006fcf` | `#ffffff` |
| Hipercard | `#e31837` | `#ffffff` |
| Diners Club | `#0079be` | `#ffffff` |
| Discover | `#ff6000` | `#000000` |
| JCB | `#00a94f` | `#e31837` |
| UnionPay | `#d50000` | `#004a99` |
| Maestro | `#ed1c24` | `#0099df` |
| Banricompras | `#0054a6` | `#e31b23` |
| Aura | `#0066b3` | `#ffffff` |
| Cabal | `#0066a1` | `#e21e2b` |
| Sorocred | `#0057a8` | `#e30613` |
| Outra | `#e5e7eb` | `#9ca3af` |

---

## Resposta de bandeira (grupo / list / modal)

Todo payload de bandeira agora inclui:

```json
{
  "id": 1,
  "bandeira": "Mastercard",
  "limite_credito": 15000,
  "cor_principal": "#eb001b",
  "cor_secundaria": "#ff5f00",
  "bandeira_chave": "mastercard",
  "bandeira_padrao": false
}
```

`GET /cartoes/bandeiras-list` e o modal de fatura (`criar: true`) também trazem essas cores.

Visualização da compra: `bandeira.cor_principal` / `bandeira.cor_secundaria`.

---

## UX — Cadastro de cartão

### Select de bandeira

Não usar mais um combo só com texto.

Cada opção:

```
[■■]  Mastercard
```

Chip **duas cores** (círculos sobrepostos estilo Mastercard, ou barra 50/50):

- esquerda / círculo de trás = `cor_principal`
- direita / círculo da frente = `cor_secundaria`

Fonte: `pares_cores_bandeiras` (ou `bandeiras[]` + match no preset).

Ao selecionar, gravar no item local:

- `bandeira` = label
- `cor_principal` / `cor_secundaria` do preset (o back também preenche se omitir)

**Outra**: chip cinza.

### Lista “Cartões deste grupo”

No cabeçalho da bandeira, o mesmo chip duas cores + nome + limite.

```
[■■] Mastercard · Limite R$ 15.000,00
```

### Payload

Continua `bandeiras[].bandeira`. Pode enviar `cor_principal` / `cor_secundaria`; se omitir, o back aplica o preset.

Não misturar com as cores do **grupo** (`cor_fundo` / `cor_texto` = banco).

---

## Outras telas

Onde houver chip de bandeira (fatura, ranking, visualização da compra, select de final):

- Usar `cor_principal` + `cor_secundaria` da bandeira
- Fallback: achar o label em `pares_cores_bandeiras`; senão `cor_padrao_bandeira`

---

## Critérios de aceite

- [ ] Select de bandeira mostra as 14 oficiais + Outra, cada uma com chip duas cores
- [ ] Escolher Mastercard / Visa / Elo / Amex (American Express) aplica o par oficial
- [ ] Outra = cinza `#e5e7eb` / `#9ca3af`
- [ ] Lista do grupo e modal de fatura usam o mesmo chip
- [ ] Cores da bandeira ≠ cores do banco (Nubank roxo continua no grupo)
- [ ] `Amex` antigo no banco continua funcionando; no select novo use American Express

---

## Fora de escopo

- Recolorir bandeiras já salvas em massa (leitura já resolve pelo nome se a coluna estiver vazia)
- Color picker livre por bandeira (só se já existir)

---

## Backend

`App\Services\Cartao\BandeiraCoresPreset`  
Lookups: `GET /api/v1/cartoes/lookups`  
Create da bandeira aplica preset se cores vazias.

Relacionado: [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md) (cores do **banco**) · [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md)
