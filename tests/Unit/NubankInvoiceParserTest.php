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

RESUMO 5162 •••• •••• 7495

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
        $this->assertSame('6921', $transactions[0]['ultimos_digitos']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(5, $transactions[0]['parcelas_total']);

        $this->assertSame('Ferreira Costa', $transactions[1]['estabelecimento']);
        $this->assertSame(57.12, $transactions[1]['valor']);
        $this->assertSame('6921', $transactions[1]['ultimos_digitos']);

        $this->assertSame('7402', $transactions[2]['ultimos_digitos']);
        $this->assertSame('6921', $transactions[3]['ultimos_digitos']);

        $this->assertSame('refund', $transactions[4]['tipo']);
        $this->assertSame(19.98, $transactions[4]['valor']);
        $this->assertSame('Estorno de "Mercadolivre*Paulista"', $transactions[4]['estabelecimento']);
        // Sem máscara na linha: herda o final do RESUMO.
        $this->assertSame('7495', $transactions[4]['ultimos_digitos']);

        $this->assertSame('Amazon', $transactions[5]['estabelecimento']);
        $this->assertSame(35.21, $transactions[5]['valor']);
        $this->assertSame('7402', $transactions[5]['ultimos_digitos']);

        $this->assertSame('payment', $transactions[6]['tipo']);
        $this->assertSame(2260.97, $transactions[6]['valor']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[6]);
        $this->assertSame('payment', $transactions[7]['tipo']);
        $this->assertSame(55.21, $transactions[7]['valor']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[7]);
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
        $this->assertSame('6921', $byName['Jim.Com* Emerson Ferr']['ultimos_digitos']);
        $this->assertSame(57.12, $byName['Ferreira Costa']['valor']);
        $this->assertSame('6921', $byName['Ferreira Costa']['ultimos_digitos']);
        $this->assertSame(128.94, $byName['Mercadolivre*Hscpqtec']['valor']);
        $this->assertSame('7402', $byName['Mercadolivre*Hscpqtec']['ultimos_digitos']);
        $this->assertSame('refund', $byName['Estorno de "Mercadolivre*Paulista"']['tipo']);
        $this->assertSame(19.98, $byName['Estorno de "Mercadolivre*Paulista"']['valor']);
    }

    public function test_parse_pagamentos_e_financiamentos_sem_herdar_cartao(): void
    {
        $text = <<<'TXT'
Nubank
Data de vencimento: 21 AGO 2026
TRANSAÇÕES DE 14 JUL A 14 AGO
14 JUL •••• 9627 Atacadao *Super R$ 60,49
Pagamentos e Financiamentos -R$ 1.463,00
15 JUL Pagamento em 15 JUL −R$ 217,99
21 JUL Saldo restante da fatura anterior R$ 0,00
22 JUL Thaís Araújo da Silva R$ 52,96
04 AGO Pagamento em 04 AGO −R$ 51,00
TXT;

        $transactions = (new NubankInvoiceParser())->parse($text);

        $this->assertSame('Atacadao *Super', $transactions[0]['estabelecimento']);
        $this->assertSame('9627', $transactions[0]['ultimos_digitos']);
        $this->assertSame('purchase', $transactions[0]['tipo']);

        $this->assertSame('payment', $transactions[1]['tipo']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[1]);

        $this->assertSame('carryover', $transactions[2]['tipo']);
        $this->assertSame('Saldo restante da fatura anterior', $transactions[2]['estabelecimento']);
        $this->assertSame(0.0, $transactions[2]['valor']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[2]);

        $this->assertSame('purchase', $transactions[3]['tipo']);
        $this->assertSame('Thaís Araújo da Silva', $transactions[3]['estabelecimento']);
        $this->assertSame(52.96, $transactions[3]['valor']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[3]);

        $this->assertSame('payment', $transactions[4]['tipo']);
        $this->assertSame(51.0, $transactions[4]['valor']);
    }

    public function test_parse_usa_final_do_resumo_quando_linha_nao_tem_mascara(): void
    {
        $text = <<<'TXT'
Nubank
Data de vencimento: 12 MAI 2026
RESUMO 5162 •••• •••• 7495
TRANSAÇÕES DE 05 ABR A 05 MAI
08 ABR Estorno de "Loja X" −R$ 10,00
08 ABR Pagamento em 08 ABR −R$ 100,00
TXT;

        $transactions = (new NubankInvoiceParser())->parse($text);

        $this->assertCount(2, $transactions);
        $this->assertSame('7495', $transactions[0]['ultimos_digitos']);
        $this->assertSame('7495', $transactions[1]['ultimos_digitos']);
    }

    public function test_parse_layout_antigo_sem_cifrao_usa_resumo(): void
    {
        $text = <<<'TXT'
Nu Pagamentos S.A.
FATURA 20 MAI 2019
RESUMO 5162 •••• •••• 7495 VALORES EM R$
TRANSAÇÕES DE 13 ABR A 13 MAI VALORES EM R$
13 ABR e C de Medeiros - 2/4 49,22
13 ABR Atacado dos Presentes - 2/3 15,03
16 ABR Pagamento em 16 ABR 522,00
17 ABR Pag*Tataamazonas 20,00
TXT;

        $transactions = (new NubankInvoiceParser())->parse($text);

        $this->assertCount(4, $transactions);
        $this->assertSame('e C de Medeiros', $transactions[0]['estabelecimento']);
        $this->assertSame('7495', $transactions[0]['ultimos_digitos']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(4, $transactions[0]['parcelas_total']);
        $this->assertSame('7495', $transactions[1]['ultimos_digitos']);
        $this->assertSame('payment', $transactions[2]['tipo']);
        $this->assertSame('7495', $transactions[2]['ultimos_digitos']);
        $this->assertSame('7495', $transactions[3]['ultimos_digitos']);
    }

    public function test_supports_layout_sem_nubank_no_cumprimento(): void
    {
        $text = <<<'TXT'
Olá, Leonardo.
Esta é a sua fatura de
abril, no valor de
R$ 2.280,95

Data de vencimento: 13 ABR 2026
Nu Pagamentos S.A.
O Nubank declara, nos termos da Lei 12.007 2009
TXT;

        $this->assertTrue((new NubankInvoiceParser())->supports($text));
    }

    public function test_parse_layout_atual_transacoes_com_espacos_e_mascara(): void
    {
        $text = <<<'TXT'
Olá, Leonardo.
Esta é a sua fatura de
abril, no valor de
R$ 2.280,95

Data de vencimento: 13 ABR 2026
Nu Pagamentos S.A.

TRANSAÇÕES      DE 05 MAR A 05 ABR

              05 MAR      •••• 6921   Atacadao 152 As - Parcela 2/2                                               R$ 95,32
              05 MAR      •••• 7402   Magalu *Magalu - Parcela 10/10                                             R$ 664,90
              19 MAR      •••• 7402   Mercadolivre*Brunogom                                                      R$ 538,00
              26 MAR                  Pagamento em 26 MAR                                                       −R$ 550,00
TXT;

        $parser = new NubankInvoiceParser();
        $this->assertTrue($parser->supports($text));

        $transactions = $parser->parse($text);
        $this->assertCount(4, $transactions);

        $this->assertSame('Atacadao 152 As', $transactions[0]['estabelecimento']);
        $this->assertSame(95.32, $transactions[0]['valor']);
        $this->assertSame('6921', $transactions[0]['ultimos_digitos']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(2, $transactions[0]['parcelas_total']);

        $this->assertSame('7402', $transactions[1]['ultimos_digitos']);
        $this->assertSame(664.90, $transactions[1]['valor']);

        $this->assertSame('Mercadolivre*Brunogom', $transactions[2]['estabelecimento']);
        $this->assertSame('7402', $transactions[2]['ultimos_digitos']);

        $this->assertSame('payment', $transactions[3]['tipo']);
        $this->assertSame(550.0, $transactions[3]['valor']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[3]);
    }
}
