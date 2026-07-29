# Adaptando o Parser de PDF por Banco

O sistema extrai texto do PDF com `spatie/pdf-to-text` (`pdftotext`) e escolhe o primeiro parser cujo método `supports()` retornar `true`.

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
    new BradescoInvoiceParser(), // ← novo
    new GenericInvoiceParser(),
];
```

## Fluxo recomendado de adaptação

1. Faça upload de uma fatura real e capture o texto bruto (`pdftotext fatura.pdf -` ou log temporário em `parseFile`).
2. Identifique o padrão de linha (data + descrição + valor).
3. Ajuste a regex no parser específico.
4. Trate parcelas (`03/12`, `Parc 3/12`) com `$this->parseInstallment()`.
5. Valide tipos (`payment`, `refund`, `advance`) via `$this->detectType()` ou override.

## Formato padronizado de saída

Cada item retornado por `parse()` deve ter:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `data` | `Y-m-d` \| null | Data da compra |
| `estabelecimento` | string | Descrição |
| `valor` | float | Valor absoluto |
| `parcelas_total` | int \| null | Total de parcelas |
| `parcela_atual` | int \| null | Parcela atual |
| `valor_parcela` | float \| null | Valor da parcela |
| `tipo` | string | `purchase` \| `payment` \| `refund` \| `advance` |

## Dicas por banco

| Banco | Detecção | Observação |
|-------|----------|------------|
| Nubank | `nubank`, `nu pagamentos` | Datas `15 MAR` ou `15/03` |
| Itaú | `itaú`, `itau` | Layout tabular clássico |
| Inter | `banco inter`, `inter pagamentos` | Linhas `DD/MM DESC VALOR` |
| C6 | `c6 bank`, `c6bank` | Mistura `15 MAR` e `DD/MM` |
| Genérico | sempre | Regex ampla de fallback |

> PDFs escaneados (imagem) não geram texto. Use OCR externo antes, ou exija PDF texto.
