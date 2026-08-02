<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\PicPayInvoiceParser;
use PHPUnit\Framework\TestCase;

class PicPayInvoiceParserTest extends TestCase
{
    public function test_parse_layout_duas_colunas_com_parc_colada(): void
    {
        $text = <<<'TXT'
Vencimento: 10-05-2025 | Fechamento: 29-04-2025
Mastercard® GOLD
PicPay Bank Banco Múltiplo S.A.
Total da fatura 3.649,16

Picpay Card final 7025
Picpay Card final 7033
Transações Nacionais

Data Estabelecimento Valor Transações Nacionais
21/03 PAG*REISNET PARC14/18 206,89 Data Estabelecimento Valor
08/04 INVESTGAS LOCACAO E IN 45,99 11/11 MP *EDIFIER PARC06/10 20,81
08/04 RADCLIN PARC01/02 75,00 11/11 SHOPEE *KABCOMPARC06/07 62,59
11/04 ATACADAO 152 APARC01/03 359,83 14/04 99PAY *PIX PARC01/03 837,98
19/04 PETALA PERFUMEPARC01/02 21,45
19/04 SUPERMERCADO PONTO CE 23,07
Subtotal dos lançamentos 1.619,36
24/04 POSTO ARECIFE 100,00
Total geral dos lançamentos 3.649,16
Subtotal dos lançamentos 2.029,80
TXT;

        $parser = new PicPayInvoiceParser();
        $this->assertTrue($parser->supports($text));

        $transactions = $parser->parse($text);
        $this->assertCount(10, $transactions);

        $this->assertSame('2025-03-21', $transactions[0]['data']);
        $this->assertSame('PAG*REISNET', $transactions[0]['estabelecimento']);
        $this->assertSame(14, $transactions[0]['parcela_atual']);
        $this->assertSame(18, $transactions[0]['parcelas_total']);
        $this->assertSame(206.89, $transactions[0]['valor']);

        $this->assertSame('2025-04-08', $transactions[1]['data']);
        $this->assertSame('INVESTGAS LOCACAO E IN', $transactions[1]['estabelecimento']);
        $this->assertSame(45.99, $transactions[1]['valor']);

        // Ano anterior quando mês da compra > mês de fechamento
        $this->assertSame('2024-11-11', $transactions[2]['data']);
        $this->assertSame('MP *EDIFIER', $transactions[2]['estabelecimento']);
        $this->assertSame(6, $transactions[2]['parcela_atual']);
        $this->assertSame(10, $transactions[2]['parcelas_total']);

        $this->assertSame('SHOPEE *KABCOM', $transactions[4]['estabelecimento']);
        $this->assertSame(6, $transactions[4]['parcela_atual']);
        $this->assertSame(7, $transactions[4]['parcelas_total']);

        $this->assertSame('ATACADAO 152 A', $transactions[5]['estabelecimento']);
        $this->assertSame(1, $transactions[5]['parcela_atual']);
        $this->assertSame(3, $transactions[5]['parcelas_total']);
        $this->assertSame(359.83, $transactions[5]['valor']);

        $this->assertSame('99PAY *PIX', $transactions[6]['estabelecimento']);
        $this->assertSame(837.98, $transactions[6]['valor']);

        $this->assertSame('PETALA PERFUME', $transactions[7]['estabelecimento']);
        $this->assertSame(1, $transactions[7]['parcela_atual']);
        $this->assertSame(2, $transactions[7]['parcelas_total']);

        $this->assertSame('SUPERMERCADO PONTO CE', $transactions[8]['estabelecimento']);
        $this->assertSame('POSTO ARECIFE', $transactions[9]['estabelecimento']);
    }

    public function test_supports_exige_contexto_picpay(): void
    {
        $this->assertFalse((new PicPayInvoiceParser())->supports("Picpay*wc5 Joycesilv\nParcela 1/1"));
        $this->assertTrue((new PicPayInvoiceParser())->supports("PicPay Bank\nTransações Nacionais\n"));
    }
}
