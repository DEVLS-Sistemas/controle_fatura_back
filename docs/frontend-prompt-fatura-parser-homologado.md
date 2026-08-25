# Prompt — Frontend: Cartões homologados para importação de fatura

Use este prompt no repositório do frontend para deixar **explícito quais cartões o sistema sabe ler** (PDF/CSV) e **avisar antes de anexar** fatura de banco ainda não homologado.

Backend **já implementado**. Cor no cadastro ≠ parser homologado. Santander, Bradesco, Magalu etc. têm cor oficial; **não** têm leitura de fatura testada.

Spec: [`modules/cartoes.md`](modules/cartoes.md) · Parsers: [`pdf-parsers.md`](pdf-parsers.md).

---

## Por que isso existe

Hoje só há parser testado com fatura real de:

| Banco | Nota |
|-------|------|
| Nubank | — |
| Inter | PDF e CSV |
| C6 Bank | — |
| Sofisa | — |
| PicPay | — |
| Itaú | **Só Itaú Click** |

Qualquer outro PDF **não quebra o cadastro**. Cai no parser **genérico** (`supports()` sempre `true`): regex frouxa `DD/MM descrição valor`. Layouts em duas colunas, datas `10 jun`, tabelas, PDFs escaneados → **zero lançamentos, lançamentos a menos, ou totais errados**, em geral **sem HTTP 500**.

Por isso: **não bloquear** o anexo de vez, mas **não deixar seguir no silêncio**. Confirmar com o texto de que o valor pode não ser o correto.

Cadastro do cartão (grupo, cores, ciclo) continua liberado para qualquer banco.

---

## Fonte da verdade (não hardcodar a lista)

```http
GET /api/v1/cartoes/lookups
GET /api/v1/faturas/lookups
```

```json
"parsers_homologados": [
  { "chave": "nubank", "label": "Nubank", "nota": null },
  { "chave": "inter", "label": "Inter", "nota": null },
  { "chave": "c6", "label": "C6 Bank", "nota": null },
  { "chave": "sofisa", "label": "Sofisa", "nota": null },
  { "chave": "picpay", "label": "PicPay", "nota": null },
  { "chave": "itau", "label": "Itaú", "nota": "Homologado com fatura Itaú Click" }
]
```

Em cada cartão (listagem, detalhe, `cartoes-list`, `faturas/lookups.cartoes[]`) e em cada swatch (`pares_cores[]`, `presets_cores[]`):

| Campo | Uso |
|-------|-----|
| `importacao_pdf_homologada` | `true` = leitura de PDF/CSV testada |
| `parser_homologado` | `{ chave, label, nota }` ou `null` |
| `aviso_parser` | Só no parse / modal de metadados, quando **não** homologado |

Match pelo **nome + banco** do cartão (mesmo critério das cores). `Nubank Principal` → homologado. `Santander SX` → não, mesmo com cor vermelha.

CSV Inter (`parser: "inter-csv"`) conta como homologado. `generico` / `csv` / `xml` → não.

---

## 1) Cartões — deixar claro o que está homologado

### Listagem / cards

No chip do cartão (já usa `cor_fundo` / `cor_texto`):

- `importacao_pdf_homologada === true` → badge discreto **PDF homologado** (ou ícone de check + tooltip com `parser_homologado.label`; se `nota`, mostrar a nota — Itaú Click)
- `false` → sem badge de “ok”, **ou** badge secundário **PDF não homologado** (cinza). Não parecer erro: o cartão é válido

Não esconder cartões não homologados. Não usar a cor do banco como sinal de homologação (Bradesco vermelho ≠ parser pronto).

### Formulário create/edit

Abaixo do seletor de cores (ou no rodapé do grupo):

**Se o nome/banco casar com homologado** (`presets_cores[].importacao_pdf_homologada` do preset aplicado):

> Importação de fatura (PDF/CSV) homologada para {label}. {nota?}

**Senão** (incluindo “Padrão” / banco só com cor):

> Você pode cadastrar este cartão. A leitura automática de PDF ainda não foi testada para ele — ao anexar uma fatura, os valores podem não ser os corretos.

Lista compacta opcional: “Hoje lemos: Nubank, Inter, C6, Sofisa, PicPay e Itaú Click.” — preferir `parsers_homologados[].label` (+ `nota` no tooltip do Itaú).

### Swatches de cor (`pares_cores`)

Todos os chips de cor **continuam**. Diferenciar:

| Chip | UI |
|------|-----|
| `importacao_pdf_homologada` | Indicador pequeno (check / ponto) + tooltip “PDF homologado” |
| Cor oficial, PDF não | Só a cor. Tooltip: “Cor oficial — importação de PDF ainda não homologada” |
| Padrão | Sem indicador |

Não filtrar a grade só nos homologados — senão some Santander/Magalu do seletor de cor.

---

## 2) Faturas — limitar o anexo com aviso (não com erro seco)

Dois momentos. Nos dois, o anexo **não some**: pede confirmação.

### A) Usuário já escolheu o cartão, depois anexa arquivo

Se `cartao.importacao_pdf_homologada === false` (ou o item do select não tiver o flag e o nome não estiver em `parsers_homologados`):

1. **Não** enviar o `POST` ainda.
2. Modal / dialog:

**Título:** Este cartão ainda não está homologado

**Corpo (obrigatório, sentido fiel):**

> Por enquanto o sistema lê com segurança faturas de **Nubank, Inter, C6, Sofisa, PicPay e Itaú Click**.
>
> O cartão **{nome}** ainda não foi testado. Você pode anexar o arquivo, mas **o valor e as compras lidos podem não ser os corretos**.

Lista: `parsers_homologados` (Itaú: mostrar `nota`).

**Ações:**

| Botão | Efeito |
|-------|--------|
| **Anexar mesmo assim** | Fecha o aviso e segue o upload (`processar_automatico` como hoje) |
| **Cadastrar sem anexo** | Limpa o arquivo; fatura só com cartão/mês/ano (lançamentos manuais) |
| Cancelar | Fecha; arquivo não entra |

Não usar toast de 1 linha no lugar deste modal. É decisão, não detalhe.

Guardar a confirmação **só nesta tentativa** (escolher outro arquivo ou outro cartão não homologado → avisar de novo). Não persistir “nunca mais avisar” no `localStorage`.

### B) Usuário anexa o PDF primeiro (sem cartão)

O back detecta o parser. No 422 `precisa_confirmar_metadados`, `sugestao` traz:

```json
{
  "parser": "generico",
  "importacao_pdf_homologada": false,
  "parser_homologado": null,
  "aviso_parser": "A leitura automática desta fatura ainda não está homologada. Os valores extraídos do arquivo podem não ser os corretos.",
  "valor_fatura": 1200.0,
  "conferencia": { "bate": false, "valor_cabecalho": 4500.0, "soma_transacoes": 1200.0 }
}
```

Se `sugestao.importacao_pdf_homologada === false` (ou `aviso_parser` preenchido):

- Banner **âmbar** no modal de metadados, **acima** dos campos, com `aviso_parser` (renderizar como veio)
- Checkbox obrigatório antes do submit: **“Li que os valores podem não ser os corretos e quero continuar”**
- Sem o check, o botão primário fica desabilitado

Se `parser_homologado.nota` existir (Itaú Click) e homologado: texto discreto da nota, não o banner de risco.

`conferencia.bate === false` **já** tem aviso de divergência cabeçalho vs soma — manter. Empilhar: primeiro “não homologado”, depois a divergência numérica.

### C) Upload em fatura já existente (`POST /upload-pdf`, reprocessar)

Mesma regra do cartão da fatura. Se não homologado → modal A antes de enviar.

---

## 3) Copy — não negociar o sentido

Pode enxugar; **não** suavizar para “pode haver pequenas diferenças”.

- Homologado = testamos faturas reais desse banco (Itaú = Click).
- Não homologado = o arquivo **entra**, o parser genérico **tenta**, o total **pode estar errado**.
- Cadastrar o cartão **nunca** é bloqueado.

Errado: “Importação indisponível” / esconder o dropzone / 403 no back.  
Certo: dropzone visível + confirmação explícita.

---

## 4) Empty / edge

| Situação | UI |
|----------|-----|
| Cartão homologado, PDF imagem (sem texto) | Erro já existente de extração — **não** misturar com o aviso de homologação |
| Cartão homologado, senha PDF | Fluxo de senha — homologação não muda |
| Cartão “Itaú Personnalité” (nome casa `itau`) | Flag **true** (mesmo parser). A `nota` avisa que o teste foi no Click — mostrar a nota |
| CSV genérico (`parser: csv`) | Tratar como não homologado (exceto `inter-csv`) |

---

## Critérios de aceite

- [ ] Lista `parsers_homologados` da API; front **não** duplica a lista no código (exceto fallback se o campo faltar)
- [ ] Listagem de cartões: badge/tooltip de PDF homologado vs não
- [ ] Form de cartão: texto distinto cor-oficial × parser homologado
- [ ] Swatches: check só nos homologados; Santander/Bradesco continuam na grade
- [ ] Anexar PDF em cartão não homologado → modal com a frase de valor incorreto + Anexar mesmo assim / Sem anexo
- [ ] Modal de metadados com `importacao_pdf_homologada: false` → banner `aviso_parser` + checkbox obrigatório
- [ ] Itaú exibe a nota do Click
- [ ] Cadastro de cartão não homologado **não** é bloqueado
- [ ] Confirmação não fica salva para sempre no `localStorage`

---

## Fora de escopo

- Implementar parser de Santander/Bradesco/etc. no front
- Recalcular `valor_total` no cliente
- Esconder bancos do seletor de cores

---

Lookups: `GET /api/v1/cartoes/lookups` · `GET /api/v1/faturas/lookups`  
Cartões: [`frontend-prompt-cartoes.md`](frontend-prompt-cartoes.md) · cores: [`frontend-prompt-cores-cartoes.md`](frontend-prompt-cores-cartoes.md)  
Faturas: [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md) · metadados: [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md)
