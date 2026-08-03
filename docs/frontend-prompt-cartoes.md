# Prompt — Frontend: Cartões (grupo → bandeira → número)

Use este prompt no repositório do frontend para alinhar a UI de cartões à nova hierarquia da API do `controle_fatura_back`.

---

## Contexto / breaking change

O cadastro deixa de ser “1 cartão = 1 bandeira + 1 final”.

Agora:

```
Cartão (grupo)     → Sofisa / Nubank (nome, banco, ciclo, cores)
  └── Bandeira     → Visa / Mastercard (limite de crédito único)
        └── Número → final 1234, 5678… (físico, virtual, adicional)
```

| Conceito | Onde vive | Observação |
|----------|-----------|------------|
| Nome, banco, fechamento, vencimento, cores | Grupo (`cartoes`) | Topo do formulário |
| Limite de crédito | **Bandeira** | Não é mais do grupo nem do número |
| Final do cartão | **Número** | Vários por bandeira |
| Fatura | Ligada à **bandeira** | Visa e Master do mesmo banco = faturas separadas |
| Transação na fatura | Pode ter **número** | View da fatura agrupa por final |

Campos antigos removidos do payload raiz: `bandeira`, `ultimos_digitos`, `limite_credito` (agora dentro de `bandeiras[]` / `numeros[]`).

---

## API

Base: `/api/v1/cartoes` (Bearer Sanctum)

CRUD padrão no grupo: `lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `cartoes-list`.

Extras:

```http
GET /api/v1/cartoes/bandeiras-list?cartao_id={id}
GET /api/v1/cartoes/numeros-list?cartao_bandeira_id={id}
```

### Lookups (`GET /lookups`)

```json
{
  "bandeiras": ["Visa", "Mastercard", "Elo", "Amex", "Hipercard", "Outra"],
  "tipos_numero": [
    { "value": "fisico", "label": "Físico" },
    { "value": "virtual", "label": "Virtual" },
    { "value": "adicional", "label": "Adicional" }
  ],
  "cores_fundo": ["#ef4444", "#f59e0b", "#22c55e", "#3b82f6", "#8b5cf6"],
  "cores_texto": ["#ffffff", "#0f172a", "#111827", "#f8fafc"],
  "pares_cores": [
    { "cor_fundo": "#8b5cf6", "cor_texto": "#ffffff", "label": "Roxo" }
  ],
  "dias": [{ "value": 1, "label": "01" }]
}
```

### Payload create/edit

```json
{
  "nome": "Sofisa",
  "banco": "Sofisa",
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12,
  "cor_fundo": "#8b5cf6",
  "cor_texto": "#ffffff",
  "ativo": true,
  "bandeiras": [
    {
      "id": 1,
      "bandeira": "Mastercard",
      "limite_credito": "15.000,00",
      "ativo": true,
      "numeros": [
        {
          "id": 10,
          "ultimos_digitos": "1234",
          "tipo": "fisico",
          "apelido": null,
          "ativo": true
        },
        {
          "ultimos_digitos": "5678",
          "tipo": "virtual",
          "apelido": "Viagem",
          "ativo": true
        }
      ]
    },
    {
      "bandeira": "Visa",
      "limite_credito": "8.000,00",
      "ativo": true,
      "numeros": [
        { "ultimos_digitos": "9999", "tipo": "fisico", "ativo": true }
      ]
    }
  ],
  "bandeiras_remover": [],
  "numeros_remover": []
}
```

Obrigatoriedade no create: `nome`, `dia_limite_fatura`, `dia_vencimento_fatura`.  
`limite_credito` (por bandeira) é opcional; se enviado, > 0. Aceita número ou string BR.

**Sincronização no edit**

- Item com `id` → atualiza
- Item sem `id` → cria
- Remoção explícita via `bandeiras_remover` / `numeros_remover` (não apagar só por omissão)

### Resposta de detalhe / listagem (formato esperado)

```json
{
  "id": 1,
  "nome": "Sofisa",
  "banco": "Sofisa",
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12,
  "cor_fundo": "#8b5cf6",
  "cor_texto": "#ffffff",
  "ativo": true,
  "qtd_bandeiras": 2,
  "qtd_numeros": 3,
  "bandeiras": [
    {
      "id": 1,
      "bandeira": "Mastercard",
      "limite_credito": 15000,
      "ativo": true,
      "numeros": [
        { "id": 10, "ultimos_digitos": "1234", "tipo": "fisico", "apelido": null, "ativo": true },
        { "id": 11, "ultimos_digitos": "5678", "tipo": "virtual", "apelido": "Viagem", "ativo": true }
      ]
    }
  ]
}
```

---

## UI — Formulário de cartão

### Topo (grupo — o que já existe, ajustado)

Campos do **grupo**:

- Nome (ex.: Sofisa)
- Banco
- Dia limite da fatura (1–31) + ajuda: “Compras até este dia entram na fatura do mês”
- Dia de vencimento (1–31) + ajuda: “Data limite para pagamento”
- Par de cores (`pares_cores` ou swatches manuais) + preview do chip
- Ativo

**Remover** do topo: bandeira, últimos dígitos, limite de crédito (esses sobem/descem para a seção de baixo).

### Base — adicionar números / bandeiras

Seção **“Cartões deste grupo”** (ou “Números / bandeiras”):

1. Linha de inclusão:
   - Select **Bandeira** (`lookups.bandeiras`)
   - Input **Final** (4 dígitos, máscara `•••• 1234` / só 4 chars)
   - Select **Tipo** (físico / virtual / adicional) — opcional
   - Input **Apelido** — opcional
   - Input **Limite da bandeira** — ver regra abaixo
   - Botão **Adicionar cartão**
2. Ao adicionar, o item entra na lista abaixo (estado local → enviado no save).
3. Lista agrupada por bandeira:

```
Mastercard · Limite R$ 15.000,00                    [editar limite]
  •••• 1234  Físico                         [ativar/desativar] [remover]
  •••• 5678  Virtual · Viagem               [ativar/desativar] [remover]
Visa · Limite R$ 8.000,00
  •••• 9999  Físico                         [ativar/desativar] [remover]
```

### Regra do limite na inclusão

- Se a bandeira **já existe** na lista local → não pedir limite de novo; o número entra nela. Limite editável no cabeçalho do grupo da bandeira.
- Se a bandeira é **nova** → mostrar campo limite (opcional) na linha de inclusão; ao adicionar, cria a bandeira + o primeiro número.
- Limite é **um só por bandeira** — nunca por linha de número.

### Validações de UI

- Final: exatamente 4 dígitos numéricos
- Não duplicar o mesmo `ultimos_digitos` dentro da mesma bandeira
- Remover bandeira só se não houver números (ou remover números primeiro / confirmar remoção em cascata na UI e enviar ids em `bandeiras_remover` / `numeros_remover`)
- Confirmar remoção: “Números/bandeiras com faturas vinculadas podem ser só desativados” (se a API bloquear delete)

---

## UI — Listagem de cartões

Para cada grupo:

- Chip com `cor_fundo` / `cor_texto` + nome
- “Fecha dia X · Vence dia Y”
- Resumo: `2 bandeiras · 3 cartões`
- Limites: “Master R$ 15 mil · Visa R$ 8 mil” (ou só a quantidade se preferir compacto)

Expandir ou ir ao detalhe/edição para ver a árvore bandeira → números.

---

## Impacto em outras telas (resumo)

### Cadastro de fatura

1. Select do **cartão (grupo)** — `cartoes-list`
2. Se `bandeiras.length > 1` → exigir select da **bandeira** (`bandeiras-list?cartao_id=`)
3. Se só existe **1 bandeira** → selecionar automaticamente, **não mostrar** o campo
4. Enviar `cartao_id` + `cartao_bandeira_id` (quando aplicável / sempre que a API exigir)

Detalhes: ver [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

### Detalhe da fatura (view)

Transações agrupadas por `ultimos_digitos` / `cartao_numero`:

```
•••• 1234
  - compra A
  - compra B
•••• 5678
  - compra C
Sem cartão identificado
  - compra D
```

### Compras / projeção

- Select de cartão continua no **grupo**; em seguida select do **final** (`cartao_numero_id`) — ver [`frontend-prompt-compras.md`](frontend-prompt-compras.md).
- A bandeira da fatura é derivada do número escolhido (mostrar select de bandeira só se o fluxo exigir e houver > 1).
- `GET /cartoes/numeros-list` aceita `cartao_bandeira_id`, `cartao_id` ou `fatura_id`.
- % de limite utilizado na projeção: por **bandeira**.

---

## Checklist

- [ ] Topo do form: só dados do grupo (sem bandeira/final/limite)
- [ ] Base: adicionar final + bandeira (+ tipo/apelido) com botão “Adicionar cartão”
- [ ] Lista agrupada por bandeira com limite editável no cabeçalho da bandeira
- [ ] Limite único por bandeira (não por número)
- [ ] Payload aninhado `bandeiras[].numeros[]` + arrays de remoção
- [ ] Listagem mostra qtd bandeiras/números
- [ ] Remover uso dos campos flat `bandeira` / `ultimos_digitos` / `limite_credito` no root
- [ ] Lookups: `bandeiras`, `tipos_numero`, cores, dias
- [ ] Integrar regra de bandeira no cadastro de fatura e agrupamento por final na view
