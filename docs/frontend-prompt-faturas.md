# Prompt — Frontend: Faturas (listagem por cartão)

Use este prompt no repositório do frontend para alinhar a tela de faturas à API do `controle_fatura_back`.

---

## Objetivo

A tela de faturas deve:

1. **Listar faturas agrupadas por cartão** (não uma lista plana misturada)
2. **Não exibir transações** na listagem — só resumo da fatura
3. Exibir o **intervalo do ciclo** (início/fim) e a competência, com base no `dia_limite_fatura` / `dia_vencimento_fatura` do cartão
4. Abrir o **detalhe** (e as transações) só ao clicar em uma fatura

As faturas já são persistidas na tabela `faturas` (criadas manualmente ou automaticamente ao cadastrar compras).

---

## Ciclo do cartão → competência

Cada cartão define:

| Campo | Uso |
|-------|-----|
| `dia_limite_fatura` | Fecha o ciclo. Compras até este dia entram na fatura do mês; depois, na seguinte |
| `dia_vencimento_fatura` | Data de pagamento (informativo) |

Exemplo com limite = 5 e vencimento = 12:

| Compra | Competência | `periodo_inicio` | `periodo_fim` | `data_vencimento` |
|--------|-------------|------------------|---------------|-------------------|
| 05/08/2026 | 08/2026 | 06/07/2026 | 05/08/2026 | 12/08/2026 |
| 06/08/2026 | 09/2026 | 06/08/2026 | 05/09/2026 | 12/09/2026 |

Se `dia_vencimento <= dia_limite` (ex.: fecha 25, vence 05), o vencimento cai no **mês seguinte** à competência.

---

## API

Base: `/api/v1/faturas` (Bearer Sanctum)

### Listagem — **breaking change**

```http
GET /api/v1/faturas/listar?perPage=5&page=1
GET /api/v1/faturas/listar?cartao_id=1&mes=8&ano=2026&status=pendente
Authorization: Bearer {token}
```

**Ordenação fixa:** competência (`ano`/`mes` desc) → cartão (`nome` asc) → `status` asc.

**Paginação é por fatura** (`perPage` = faturas). A página vem reagrupada por cartão em `data[]` (um cartão pode aparecer só com as faturas daquela página).

Filtros: `cartao_id`, `mes`, `ano`, `status`, `palavra_chave`, `page`, `perPage`.

### Formato da resposta (`data`)

```json
{
  "current_page": 1,
  "per_page": 5,
  "total": 2,
  "data": [
    {
      "cartao_id": 1,
      "nome": "Nubank",
      "bandeira": "Mastercard",
      "banco": "Nubank",
      "ultimos_digitos": "1234",
      "dia_limite_fatura": 5,
      "dia_vencimento_fatura": 12,
      "cor_fundo": "#8b5cf6",
      "cor_texto": "#ffffff",
      "ativo": true,
      "total_faturas": 2,
      "valor_total": 450.9,
      "faturas": [
        {
          "id": 10,
          "mes": 8,
          "ano": 2026,
          "competencia": "08/2026",
          "periodo_inicio": "2026-07-06",
          "periodo_fim": "2026-08-05",
          "data_vencimento": "2026-08-12",
          "valor_total": "150.90",
          "arquivo_pdf": "faturas/1/....pdf",
          "tem_pdf": true,
          "status": "pendente",
          "erro_mensagem": null,
          "processado_em": null,
          "total_transacoes": 3,
          "transacoes_com_categoria": 2,
          "created_at": "...",
          "updated_at": "..."
        }
      ]
    }
  ]
}
```

**Importante:**

- `data[]` = grupos de cartão (não faturas soltas)
- Cada fatura traz contadores (`total_transacoes`), **não** o array de transações
- Use `cor_fundo` / `cor_texto` no chip do cartão
- Exiba `periodo_inicio`–`periodo_fim` e `data_vencimento` formatados em `dd/MM/yyyy`

### Detalhe (com resumo; transações em outro endpoint)

```http
GET /api/v1/faturas/listar/{id}
```

Retorna a fatura + chip do cartão + `competencia`, `periodo_inicio`, `periodo_fim`, `data_vencimento`, `tem_pdf`, `pdf_url`, contadores.

**Não** inclui a lista de transações. Para listar compras da fatura:

```http
GET /api/v1/transacoes/listar?fatura_id={id}&perPage=50
```

### Demais rotas (inalteradas)

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/lookups` | status, cartões, meses |
| POST | `/cadastrar` | multipart: `cartao_id`, `mes`, `ano`, `arquivo_pdf?`, `processar_automatico?` |
| PUT | `/editar` | altera período/status/valor |
| DELETE | `/excluir/{id}` | soft-delete fatura + transações |
| POST | `/upload-pdf` | anexa PDF/CSV/XML |
| POST | `/processar/{id}` | reprocessa arquivo |
| GET | `/pdf/{id}` | visualiza arquivo |
| GET | `/faturas-list` | select assíncrono |

---

## UI sugerida

### Tela de listagem

1. Filtros: cartão, mês, ano, status, busca
2. Para cada cartão da página:
   - Cabeçalho com chip (`background: cor_fundo; color: cor_texto`), nome, “Fecha dia X · Vence dia Y”
   - Subtotal do grupo (`valor_total` do cartão)
   - Cards/linhas das faturas **sem** expandir compras:
     - Competência (`08/2026`)
     - Período: `06/07/2026 – 05/08/2026`
     - Vencimento
     - Valor total
     - Status (badge)
     - Contador “N lançamentos” (`total_transacoes`) — só número
     - Ícone se `tem_pdf`
3. Clique na fatura → tela/drawer de detalhe
4. Ações na linha: upload PDF, processar, excluir, ver PDF

### Tela de detalhe

1. Mesmo cabeçalho do cartão + competência + intervalo + vencimento
2. Valor total e status
3. Bloco de PDF (preview / reprocessar)
4. **Só aqui** carregar transações via `GET /transacoes/listar?fatura_id=`

### Empty states

- Sem cartões com fatura → CTA para cadastrar compra ou importar PDF
- Cartão sem fatura no filtro de mês/ano → mensagem “Nenhuma fatura neste período”

---

## Checklist de aceite

- [ ] Listagem agrupa por cartão (não lista plana de faturas)
- [ ] Transações **não** aparecem na listagem
- [ ] Cada fatura mostra competência, intervalo início/fim e vencimento
- [ ] Chip usa `cor_fundo` + `cor_texto`
- [ ] Ordenação: competência → cartão → status
- [ ] Paginação trata `perPage` como quantidade de **faturas** (resposta agrupada por cartão)
- [ ] Detalhe busca transações só sob demanda (`fatura_id`)
- [ ] Filtros `cartao_id`, `mes`, `ano`, `status` funcionam
- [ ] Upload/processamento de PDF continua acessível a partir da fatura
