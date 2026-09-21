<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\GenericInvoiceParser;
use App\Services\Pdf\Parsers\ItauInvoiceParser;
use PHPUnit\Framework\TestCase;

class ItauInvoiceParserTest extends TestCase
{
    public function test_parse_layout_duas_colunas_itau(): void
    {
        // Espaços preservados: coluna esquerda vs encargos à direita (~coluna 90).
        $text = <<<'TXT'
                                LEONARDO DA SILVA FERREIRA
                                                                             Postagem: 05/07/2026
                                                                            Vencimento: 13/07/2026
                                                                               Emissão: 05/07/2026

           Titular LEONARDO DA SILVA FERREIRA
           Cartão 4705.XXXX.XXXX.8201

    O total da sua fatura é:                                                   Com vencimento em:
    R$ 1.261,25                                                                      13/07/2026

                      Banco Itaú S.A. 341-7 34191758012859651252650484150003315060000126125
                      Nome do Beneficiário/CPF/CNPJ   ITAU UNIBANCO HOLDING S.A.

                 Pagamentos efetuados                                                     Encargos cobrados nesta fatura
                 DATA                                                 VALOR EM R$         Juros do rotativo                               15,10 %         30,20
                 17/06    PAGAMENTO                                      -1.200,00        Juros de mora                                1,00 % am           2,00
                P Total dos pagamentos                                   -1.200,00        Multa por atraso                                 2,00 %         24,00
                                                                                          IOF de financiamento            (0,38 % + 0,00820 % a.d.)        5,05
                                                                                        E Total de encargos em R$                                         61,25
                 Lançamentos: compras e saques
                 LEONARDO DA SILVA FERREIR
                 DATA     ESTABELECIMENTO                             VALOR EM R$         Novo teto de juros do cartão de crédito
                 28/11    PERNAMBUCO MOT 08/10                            1.200,00
                          outros PAULISTA
                 Lançamentos no cartão                                    1.200,00        Credito Rotativo / Atraso
                L Total dos lançamentos atuais                            1.200,00

                 Compras parceladas - próximas faturas                                    Os juros e encargos que você irá pagar são os apresentados na
                 DATA     ESTABELECIMENTO                             VALOR EM R$         contratação, e caso ultrapassem o limite máximo, a diferença não
                 28/11    PERNAMBUCO MOT 09/10                            1.200,00        será cobrada ou será devolvida em fatura. Válido por cada operação
                 Próxima fatura                                           1.200,00
TXT;

        $parser = new ItauInvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertTrue((new GenericInvoiceParser())->supports($text));

        $transactions = $parser->parse($text);

        $this->assertCount(6, $transactions);

        // Encargo da coluna direita aparece na linha do cabeçalho DATA, antes do pagamento.
        $this->assertSame('Juros do rotativo', $transactions[0]['estabelecimento']);
        $this->assertSame(30.2, $transactions[0]['valor']);
        $this->assertSame('fee', $transactions[0]['tipo']);
        $this->assertSame('8201', $transactions[0]['ultimos_digitos']);
        $this->assertSame('LEONARDO DA SILVA FERREIRA', $transactions[0]['nome_no_cartao']);

        $this->assertSame('payment', $transactions[1]['tipo']);
        $this->assertSame('2026-06-17', $transactions[1]['data']);
        $this->assertSame('PAGAMENTO', $transactions[1]['estabelecimento']);
        $this->assertSame(1200.0, $transactions[1]['valor']);
        $this->assertSame('8201', $transactions[1]['ultimos_digitos']);

        $this->assertSame('Juros de mora', $transactions[2]['estabelecimento']);
        $this->assertSame(2.0, $transactions[2]['valor']);
        $this->assertSame('fee', $transactions[2]['tipo']);

        $this->assertSame('Multa por atraso', $transactions[3]['estabelecimento']);
        $this->assertSame(24.0, $transactions[3]['valor']);
        $this->assertSame('fee', $transactions[3]['tipo']);

        $this->assertSame('IOF de financiamento', $transactions[4]['estabelecimento']);
        $this->assertSame(5.05, $transactions[4]['valor']);
        $this->assertSame('fee', $transactions[4]['tipo']);

        $this->assertSame('2025-11-28', $transactions[5]['data']);
        $this->assertSame('PERNAMBUCO MOT outros PAULISTA', $transactions[5]['estabelecimento']);
        $this->assertSame(8, $transactions[5]['parcela_atual']);
        $this->assertSame(10, $transactions[5]['parcelas_total']);
        $this->assertSame(1200.0, $transactions[5]['valor']);
        $this->assertSame('purchase', $transactions[5]['tipo']);
        $this->assertSame('8201', $transactions[5]['ultimos_digitos']);
        $this->assertSame('LEONARDO DA SILVA FERREIRA', $transactions[5]['nome_no_cartao']);
    }

    public function test_parse_valores_alinhados_a_direita_nao_perde_compras(): void
    {
        // Fatura real 09/2026: 1.200,00 cabe à esquerda da coluna 85; 10,00 / 272,16 / 62,50
        // estão nas colunas 81–87 e sumiam com o corte antigo.
        $text = file_get_contents(__DIR__.'/../Fixtures/itau-click-valores-direita.txt');
        $this->assertNotFalse($text);

        $parser = new ItauInvoiceParser();
        $this->assertTrue($parser->supports($text));

        $transactions = $parser->parse($text);
        $purchases = array_values(array_filter(
            $transactions,
            fn (array $t) => $t['tipo'] === 'purchase'
        ));
        $payments = array_values(array_filter(
            $transactions,
            fn (array $t) => $t['tipo'] === 'payment'
        ));

        $this->assertCount(1, $payments);
        $this->assertSame(1200.0, $payments[0]['valor']);
        $this->assertSame('2026-08-12', $payments[0]['data']);

        $this->assertCount(4, $purchases);
        $this->assertSame('PERNAMBUCO MOT outros PAULISTA', $purchases[0]['estabelecimento']);
        $this->assertSame(1200.0, $purchases[0]['valor']);
        $this->assertSame(10, $purchases[0]['parcela_atual']);
        $this->assertSame(10, $purchases[0]['parcelas_total']);

        $this->assertSame('PARK.ME ESTACIONAMENTOU outros UBERLANDIA', $purchases[1]['estabelecimento']);
        $this->assertSame(10.0, $purchases[1]['valor']);
        $this->assertSame('2026-08-25', $purchases[1]['data']);

        $this->assertSame('ECO VIP COMERCIO DE COR outros RECIFE', $purchases[2]['estabelecimento']);
        $this->assertSame(272.16, $purchases[2]['valor']);
        $this->assertSame('2026-08-30', $purchases[2]['data']);

        $this->assertSame('SUPERMERCADO P supermercado CAMARAGIBE', $purchases[3]['estabelecimento']);
        $this->assertSame(62.5, $purchases[3]['valor']);
        $this->assertSame(1, $purchases[3]['parcela_atual']);
        $this->assertSame(2, $purchases[3]['parcelas_total']);
        $this->assertSame('2026-08-31', $purchases[3]['data']);

        $somaCompras = array_sum(array_column($purchases, 'valor'));
        $this->assertEqualsWithDelta(1544.66, $somaCompras, 0.001);
    }

    public function test_parse_secoes_pagamentos_e_lancamentos_texto_linear(): void
    {
        $text = <<<'TXT'
Banco Itaú S.A.
Emissão: 05/09/2026
Pagamentos efetuados
DATA VALOR EM R$
12/08 PAGAMENTO -1.200,00
Total dos pagamentos -1.200,00
Lançamentos: compras e saques
LEONARDO DA SILVA FERREIR
DATA ESTABELECIMENTO VALOR EM R$
28/11 PERNAMBUCO MOT 10/10 1.200,00
outros PAULISTA
25/08 PARK.ME ESTACIONAMENTOU 10,00
outros UBERLANDIA
30/08 ECO VIP COMERCIO DE COR 272,16
outros RECIFE
31/08 SUPERMERCADO P 01/02 62,50
supermercado CAMARAGIBE
Lançamentos no cartão 1.544,66
Total dos lançamentos atuais 1.544,66
TXT;

        $transactions = (new ItauInvoiceParser())->parse($text);
        $purchases = array_values(array_filter(
            $transactions,
            fn (array $t) => $t['tipo'] === 'purchase'
        ));
        $payments = array_values(array_filter(
            $transactions,
            fn (array $t) => $t['tipo'] === 'payment'
        ));

        $this->assertCount(1, $payments);
        $this->assertSame('PAGAMENTO', $payments[0]['estabelecimento']);
        $this->assertSame(1200.0, $payments[0]['valor']);
        $this->assertSame('2026-08-12', $payments[0]['data']);

        $this->assertCount(4, $purchases);
        $this->assertSame('PERNAMBUCO MOT outros PAULISTA', $purchases[0]['estabelecimento']);
        $this->assertSame(1200.0, $purchases[0]['valor']);
        $this->assertSame(10, $purchases[0]['parcela_atual']);
        $this->assertSame(10, $purchases[0]['parcelas_total']);
        $this->assertSame('PARK.ME ESTACIONAMENTOU outros UBERLANDIA', $purchases[1]['estabelecimento']);
        $this->assertSame(10.0, $purchases[1]['valor']);
        $this->assertSame('ECO VIP COMERCIO DE COR outros RECIFE', $purchases[2]['estabelecimento']);
        $this->assertSame(272.16, $purchases[2]['valor']);
        $this->assertSame('SUPERMERCADO P supermercado CAMARAGIBE', $purchases[3]['estabelecimento']);
        $this->assertSame(62.5, $purchases[3]['valor']);
        $this->assertSame(1, $purchases[3]['parcela_atual']);
        $this->assertSame(2, $purchases[3]['parcelas_total']);

        $nomes = array_column($purchases, 'estabelecimento');
        $this->assertFalse(
            (bool) preg_match('/1544|lançamentos no cart/iu', implode(' ', $nomes)),
            'Totais da seção não podem virar compra'
        );
    }

    public function test_nao_detecta_sem_banco_itau(): void
    {
        $text = "17/06 PAGAMENTO -1.200,00\n28/11 LOJA 01/02 10,00\n";
        $this->assertFalse((new ItauInvoiceParser())->supports($text));
    }
}
