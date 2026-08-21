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
| Nome no cartão | **Número** (`nome_no_cartao`) | Nome impresso no plástico (ex.: LEONARDO S FERREIRA) |
| Fatura | Ligada à **bandeira** | Visa e Master do mesmo banco = faturas separadas |
| Transação na fatura | Pode ter **número** | View da fatura agrupa por final; finais do PDF ficam na bandeira da fatura |

Campos antigos removidos do payload raiz: `bandeira`, `ultimos_digitos`, `limite_credito` (agora dentro de `bandeiras[]` / `numeros[]`).

---

## API

Base: `/api/v1/cartoes` (Bearer Sanctum)

CRUD padrão no grupo: `lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `cartoes-list`.

`DELETE /excluir/{id}` retorna **422** se o cartão tiver fatura vinculada (`"Não é possível excluir cartão com fatura anexada vinculada"`). Exiba o erro e oriente a excluir as faturas antes.

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
  "cor_padrao": {
    "chave": "padrao",
    "label": "Padrão",
    "cor_fundo": "#e5e7eb",
    "cor_texto": "#111827",
    "padrao": true
  },
  "cores_fundo": ["#e5e7eb", "#820ad1", "#ff7a00"],
  "cores_texto": ["#111827", "#ffffff", "#000000", "#003da5"],
  "pares_cores": [
    { "chave": "padrao", "label": "Padrão", "cor_fundo": "#e5e7eb", "cor_texto": "#111827", "padrao": true },
    { "chave": "nubank", "label": "Nubank", "cor_fundo": "#820ad1", "cor_texto": "#ffffff", "padrao": false }
  ],
  "presets_cores": [
    { "chave": "nubank", "label": "Nubank", "aliases": ["nubank", "nu bank"], "cor_fundo": "#820ad1", "cor_texto": "#ffffff" }
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
  "cor_fundo": "#008f5a",
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
          "nome_no_cartao": "LEONARDO S FERREIRA",
          "ativo": true
        },
        {
          "ultimos_digitos": "5678",
          "tipo": "virtual",
          "apelido": "Viagem",
          "nome_no_cartao": "LEONARDO S FERREIRA",
          "ativo": true
        }
      ]
    },
    {
      "bandeira": "Visa",
      "limite_credito": "8.000,00",
      "ativo": true,
      "numeros": [
        { "ultimos_digitos": "9999", "tipo": "fisico", "nome_no_cartao": "LEONARDO S FERREIRA", "ativo": true }
      ]
    }
  ],
  "bandeiras_remover": [],
  "numeros_remover": []
}
```

Obrigatoriedade no create: `nome`, `dia_limite_fatura`, `dia_vencimento_fatura`.  
`bandeiras` e finais (`numeros`) são **opcionais** — permitir salvar o grupo sem nenhum final (e com bandeira sem `numeros`, se quiser).  
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
  "cor_fundo": "#008f5a",
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
        { "id": 10, "ultimos_digitos": "1234", "tipo": "fisico", "apelido": null, "nome_no_cartao": "LEONARDO S FERREIRA", "ativo": true },
        { "id": 11, "ultimos_digitos": "5678", "tipo": "virtual", "apelido": "Viagem", "nome_no_cartao": "LEONARDO S FERREIRA", "ativo": true }
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
- Par de cores (`pares_cores`: Padrão + bancos oficiais) + preview do chip. Auto-aplica ao digitar nome/banco se o usuário não escolheu na mão. Ver [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md).
- Ativo
- **Senha do PDF** + **regra de senha** — ver prompt dedicado [`frontend-prompt-senha-pdf-fatura.md`](frontend-prompt-senha-pdf-fatura.md) (input password com olho, select de regra, orientação C6 = 6 dígitos do CPF/CNPJ)

**Remover** do topo: bandeira, últimos dígitos, limite de crédito (esses sobem/descem para a seção de baixo).

### Base — adicionar números / bandeiras

Seção **“Cartões deste grupo”** (ou “Números / bandeiras”):

1. Linha de inclusão:
   - Select **Bandeira** (`lookups.bandeiras`)
   - Input **Final** — **opcional** (se preenchido: exatamente 4 dígitos; se vazio, adiciona só a bandeira)
   - Input **Nome no cartão** — opcional (nome impresso, ex.: `LEONARDO S FERREIRA`)
   - Select **Tipo** (físico / virtual / adicional) — opcional
   - Input **Apelido** — opcional (rótulo interno; distinto do nome no cartão)
   - Input **Limite da bandeira** — ver regra abaixo
   - Botão **Adicionar cartão**
2. Ao adicionar, o item entra na lista abaixo (estado local → enviado no save). Salvar o formulário **não exige** finais nem bandeiras.
3. Lista agrupada por bandeira (bandeira sem finais permanece visível):

```
Mastercard · Limite R$ 15.000,00                    [editar limite]
  •••• 7025  LEONARDO S FERREIRA  Físico    [ativar/desativar] [remover]
  •••• 7033  LEONARDO S FERREIRA  Virtual · Viagem  [ativar/desativar] [remover]
Visa · Limite R$ 8.000,00
  •••• 9999  LEONARDO S FERREIRA  Físico    [ativar/desativar] [remover]
```

> `apelido` = rótulo interno do usuário. `nome_no_cartao` = texto impresso no plástico (aparece na fatura PDF).

### Regra do limite na inclusão

- Se a bandeira **já existe** na lista local → não pedir limite de novo; o número entra nela (exige final). Limite editável no cabeçalho do grupo da bandeira.
- Se a bandeira é **nova** → mostrar campo limite (opcional) na linha de inclusão; ao adicionar, cria a bandeira (com ou sem o primeiro número).
- Limite é **um só por bandeira** — nunca por linha de número.

### Validações de UI

- Final: opcional; se informado, exatamente 4 dígitos numéricos
- Não duplicar o mesmo `ultimos_digitos` dentro da mesma bandeira
- Remoção de bandeira: enviar id em `bandeiras_remover` (e números dela em `numeros_remover` se já persistidos)
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
5. Finais que aparecerem na fatura (ex.: PicPay `final 7025` / `final 7033`) ficam vinculados a **essa mesma bandeira** — não misturar com Visa/Master do outro lado do grupo

Detalhes: ver [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

### Detalhe da fatura (view)

Transações agrupadas por `ultimos_digitos` / `cartao_numero` (usar `nome_no_cartao` no cabeçalho do grupo quando existir):

```
•••• 7025 · LEONARDO S FERREIRA
  - compra A
  - compra B
•••• 7033 · LEONARDO S FERREIRA
  - compra C
Sem cartão identificado
  - compra D
```

### Compras / projeção

- Select de cartão continua no **grupo**; em seguida select do **final** (`cartao_numero_id`) — ver [`frontend-prompt-compras.md`](frontend-prompt-compras.md).
- A bandeira da fatura é derivada do número escolhido (mostrar select de bandeira só se o fluxo exigir e houver > 1).
- `GET /cartoes/numeros-list` aceita `cartao_bandeira_id`, `cartao_id` ou `fatura_id`.
- Na projeção (`projecao-faturas`), o limite do **grupo** é a soma dos limites das bandeiras ativas. Cada cartão expõe **Limite / Em uso / Livre** (valor + %) via `uso_limite`, além do split **Eu vs Outros** — ver [`frontend-prompt-projecao-faturas.md`](frontend-prompt-projecao-faturas.md).

---

## Checklist

- [ ] Topo do form: só dados do grupo (sem bandeira/final/limite)
- [ ] Base: adicionar bandeira com final opcional (+ nome_no_cartao/tipo/apelido) com botão “Adicionar cartão”
- [ ] Permitir salvar o grupo sem nenhum final/bandeira
- [ ] Lista agrupada por bandeira com limite editável no cabeçalho da bandeira
- [ ] Limite único por bandeira (não por número)
- [ ] Payload aninhado `bandeiras[].numeros[]` + arrays de remoção
- [ ] Listagem mostra qtd bandeiras/números
- [ ] Remover uso dos campos flat `bandeira` / `ultimos_digitos` / `limite_credito` no root
- [ ] Lookups: `bandeiras`, `tipos_numero`, `pares_cores` / `presets_cores` / `cor_padrao`, dias
- [ ] Cores oficiais dos bancos no seletor + auto-apply (ver [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md))
- [ ] Integrar regra de bandeira no cadastro de fatura e agrupamento por final na view
