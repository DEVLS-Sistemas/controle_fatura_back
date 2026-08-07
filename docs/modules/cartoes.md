# Especificação — Cartões (grupo → bandeira → número)

## Hierarquia

```
Cartão (grupo) ………… ex.: "Sofisa"
├── Bandeira …………… ex.: Mastercard  → limite_credito único
│   ├── Número ……… ex.: final 1234 (físico / adicional)
│   └── Número ……… ex.: final 5678 (virtual)
└── Bandeira …………… ex.: Visa         → limite_credito único
    └── Número ……… ex.: final 9999
```

| Nível | Tabela | Responsabilidade |
|-------|--------|------------------|
| Grupo | `cartoes` | Nome, banco, ciclo (fechamento/vencimento), cores, ativo |
| Bandeira | `cartao_bandeiras` | Bandeira (Visa/Master/…), **limite de crédito**, ativo |
| Número | `cartao_numeros` | Últimos 4 dígitos, tipo, apelido, nome no cartão, ativo |

**Regras de negócio**

1. Um grupo pode ter **várias bandeiras** (faturas separadas).
2. O **limite é por bandeira** (não por número físico/virtual).
3. Uma bandeira pode ter **vários números** (adicional, virtual, substituição por vencimento/bloqueio).
4. A **fatura** pertence à **bandeira** (`cartao_bandeira_id`), não ao número.
5. A **transação** pode apontar para o **número** (`cartao_numero_id`) para agrupar por final na view da fatura.
6. Finais detectados na fatura (PDF) são sempre vinculados à **mesma bandeira** da fatura (`cartao_bandeira_id`) — nunca cruzam para outra bandeira do grupo.
7. Se a fatura for processada **sem bandeira** no cartão: o job cria a bandeira (detectada no PDF ou `"Outra"`), vincula à fatura e só então cria/vincula o final às transações.

---

## Tabelas

### `cartoes` (grupo)

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK users | Multiusuário |
| nome | string | Ex.: Sofisa, Nubank |
| banco | string nullable | |
| dia_limite_fatura | tinyint 1-31 | Fechamento do ciclo |
| dia_vencimento_fatura | tinyint 1-31 | Dia de pagamento |
| cor_fundo | string nullable | Hex do chip |
| cor_texto | string nullable | Hex do texto |
| ativo | boolean | default true |
| senha_pdf | text nullable | Criptografada (`encrypted` cast). Usada ao extrair texto de PDF protegido. **Nunca** retornada na API. |
| senha_pdf_regra | string nullable | Código da regra (`cpf_cnpj_4/5/6/8_digitos`, `cpf_11_digitos`, `cnpj_14_digitos`). Ver `PdfSenhaRegra`. |

SoftDeletes + timestamps.

> Campos removidos deste nível (migrados): `bandeira`, `ultimos_digitos`, `limite_credito`.

### `cartao_bandeiras`

| Campo | Tipo | Obs |
|-------|------|-----|
| cartao_id | FK cartoes | |
| bandeira | string | Visa, Mastercard, Elo, Amex, Hipercard, Outra |
| limite_credito | decimal(12,2) nullable | Limite **desta** bandeira |
| ativo | boolean | default true |

SoftDeletes + timestamps.  
Único lógico: `(cartao_id, bandeira)` entre não deletados.

### `cartao_numeros`

| Campo | Tipo | Obs |
|-------|------|-----|
| cartao_bandeira_id | FK cartao_bandeiras | |
| ultimos_digitos | string(4) | Final do cartão |
| tipo | enum nullable | `fisico`, `virtual`, `adicional` |
| apelido | string nullable | Ex.: “Cartão da esposa”, “Virtual viagem” |
| nome_no_cartao | string nullable | Nome impresso no plástico (ex.: “LEONARDO S FERREIRA”) |
| ativo | boolean | default true |

SoftDeletes + timestamps.  
Único lógico: `(cartao_bandeira_id, ultimos_digitos)` entre não deletados.

---

## Impacto em faturas e transações

### `faturas`

| Campo | Mudança |
|-------|---------|
| `cartao_id` | Mantido (grupo) para listagem/agrupamento e ciclo |
| `cartao_bandeira_id` | **Novo FK obrigatório** — fatura é da bandeira |

Unicidade: `(user_id, cartao_bandeira_id, mes, ano)`.

Sofisa Mastercard 08/2026 e Sofisa Visa 08/2026 são faturas distintas.

### `transacoes`

| Campo | Mudança |
|-------|---------|
| `cartao_numero_id` | **Novo FK nullable** → `cartao_numeros` |

Usado para separar/agrupar compras por final do cartão na tela de detalhe da fatura.  
Parsers de PDF devem tentar capturar o final e vincular ao número cadastrado (ou criar se a política permitir).

---

## Ciclo da fatura

Continua no **grupo** (`cartoes`):

- Compras com `data.day <= dia_limite_fatura` → fatura do mês atual.
- Compras com `data.day > dia_limite_fatura` → fatura do mês seguinte.
- Em meses curtos, o limite efetivo é o último dia do mês.
- Parcelas: parcela 1 usa o ciclo; demais avançam +1 mês.
- `dia_vencimento_fatura` é informativo.

---

## Limite de crédito

- Vive em `cartao_bandeiras.limite_credito` (opcional, se informado > 0).
- Projeção / % utilizado: por **bandeira**.
- Aceita número ou string BR (`"8.000,00"` / `"8000"`).

---

## Rotas (`/api/v1/cartoes`)

CRUD padrão no **grupo**, com bandeiras e números aninhados no payload.

### Lookups

- `bandeiras` — Visa, Mastercard, Elo, Amex, Hipercard, Outra
- `tipos_numero` — fisico, virtual, adicional
- `cores_fundo` / `cores_texto` / `pares_cores`
- `dias` (1..31)
- `senhas_pdf_regras` — regras de senha de PDF (`value`, `label`, `orientacao`, `digitos`, `bancos_sugeridos`)

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
  "senha_pdf": "123456",
  "senha_pdf_regra": "cpf_cnpj_6_digitos",
  "bandeiras": [
    {
      "id": 1,
      "bandeira": "Mastercard",
      "limite_credito": "15.000,00",
      "ativo": true,
      "numeros": [
        { "id": 10, "ultimos_digitos": "1234", "tipo": "fisico", "apelido": null, "nome_no_cartao": "LEONARDO S FERREIRA", "ativo": true },
        { "id": 11, "ultimos_digitos": "5678", "tipo": "virtual", "apelido": "Viagem", "nome_no_cartao": null, "ativo": true }
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
  ]
}
```

**Create:** `nome`, `dia_limite_fatura`, `dia_vencimento_fatura` obrigatórios.  
`bandeiras` e `numeros` (finais) são **opcionais** — o grupo pode ser cadastrado sem bandeiras/finais e preenchido depois. Cada bandeira também pode existir com `numeros: []`.  
`senha_pdf` / `senha_pdf_regra` opcionais. Se `senha_pdf_regra` omitida e `banco` for C6, o back sugere `cpf_cnpj_6_digitos`.

**Delete:** soft-delete do grupo + bandeiras + números. **Bloqueado (422)** se existir fatura vinculada ao cartão — mensagem: `"Não é possível excluir cartão com fatura anexada vinculada"`. Exclua as faturas antes.

**Resposta do grupo** inclui `tem_senha_pdf`, `senha_pdf_regra`, `senha_pdf_orientacao`, `senha_pdf_regra_label` (nunca a senha em claro). No edit: enviar `limpar_senha_pdf=true` para apagar; só enviar `senha_pdf` se o usuário digitou um valor novo.

**Edit (sincronização aninhada):**

- Itens com `id` → atualiza
- Itens sem `id` → cria
- Itens existentes omitidos (ou enviados em `bandeiras_remover` / `numeros_remover`) → soft delete  
  Preferência: arrays `bandeiras_remover: [id…]` e `numeros_remover: [id…]` para evitar apagar por omissão acidental.

### Detalhe / listagem

Cada grupo retorna `bandeiras[]` com `numeros[]`, `qtd_bandeiras`, `qtd_numeros` e, se útil, `limite_total` (soma dos limites das bandeiras ativas).

### Async select (`cartoes-list`)

Continua listando o **grupo**. Para selects que precisam da bandeira (fatura/compra), usar:

```http
GET /api/v1/cartoes/bandeiras-list?cartao_id=1
```

```json
[
  { "value": 1, "label": "Mastercard", "limite_credito": 15000, "qtd_numeros": 2 },
  { "value": 2, "label": "Visa", "limite_credito": 8000, "qtd_numeros": 1 }
]
```

```http
GET /api/v1/cartoes/numeros-list?cartao_bandeira_id=1
GET /api/v1/cartoes/numeros-list?cartao_id=1
GET /api/v1/cartoes/numeros-list?fatura_id=10
```

Aceita `cartao_bandeira_id`, `cartao_id` ou `fatura_id` (pelo menos um).

```json
[
  {
    "value": 10,
    "label": "•••• 1234 · LEONARDO S FERREIRA",
    "ultimos_digitos": "1234",
    "tipo": "fisico",
    "apelido": null,
    "nome_no_cartao": "LEONARDO S FERREIRA",
    "cartao_bandeira_id": 1,
    "bandeira": "Mastercard"
  },
  {
    "value": 11,
    "label": "•••• 5678 · LEONARDO S FERREIRA (Viagem)",
    "ultimos_digitos": "5678",
    "tipo": "virtual",
    "apelido": "Viagem",
    "nome_no_cartao": "LEONARDO S FERREIRA",
    "cartao_bandeira_id": 1,
    "bandeira": "Mastercard"
  }
]
```

Com `fatura_id`, a lista restringe aos finais da **bandeira da fatura** (mesma regra do PDF).

---

## Migração de dados legados

Para cada `cartoes` antigo:

1. Mantém a linha como **grupo** (nome, banco, ciclo, cores).
2. Cria 1 `cartao_bandeiras` com `bandeira` + `limite_credito` antigos (bandeira default `"Outra"` se nula).
3. Se havia `ultimos_digitos`, cria 1 `cartao_numeros` nessa bandeira.
4. Preenche `faturas.cartao_bandeira_id` com a bandeira criada do `cartao_id`.
5. Remove colunas `bandeira`, `ultimos_digitos`, `limite_credito` de `cartoes`.

---

## Filtros listar

- `nome`, `banco`, `bandeira` (existe alguma bandeira com esse nome), `ativo`, `palavra_chave`
- `page`, `perPage`

---

## Prompt do front

[`docs/frontend-prompt-cartoes.md`](../frontend-prompt-cartoes.md)  
Senha de PDF + modal: [`docs/frontend-prompt-senha-pdf-fatura.md`](../frontend-prompt-senha-pdf-fatura.md)
