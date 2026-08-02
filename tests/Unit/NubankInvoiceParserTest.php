<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\NubankInvoiceParser;
use PHPUnit\Framework\TestCase;

class NubankInvoiceParserTest extends TestCase
{
    public function test_parse_layout_linha_unica(): void
    {
        $text = <<<'TXT'
Olá, Leonardo.
Esta é a sua fatura Nubank de
maio, no valor de
R$ 899,02

Data de vencimento: 12 MAI 2026
Período vigente: 05 ABR a 05 MAI

TRANSAÇÕES DE 05 ABR A 05 MAI

Leonardo Silva R$ 2.255,50

05 ABR •••• 6921 Jim.Com* Emerson Ferr - Parcela 2/5 R$ 692,41
05 ABR •••• 6921 Ferreira Costa - Parcela 7/7 R$ 57,12
05 ABR •••• 7402 Mercadolivre*Hscpqtec - Parcela 11/12 R$ 128,94
06 ABR •••• 6921 Mercado Xavier R$ 28,00
08 ABR Estorno de "Mercadolivre*Paulista" −R$ 19,98
01 MAI •••• 7402 Amazon - Parcela 1/6 R$ 35,21

Pagamentos -R$ 3.637,43

08 ABR Pagamento em 08 ABR −R$ 2.260,97
28 ABR Pagamento em 28 ABR −R$ 55,21
TXT;

        $parser = new NubankInvoiceParser();
        $this->assertTrue($parser->supports($text));

        $transactions = $parser->parse($text);

        $this->assertCount(8, $transactions);

        $this->assertSame('Jim.Com* Emerson Ferr', $transactions[0]['estabelecimento']);
        $this->assertSame(692.41, $transactions[0]['valor']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(5, $transactions[0]['parcelas_total']);

        $this->assertSame('Ferreira Costa', $transactions[1]['estabelecimento']);
        $this->assertSame(57.12, $transactions[1]['valor']);

        $this->assertSame('refund', $transactions[4]['tipo']);
        $this->assertSame(19.98, $transactions[4]['valor']);
        $this->assertSame('Estorno de "Mercadolivre*Paulista"', $transactions[4]['estabelecimento']);

        $this->assertSame('Amazon', $transactions[5]['estabelecimento']);
        $this->assertSame(35.21, $transactions[5]['valor']);

        $this->assertSame('payment', $transactions[6]['tipo']);
        $this->assertSame(2260.97, $transactions[6]['valor']);
        $this->assertSame('payment', $transactions[7]['tipo']);
        $this->assertSame(55.21, $transactions[7]['valor']);
    }

    public function test_parse_layout_legado_uma_linha(): void
    {
        $text = "Nubank\nFatura de abril de 2019\n15 MAR SUPERMERCADO ABC 123,45\n16/03 Pagamento recebido -522,00\n";

        $transactions = (new NubankInvoiceParser())->parse($text);

        $this->assertCount(2, $transactions);
        $this->assertSame('SUPERMERCADO ABC', $transactions[0]['estabelecimento']);
        $this->assertSame(123.45, $transactions[0]['valor']);
        $this->assertSame('payment', $transactions[1]['tipo']);
    }

    public function test_parse_multiline_com_valores_atrasados_por_coluna(): void
    {
        $text = <<<'TXT'
Nubank
Data de vencimento: 12 MAI 2026
TRANSAÇÕES
05 ABR
•••• 6921
Jim.Com* Emerson Ferr - Parcela 2/5
05 ABR
•••• 6921
Ferreira Costa - Parcela 7/7
05 ABR
•••• 7402
Mercadolivre*Hscpqtec - Parcela 11/12
R$ 128,94
08 ABR
Estorno de "Mercadolivre*Paulista"
R$ 692,41
R$ 57,12
−R$ 19,98
Estorno referente a compra em Mercadolivre*Paulista, de valor R$ 19,98,
realizada em 19 de Março de 2026
TXT;

        $transactions = (new NubankInvoiceParser())->parse($text);
        $byName = [];
        foreach ($transactions as $tx) {
            $byName[$tx['estabelecimento']] = $tx;
        }

        $this->assertCount(4, $transactions);
        $this->assertSame(692.41, $byName['Jim.Com* Emerson Ferr']['valor']);
        $this->assertSame(57.12, $byName['Ferreira Costa']['valor']);
        $this->assertSame(128.94, $byName['Mercadolivre*Hscpqtec']['valor']);
        $this->assertSame('refund', $byName['Estorno de "Mercadolivre*Paulista"']['tipo']);
        $this->assertSame(19.98, $byName['Estorno de "Mercadolivre*Paulista"']['valor']);
    }
}
