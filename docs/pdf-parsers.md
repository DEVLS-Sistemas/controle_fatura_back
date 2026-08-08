# Adaptando o Parser de PDF por Banco

O sistema extrai texto do PDF com `spatie/pdf-to-text` (`pdftotext -layout`) e escolhe o primeiro parser cujo método `supports()` retornar `true`. O total do cabeçalho (`no valor de R$ X`) é gravado em `valor_total` quando bate com a soma das transações do ciclo; em divergência (ex.: Inter lendo o limite do cartão), prevalece a soma. Toda leitura devolve `conferencia: { valor_cabecalho, soma_transacoes, bate, diferenca }`.

## Estrutura

```
app/Services/Pdf/
  InvoicePdfParserService.php   # orquestra parsers
  Parsers/
    InvoiceParserInterface.php
    AbstractInvoiceParser.php   # helpers: money, date, parcelas, tipo
    NubankInvoiceParser.php
    ItauInvoiceParser.php
    InterInvoiceParser.php
    C6InvoiceParser.php
    PicPayInvoiceParser.php
    SofisaInvoiceParser.php
    GenericInvoiceParser.php    # fallback
```

## Como adicionar um novo banco (ex.: Bradesco)

1. Crie `app/Services/Pdf/Parsers/BradescoInvoiceParser.php` estendendo `AbstractInvoiceParser`.
2. Implemente:
   - `name()` → `'bradesco'`
   - `supports(string $text)` → detecte palavras-chave do PDF (`bradesco`, `banco bradesco`, etc.)
   - `parse(string $text)` → percorra linhas e monte transações via `$this->makeTransaction(...)`
3. Registre no construtor de `InvoicePdfParserService` **antes** do `GenericInvoiceParser`.

```php
$this->parsers = $parsers ?? [
    new NubankInvoiceParser(),
    new ItauInvoiceParser(),
    new InterInvoiceParser(),
    new C6InvoiceParser(),
    new PicPayInvoiceParser(),
    new SofisaInvoiceParser(),
    new BradescoInvoiceParser(), // ← novo
    new GenericInvoiceParser(),
];
```

## Fluxo recomendado de adaptação

1. Faça upload de uma fatura real e capture o texto bruto (`pdftotext -layout fatura.pdf -` ou log temporário em `parseFile`).
2. Identifique o padrão de linha (data + descrição + valor).
3. Ajuste a regex no parser específico.
4. Trate parcelas (`03/12`, `Parc 3/12`) com `$this->parseInstallment()`.
5. Valide tipos (`payment`, `refund`, `advance`) via `$this->detectType()` ou override.

## Formato padronizado de saída

Cada item retornado por `parse()` deve ter:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `data` | `Y-m-d` \| null | Data da compra |
| `estabelecimento` | string | Nome **sem** parcela (ex.: `Loja`, nunca `Loja 1/3`) |
| `valor` | float | Valor absoluto |
| `parcelas_total` | int \| null | Total de parcelas |
| `parcela_atual` | int \| null | Parcela atual |

> `makeTransaction()` extrai `1/3` / `Parc 2/10` da descrição, grava em `parcela_*` e **remove** do nome.
> `findOrCreateByNome()` também normaliza — um estabelecimento = um registro; a parcela fica só na transação.
| `valor_parcela` | float \| null | Valor da parcela |
| `tipo` | string | `purchase` \| `payment` \| `refund` \| `advance` \| `fee` |
| `ultimos_digitos` | string(4) opcional | Final detectado no PDF (ex.: PicPay `Card final 7025`) |
| `nome_no_cartao` | string opcional | Nome impresso acima do final (ex.: `LEONARDO S FERREIRA`) |

> No `ProcessInvoicePdfJob`, `ultimos_digitos` resolve/cria `cartao_numeros` **na bandeira da fatura** (`fatura.cartao_bandeira_id`) e grava `transacoes.cartao_numero_id`.

## Dicas por banco

| Banco | Detecção | Observação |
|-------|----------|------------|
| Nubank | `nubank`, `nu pagamentos` | Preferir `-layout`: `05 ABR •••• 7402 LOJA - Parcela 2/10 R$ 143,20`. Final do cartão: máscara na linha (`•••• 7402`) ou cabeçalho `RESUMO 5162 •••• •••• 7495`. Fallback multilinha / legado |
| Itaú | `banco itaú`, `itaú unibanco` | Layout 2 colunas (split ~85); seções `Pagamentos efetuados` / `Lançamentos: compras`; ignora `Compras parceladas`; ano via `Emissão`/`Vencimento`. Final do cartão: `Titular NOME` + `Cartão 4705.XXXX.XXXX.8201` → `8201` |
| Inter | `banco inter`, `conta do inter`, `clientes inter` | PDF `-layout`: `02 de jul. 2026 LOJA (Parcela 01 de 06) R$ 193,19` (`+ R$` = crédito/pagamento). Total: preferir `Total da sua fatura` + frase `precisa pagar` (não confundir com coluna Limite). Final do cartão: `CARTÃO 5364****1668` → `1668` (troca a cada cabeçalho). CSV Inter inalterado |
| C6 | `c6 bank`, `banco c6`, `cartão c6` + transações | Seção `Transações do cartão`; data `10 jun` / `06 nov`; ano via `fechamento ... em DD/MM/YY`; total `Valor da fatura: R$` ou `chegou no valor de R$` |
| PicPay | `picpay bank`, `picpay card` | Layout 2 colunas; `PARC01/03` colado no nome; ano via `Fechamento`; captura `Picpay Card final XXXX` + nome do titular |
| Sofisa | `sofisa direto`, `banco sofisa` | Seção `Detalhamento da Fatura`; data `DD/MM/YY`; parcelas `Parc.5/10`; prefixo `Compra a Vista` removido. Final do cartão: máscara `4563**.******.0236` + nome do titular acima → `0236` |
| Genérico | sempre | Regex ampla de fallback |

> PDFs escaneados (imagem) não geram texto. Use OCR externo antes, ou exija PDF texto.
