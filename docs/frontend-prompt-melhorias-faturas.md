# Prompt — Frontend: Melhorias no cadastro/listagem de faturas

Use este prompt no repositório do frontend para aplicar 3 melhorias na tela de faturas. Complementa [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

---

## Objetivo

1. Na **listagem**, trocar a coluna/badge de **bandeira** por indicadores de **anexo** (PDF e/ou CSV)
2. Garantir que a UI de **quitação** (`pago` / pago / restante) reflita a API após cadastrar/processar faturas em sequência
3. No **detalhe da fatura**, adicionar navegação **Fatura anterior** / **Próxima fatura** da mesma bandeira, sem voltar à lista

---

## 1) Coluna de anexos (PDF / CSV) na listagem

### Contexto

Hoje a listagem destaca a **bandeira**. A bandeira continua existindo no payload (e no detalhe), mas na listagem o espaço visual deve informar se a fatura tem arquivo vinculado.

Tipos aceitos no upload passam a ser só **PDF** e **CSV** (XML deixa de ser oferecido no front). Cada fatura tem **um** anexo (`arquivo_pdf` no backend — nome legado); o tipo vem da extensão.

### Campos da API (listagem e detalhe)

```json
{
  "arquivo_pdf": "faturas/1/abc123.pdf",
  "tipo_arquivo": "pdf",
  "tem_pdf": true,
  "tem_csv": false,
  "pdf_url": "http://localhost:5000/api/v1/faturas/pdf/10"
}
```

| Campo | Tipo | Uso |
|-------|------|-----|
| `tipo_arquivo` | `"pdf"` \| `"csv"` \| `null` | Tipo do anexo atual |
| `tem_pdf` | bool | `true` **somente** se o anexo for PDF |
| `tem_csv` | bool | `true` **somente** se o anexo for CSV |
| `pdf_url` | string\|null | URL autenticada para abrir/baixar o anexo (PDF ou CSV) |
| `arquivo_pdf` | string\|null | Path interno (não exibir ao usuário) |

> Fallback se a API ainda não mandar `tipo_arquivo` / `tem_csv`: derive pela extensão de `arquivo_pdf` (`.pdf` → PDF, `.csv` → CSV). `tem_pdf` legado que era “tem qualquer arquivo” deve ser tratado com a regra nova (só PDF).

### UI da coluna “Anexo”

Substituir a coluna/célula de bandeira por algo como:

| Situação | Exibição |
|----------|----------|
| Sem anexo | “—” ou ícone cinza desabilitado (tooltip: “Sem anexo”) |
| Só PDF | Ícone **PDF** vermelho/colorido (ex. FilePdf) |
| Só CSV | Ícone **CSV** verde/colorido (ex. FileSpreadsheet) |

Regras:

- Mostrar o ícone **apenas** quando o tipo correspondente existir (`tem_pdf` / `tem_csv`)
- Tooltip: “PDF anexado” / “CSV anexado”
- Clique no ícone (opcional): abrir `pdf_url` (Bearer) ou acionar a ação “Ver anexo” já existente
- **Bandeira**: não usar mais como coluna principal da listagem. Se o grupo tiver **mais de uma** bandeira, manter um chip secundário discreto na linha (ou no subtítulo), para não perder a distinção Visa/Master

### Upload / cadastro

1. `accept` do input: `.pdf,.csv,application/pdf,text/csv`
2. Remover XML das opções de UI e textos de ajuda
3. Label sugerido: “Anexo da fatura (PDF ou CSV)”
4. Continua enviando no campo multipart `arquivo_pdf` (nome da API não muda)
5. Rotas iguais: `POST /cadastrar`, `POST /upload-pdf`, `GET /pdf/{id}`, `POST /processar/{id}`

---

## 2) Quitação — comportamento esperado na UI

### Regra (não recalcular no front)

A fatura **F** é quitada pelos pagamentos (`tipo = payment`) da competência **seguinte** (F+1), mesma bandeira:

| Campo | Uso na UI |
|-------|-----------|
| `valor_total` | Total da fatura |
| `valor_pago` | Quanto já foi quitado |
| `valor_restante` | O que falta |
| `pago` | Badge **Paga** / **Em aberto** |

Nunca use `status` (processamento do arquivo) como quitação.

### Cenário que a UI precisa cobrir bem

Ao cadastrar faturas “de trás para frente”:

1. Usuário importa **07/2026** → API cria competências vizinhas (parcelas) e a competência **06/2026** deve aparecer como **Paga** se 07 tiver pagamentos suficientes
2. Em seguida importa **06/2026** → a competência **05/2026** deve passar a **Paga** (pagamentos de 06 abatem 05)
3. O mesmo para gaps maiores (ex.: importar **04/2025** → **03/2025** deve refletir quitação pelos pagamentos de 04)

### O que o front deve fazer

1. **Sempre** renderizar `pago` / `valor_pago` / `valor_restante` vindos da API (listagem e detalhe)
2. Após `POST /cadastrar`, `POST /upload-pdf` ou `POST /processar/{id}` com sucesso:
   - Invalidar cache / refetch da **listagem** (não só da fatura atual)
   - Se estiver no detalhe, refetch do detalhe também
3. Não manter estado local de “paga” após processar — a competência **anterior** muda sem o usuário abri-la
4. Loading/skeleton na listagem enquanto refetcha, para não mostrar badge stale

> A API calcula `pago` assim: pagamentos (`tipo=payment`) de F+1 × `valor_total` de F. Residual de fatura anterior só entra no total se a anterior estiver `processada` (stubs `pendente` de parcela não inflacionam). Após processar F+1, refetch a listagem — a competência F deve atualizar sozinha.

### Bloco financeiro (inalterado)

```
Total      R$ {valor_total}
Pago       R$ {valor_pago}
Restante   R$ {valor_restante}
[Paga | Em aberto]   ← campo `pago`
```

---

## 3) Navegação anterior / próxima no detalhe

### Objetivo

Dentro de `GET /api/v1/faturas/listar/{id}`, o usuário troca de competência **da mesma bandeira** com um clique, sem voltar à lista.

### Campos da API no detalhe

```json
{
  "id": 73,
  "cartao_id": 1,
  "cartao_bandeira_id": 1,
  "competencia": "06/2026",
  "fatura_anterior_id": 72,
  "fatura_proxima_id": 74,
  "fatura_anterior_competencia": "05/2026",
  "fatura_proxima_competencia": "07/2026"
}
```

| Campo | Significado |
|-------|-------------|
| `fatura_anterior_id` | Competência imediatamente anterior **existente** (mesma `cartao_bandeira_id`), ou `null` |
| `fatura_proxima_id` | Próxima competência existente (mesma bandeira), ou `null` |
| `*_competencia` | Label opcional para tooltip/botão (`05/2026`) |

Ordem: `ano`/`mes` ascendente na linha do tempo da bandeira (não do grupo inteiro misturando Visa/Master).

### UI no topo do detalhe

```
[← Anterior 05/2026]     Sofisa · Mastercard · 06/2026     [07/2026 Próxima →]
```

Regras:

1. Botões no **topo** do detalhe (cabeçalho), sempre visíveis no desktop; no mobile podem ir para uma barra sticky
2. `fatura_anterior_id === null` → desabilitar “Anterior”
3. `fatura_proxima_id === null` → desabilitar “Próxima”
4. Clique → navegar para a rota de detalhe do id (`/faturas/{id}` ou equivalente) e carregar `GET /listar/{id}` + transações
5. Preservar query/filtros da listagem só se o app já fizer isso; não é obrigatório
6. Atalhos opcionais: setas do teclado ← / → quando não estiver em input

### Fallback (se a API ainda não mandar os ids)

```http
GET /api/v1/faturas/listar?cartao_bandeira_id={id}&perPage=100
```

Ordenar as faturas do grupo por `ano`/`mes`, achar o índice da fatura atual e pegar vizinhos. Preferir os campos do detalhe quando existirem.

---

## Checklist de aceite

- [ ] Listagem: coluna de bandeira principal substituída por ícones de anexo
- [ ] Ícone PDF colorido só quando `tem_pdf` (anexo PDF)
- [ ] Ícone CSV colorido só quando `tem_csv` (anexo CSV)
- [ ] Sem anexo → estado vazio claro
- [ ] Upload aceita apenas PDF e CSV (XML removido da UI)
- [ ] Badge Paga/Em aberto usa só `pago` da API
- [ ] Após cadastrar/processar fatura, listagem é refetchada (competência anterior atualiza)
- [ ] Detalhe: botões Anterior / Próxima no topo
- [ ] Navegação restrita à mesma `cartao_bandeira_id`
- [ ] Botões desabilitados nas pontas (`null`)
- [ ] Clique troca o detalhe sem passar pela listagem

---

## Referências

- Prompt base: [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md)
- Módulo: [`modules/faturas.md`](modules/faturas.md)
- Cartões (grupo → bandeira): [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md)
