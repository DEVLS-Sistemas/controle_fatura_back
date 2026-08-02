<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\GenericInvoiceParser;
use App\Services\Pdf\Parsers\ItauInvoiceParser;
use PHPUnit\Framework\TestCase;

class ItauInvoiceParserTest extends TestCase
{
    public function test_parse_layout_duas_colunas_itau(): void
    {
        // Espaços preservados: coluna esquerda (~85 chars) vs encargos à direita.
        $text = <<<'TXT'
                                LEONARDO DA SILVA FERREIRA
                                                                             Postagem: 05/07/2026
                                                                            Vencimento: 13/07/2026
                                                                               Emissão: 05/07/2026

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
        $this->assertSame('purchase', $transactions[0]['tipo']);

        $this->assertSame('payment', $transactions[1]['tipo']);
        $this->assertSame('2026-06-17', $transactions[1]['data']);
        $this->assertSame('PAGAMENTO', $transactions[1]['estabelecimento']);
        $this->assertSame(1200.0, $transactions[1]['valor']);

        $this->assertSame('Juros de mora', $transactions[2]['estabelecimento']);
        $this->assertSame(2.0, $transactions[2]['valor']);

        $this->assertSame('Multa por atraso', $transactions[3]['estabelecimento']);
        $this->assertSame(24.0, $transactions[3]['valor']);

        $this->assertSame('IOF de financiamento', $transactions[4]['estabelecimento']);
        $this->assertSame(5.05, $transactions[4]['valor']);

        $this->assertSame('2025-11-28', $transactions[5]['data']);
        $this->assertSame('PERNAMBUCO MOT outros PAULISTA', $transactions[5]['estabelecimento']);
        $this->assertSame(8, $transactions[5]['parcela_atual']);
        $this->assertSame(10, $transactions[5]['parcelas_total']);
        $this->assertSame(1200.0, $transactions[5]['valor']);
        $this->assertSame('purchase', $transactions[5]['tipo']);
    }

    public function test_nao_detecta_sem_banco_itau(): void
    {
        $text = "17/06 PAGAMENTO -1.200,00\n28/11 LOJA 01/02 10,00\n";
        $this->assertFalse((new ItauInvoiceParser())->supports($text));
    }
}
