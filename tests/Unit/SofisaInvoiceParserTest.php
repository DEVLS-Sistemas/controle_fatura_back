<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\GenericInvoiceParser;
use App\Services\Pdf\Parsers\SofisaInvoiceParser;
use PHPUnit\Framework\TestCase;

class SofisaInvoiceParserTest extends TestCase
{
    public function test_parse_detalhamento_sofisa_direto(): void
    {
        $text = <<<'TXT'
Olá, LEONARDO DA SILVA FERREIRA
Chegou a fatura com as compras e pagamentos feitos até 05/06/2026 com o seu cartão SOFISA DIRETO VISA.

     Total a pagar                               Vencimento:
   R$ 2.275,10                                10/06/2026

Detalhamento da Fatura                                                                                                                        Fechamento da próxima fatura 03/07/2026

LEONARDO S FERREIRA
4563**.******.0236
Data          Descricao                                                                                                  Valor Original        Valor Equivalente               Taxa da Conversão R$            Valor em Real
08/01/26      SHOPEE*SHOPEE*MA Parc.5/10                                                                                                                                                                          427,95
18/01/26      JIM.COM EMERSON FERREIRA Parc.5/5                                                                                                                                                                 1.081,90
12/02/26      PG *NUVEM VOOLT3D Parc.4/10                                                                                                                                                                          82,54
11/05/26      Pagamento de Fatura                                                                                                                                                                              -2.737,46
11/05/26      Compra a Vista BRADESCO AUT*06DE10                                                                                                                                                                  232,40
16/05/26      Compra a Vista CLARO FLEX                                                                                                                                                                            39,99
04/06/26      NOVO CAETES MATERIAIS Parc.1/6                                                                                                                                                                       59,05
LEONARDO S FERREIRA
4563**.******.8754
Data          Descricao                                                                                                  Valor Original        Valor Equivalente               Taxa da Conversão R$            Valor em Real
09/02/26      PG *NUVEM VOOLT3D Parc.4/9                                                                                                                                                                            74,58

VALOR TOTAL DA FATURA                                                                                                                                                                                           2.275,10
TXT;

        $parser = new SofisaInvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertTrue((new GenericInvoiceParser())->supports($text));

        $transactions = $parser->parse($text);
        $this->assertCount(8, $transactions);

        $this->assertSame('2026-01-08', $transactions[0]['data']);
        $this->assertSame('SHOPEE*SHOPEE*MA', $transactions[0]['estabelecimento']);
        $this->assertSame(5, $transactions[0]['parcela_atual']);
        $this->assertSame(10, $transactions[0]['parcelas_total']);
        $this->assertSame(427.95, $transactions[0]['valor']);
        $this->assertSame('purchase', $transactions[0]['tipo']);
        $this->assertSame('0236', $transactions[0]['ultimos_digitos']);
        $this->assertSame('LEONARDO S FERREIRA', $transactions[0]['nome_no_cartao']);

        $this->assertSame('JIM.COM EMERSON FERREIRA', $transactions[1]['estabelecimento']);
        $this->assertSame(5, $transactions[1]['parcela_atual']);
        $this->assertSame(5, $transactions[1]['parcelas_total']);
        $this->assertSame(1081.9, $transactions[1]['valor']);
        $this->assertSame('0236', $transactions[1]['ultimos_digitos']);

        $this->assertSame('payment', $transactions[3]['tipo']);
        $this->assertSame('Pagamento de Fatura', $transactions[3]['estabelecimento']);
        $this->assertSame(2737.46, $transactions[3]['valor']);
        $this->assertSame('0236', $transactions[3]['ultimos_digitos']);

        $this->assertSame('BRADESCO AUT*06DE10', $transactions[4]['estabelecimento']);
        $this->assertSame(232.4, $transactions[4]['valor']);
        $this->assertNull($transactions[4]['parcela_atual']);
        $this->assertSame('0236', $transactions[4]['ultimos_digitos']);

        $this->assertSame('CLARO FLEX', $transactions[5]['estabelecimento']);
        $this->assertSame('0236', $transactions[5]['ultimos_digitos']);

        $this->assertSame('2026-02-09', $transactions[7]['data']);
        $this->assertSame('PG *NUVEM VOOLT3D', $transactions[7]['estabelecimento']);
        $this->assertSame(4, $transactions[7]['parcela_atual']);
        $this->assertSame(9, $transactions[7]['parcelas_total']);
        $this->assertSame(74.58, $transactions[7]['valor']);
        $this->assertSame('8754', $transactions[7]['ultimos_digitos']);
        $this->assertSame('LEONARDO S FERREIRA', $transactions[7]['nome_no_cartao']);
    }

    public function test_parse_data_com_ano_completo_e_valor_na_ultima_coluna(): void
    {
        $text = <<<'TXT'
SOFISA DIRETO VISA
Detalhamento da Fatura
08/01/2026 SHOPEE*SHOPEE*MA Parc.5/10 1.200,00 427,95
VALOR TOTAL DA FATURA 427,95
TXT;

        $transactions = (new SofisaInvoiceParser())->parse($text);

        $this->assertCount(1, $transactions);
        $this->assertSame('2026-01-08', $transactions[0]['data']);
        $this->assertSame('SHOPEE*SHOPEE*MA', $transactions[0]['estabelecimento']);
        $this->assertSame(427.95, $transactions[0]['valor']);
        $this->assertSame(5, $transactions[0]['parcela_atual']);
        $this->assertSame(10, $transactions[0]['parcelas_total']);
    }

    public function test_nao_detecta_sem_sofisa(): void
    {
        $text = "Detalhamento da Fatura\n08/01/26 LOJA Parc.1/2 10,00\n";
        $this->assertFalse((new SofisaInvoiceParser())->supports($text));
    }

    public function test_parse_mastercard_data_sem_ano_e_parcela_colada(): void
    {
        $text = <<<'TXT'
Nome do titular LEONARDO DA SILVA FERREIRA

Olá, LEONARDO chegou a fatura com                                            Total a Pagar                    Vencimento
                                                                              R$ 162,04                       10/09/2026
as compras e pagamentos feitos até
01/09/2026 com o seu cartão SOFISA
                                                                          Pagamento mínimo              Melhor dia para compra
DIRETO MASTERCARD.                                                             R$ 24,31                       02/09/2026

(+) Total a Pagar                                         162,04

Detalhamento da Fatura
Despesas Cartão - 0217                                  R$ 162,04

Data         Transações                Moeda Original    Valor (R$)

15/11        ComercialDe 10/12                                13,54
06/12        CASA MAE CAMARAGIB09/10                         148,50
10/08        PAGAMENTO DE FATURA                            -167,84
Atenção: em caso de pagamento inferior ao valor total
01/09/2026                       04661947                   CC
TXT;

        $parser = new SofisaInvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertSame(['mes' => 9, 'ano' => 2026], $parser->extractPeriod($text));

        $transactions = $parser->parse($text);
        $this->assertCount(3, $transactions);

        $this->assertSame('2025-11-15', $transactions[0]['data']);
        $this->assertSame('ComercialDe', $transactions[0]['estabelecimento']);
        $this->assertSame(13.54, $transactions[0]['valor']);
        $this->assertSame(10, $transactions[0]['parcela_atual']);
        $this->assertSame(12, $transactions[0]['parcelas_total']);
        $this->assertSame('0217', $transactions[0]['ultimos_digitos']);
        $this->assertSame('LEONARDO DA SILVA FERREIRA', $transactions[0]['nome_no_cartao']);

        $this->assertSame('2025-12-06', $transactions[1]['data']);
        $this->assertSame('CASA MAE CAMARAGIB', $transactions[1]['estabelecimento']);
        $this->assertSame(148.5, $transactions[1]['valor']);
        $this->assertSame(9, $transactions[1]['parcela_atual']);
        $this->assertSame(10, $transactions[1]['parcelas_total']);

        $this->assertSame('2026-08-10', $transactions[2]['data']);
        $this->assertSame('payment', $transactions[2]['tipo']);
        $this->assertSame('PAGAMENTO DE FATURA', $transactions[2]['estabelecimento']);
        $this->assertSame(167.84, $transactions[2]['valor']);
    }
}
