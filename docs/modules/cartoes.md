# Especificação — Cartões

## Tabela `cartoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK users | Multiusuário |
| nome | string | Nome personalizado |
| bandeira | string nullable | Visa, Mastercard... |
| banco | string nullable | |
| ultimos_digitos | string(4) nullable | |
| limite_credito | decimal(12,2) nullable | Limite de crédito do cartão |
| dia_limite_fatura | tinyint 1-31 | Dia de fechamento do ciclo |
| dia_vencimento_fatura | tinyint 1-31 | Dia de pagamento da fatura |
| cor_fundo | string nullable | Hex do fundo do chip/badge |
| cor_texto | string nullable | Hex do texto sobre o fundo |
| ativo | boolean | default true |

SoftDeletes + timestamps.

## Ciclo da fatura

- Compras com `data.day <= dia_limite_fatura` entram na fatura do **mês atual**.
- Compras com `data.day > dia_limite_fatura` entram na fatura do **mês seguinte**.
- Em meses com menos dias que o limite (ex.: limite 31 em fevereiro), o limite efetivo é o último dia do mês.
- Parcelas: a parcela 1 usa o ciclo acima; as demais avançam +1 mês a partir desse período.
- `dia_vencimento_fatura` é informativo (prazo de pagamento); não altera a alocação da compra.
- Se `dia_limite_fatura` for nulo (legado), usa o mês calendário da data.

Exemplo com limite = 5 e data `01/08/2026`:

- Compra até `05/08/2026` → fatura de **agosto/2026**
- Compra a partir de `06/08/2026` → fatura de **setembro/2026** (inclusive se parcelada)

## Limite de crédito

- `limite_credito` é opcional no cadastro.
- Usado na projeção de faturas para calcular `% utilizado` e valor disponível por mês.
- Aceita número ou string BR (ex.: `"5.000,00"` / `"5000"`).
- Se informado, deve ser **> 0**.

## Rotas (`/api/v1/cartoes`)

Padrão CRUD completo (`lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `cartoes-list`).

### Lookups

- `bandeiras`
- `cores_fundo` / `cores_texto` (paletas hex)
- `pares_cores` (sugestões prontas `{ cor_fundo, cor_texto, label }`)
- `dias` (1..31 para selects de limite/vencimento)

### Payload create/edit

```json
{
  "nome": "Nubank Principal",
  "bandeira": "Mastercard",
  "banco": "Nubank",
  "ultimos_digitos": "1234",
  "limite_credito": 8000.00,
  "dia_limite_fatura": 5,
  "dia_vencimento_fatura": 12,
  "cor_fundo": "#8b5cf6",
  "cor_texto": "#ffffff",
  "ativo": true
}
```

No create, `dia_limite_fatura` e `dia_vencimento_fatura` são **obrigatórios**.  
`limite_credito` é **opcional**.  
Cores aceitam hex `#RGB` ou `#RRGGBB`.

## Filtros listar

- `nome`, `bandeira`, `banco`, `ativo`, `palavra_chave`
- `page`, `perPage`
