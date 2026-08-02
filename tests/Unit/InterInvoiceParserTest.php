<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\InterInvoiceParser;
use App\Services\Pdf\Parsers\ItauInvoiceParser;
use PHPUnit\Framework\TestCase;

class InterInvoiceParserTest extends TestCase
{
    public function test_parse_layout_atual_inter(): void
    {
        $text = <<<'TXT'
Olá, Leonardo! A sua fatura chegou!
Banco Inter
Clientes Inter Digital e Inter One
Fatura atual R$ 6.137,69

Despesas da fatura

CARTÃO 5364****1668
Data Movimentação Beneficiário Valor

12 de jun. 2026 PAGTO DEBITO AUTOMATICO - + R$ 5.956,84
17 de jun. 2026 IOF CREDITO PARCELADO - R$ 16,56
02 de jul. 2026 RI HAPPY (Parcela 01 de 06) - R$ 193,19
02 de jul. 2026 Shopee*62249994 GABRIE - + R$ 179,95
03 de abr. 2026 PIX CRED PARCELADO (Parcela 04 de 04) EIA MARIA DA S R$ 8,46

Total CARTÃO 5364****1668 R$ 550,17
TXT;

        $parser = new InterInvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertFalse((new ItauInvoiceParser())->supports($text));

        $transactions = $parser->parse($text);
        $this->assertCount(5, $transactions);

        $this->assertSame('payment', $transactions[0]['tipo']);
        $this->assertSame('2026-06-12', $transactions[0]['data']);
        $this->assertSame(5956.84, $transactions[0]['valor']);

        $this->assertSame('purchase', $transactions[1]['tipo']);
        $this->assertSame(16.56, $transactions[1]['valor']);

        $this->assertSame('RI HAPPY', $transactions[2]['estabelecimento']);
        $this->assertSame(1, $transactions[2]['parcela_atual']);
        $this->assertSame(6, $transactions[2]['parcelas_total']);
        $this->assertSame(193.19, $transactions[2]['valor']);

        $this->assertSame('refund', $transactions[3]['tipo']);
        $this->assertSame(179.95, $transactions[3]['valor']);

        $this->assertSame('PIX CRED PARCELADO', $transactions[4]['estabelecimento']);
        $this->assertSame(4, $transactions[4]['parcela_atual']);
        $this->assertSame(4, $transactions[4]['parcelas_total']);
    }

    public function test_nao_detecta_itau_por_lancamento_parc_itau(): void
    {
        $text = "Banco Inter\nDespesas da fatura\nClientes Inter Digital\n"
            . "10 de jun. 2026 BOLETO CRED PARC ITAU U (Parcela 02 de 04) - R$ 10,00\n";

        $this->assertTrue((new InterInvoiceParser())->supports($text));
        $this->assertFalse((new ItauInvoiceParser())->supports($text));
    }
}
