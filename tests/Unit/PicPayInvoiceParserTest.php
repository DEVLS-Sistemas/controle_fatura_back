<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\PicPayInvoiceParser;
use App\Services\Pdf\Parsers\SofisaInvoiceParser;
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
        $this->assertSame('7033', $transactions[0]['ultimos_digitos']);

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

    public function test_parse_agrupa_por_final_e_nome_no_cartao(): void
    {
        $text = <<<'TXT'
Vencimento: 10-07-2026 | Fechamento: 29-06-2026
PicPay Bank Banco Múltiplo S.A.
Total da fatura 2.271,47

Transações Nacionais
Data Estabelecimento Valor (R$)
10/06 PAGAMENTO DE FATURA -1.530,27
LEONARDO S FERREIRA
Picpay Card final 7025
Transações Nacionais
Data Estabelecimento Valor (R$)
28/11 PERNAMBUCO MOTPARC08/10 1.190,00
Subtotal dos lançamentos 1.190,00
LEONARDO S FERREIRA
Picpay Card final 7033
Transações Nacionais
Data Estabelecimento Valor (R$)
01/06 MP *ALIEXPRESSPARC02/12 340,26
06/06 AMAZON MARKETPPARC01/12 240,36
Subtotal dos lançamentos 1.081,47
Total geral dos lançamentos 2.271,47
TXT;

        $parser = new PicPayInvoiceParser();
        $transactions = $parser->parse($text);

        $this->assertCount(4, $transactions);

        // Pagamento aparece antes do cabeçalho do final 7025 → ainda sem final
        $this->assertSame('PAGAMENTO DE FATURA', $transactions[0]['estabelecimento']);
        $this->assertArrayNotHasKey('ultimos_digitos', $transactions[0]);

        $this->assertSame('PERNAMBUCO MOT', $transactions[1]['estabelecimento']);
        $this->assertSame('7025', $transactions[1]['ultimos_digitos']);
        $this->assertSame('LEONARDO S FERREIRA', $transactions[1]['nome_no_cartao']);
        $this->assertSame(8, $transactions[1]['parcela_atual']);
        $this->assertSame(10, $transactions[1]['parcelas_total']);

        $this->assertSame('MP *ALIEXPRESS', $transactions[2]['estabelecimento']);
        $this->assertSame('7033', $transactions[2]['ultimos_digitos']);
        $this->assertSame('LEONARDO S FERREIRA', $transactions[2]['nome_no_cartao']);

        $this->assertSame('AMAZON MARKETP', $transactions[3]['estabelecimento']);
        $this->assertSame('7033', $transactions[3]['ultimos_digitos']);
        $this->assertSame('LEONARDO S FERREIRA', $transactions[3]['nome_no_cartao']);
    }

    public function test_parse_coluna_direita_antes_de_picpay_card_vira_parcela(): void
    {
        $text = <<<'TXT'
PicPay Bank Banco Múltiplo S.A.
Vencimento: 10/09/2026 | Fechamento: 03/09/2026
Esta é a sua fatura de Setembro.
Total da fatura                                      R$ 2.288,25

                                                   16/06    MP *ALIEXPRESSPARC03/03                                137,57
Picpay Card

Tarifas                                             Subtotal dos lançamentos                                 1.023,39
Data      Operação                  Valor (R$)
03/09     JUROS CREDITO ROTATIVO           17,56
03/09     JUROS DE MORA                    0,93    Total geral dos lançamentos                                2.288,25
03/09     MULTA POR ATRASO                56,37

Transações Nacionais
Data      Estabelecimento           Valor (R$)
11/08     PAGAMENTO DE FATURA          -2.818,50

LEONARDO S FERREIRA
Picpay Card final 7025
Transações Nacionais
Data      Estabelecimento           Valor (R$)
28/11     PERNAMBUCO MOTPARC10/10       1.190,00

LEONARDO S FERREIRA
Picpay Card final 7033
Transações Nacionais
Data      Estabelecimento           Valor (R$)
01/06     MP *ALIEXPRESSPARC04/12        340,26
06/06     AMAZON MARKETPPARC03/12       240,30
08/06     MP *ALIEXPRESSPARC03/12        225,36
11/06     HTM*STLFLIX PRPARC03/12         79,90
TXT;

        $parser = new PicPayInvoiceParser();
        $this->assertTrue($parser->supports($text));

        $transactions = $parser->parse($text);
        $byKey = [];
        foreach ($transactions as $tx) {
            $byKey[$tx['estabelecimento'] . '|' . number_format($tx['valor'], 2, '.', '')] = $tx;
        }

        $this->assertArrayHasKey('MP *ALIEXPRESS|137.57', $byKey);
        $parcela = $byKey['MP *ALIEXPRESS|137.57'];
        $this->assertSame('purchase', $parcela['tipo']);
        $this->assertSame('fee', $byKey['JUROS CREDITO ROTATIVO|17.56']['tipo']);
        $this->assertSame(3, $parcela['parcela_atual']);
        $this->assertSame(3, $parcela['parcelas_total']);
        $this->assertSame('2026-06-16', $parcela['data']);

        $comprasETaxas = 0.0;
        foreach ($transactions as $tx) {
            if (in_array($tx['tipo'], ['purchase', 'fee'], true)) {
                $comprasETaxas += $tx['valor'];
            }
        }
        $this->assertEqualsWithDelta(2288.25, $comprasETaxas, 0.001);
    }

    public function test_parse_layout_antigo_mes_abreviado_ignora_boleto(): void
    {
        $text = <<<'TXT'
LEONARDO DA SILVA FERREIRA
Fatura 20 JUN 2021 | Data de fechamento 10 JUN 2021
Total da fatura                                                                 301,71
Vencimento em                           Pagamento total         Pagamento Mínimo
20/06/2021                                 R$ 301,71                R$ 38,74

Transações                                           Valores em U$          Valores em R$
13 mai PAGAMENTO DE FATURA PELO PICPA                                                -288,28
16 mai INVESTGAS LTDA                                                                   27,50
20 mai PAGAMENTO ENTRE PESSOAS                                                        100,00
20 mai INVESTGAS LTDA                                                                  43,99
11 jan MERCPAGO*ASOUNPARC05/10                                                       15,25
27 abr PAG*LETICIAMODPARC02/03                                                          44,18
15 mai P L DA SILVA SUPERMERC                                                            8,99
15 mai MP *ACAI                                                                         19,00
17 mai MAGALU *MAGAZIPARC01/03                                                         26,60
19 mai POSTO GERACAO                                                                     11,20
30 mai FARMAQUALY                                                                        5,00
TOTAL GERAL DOS LANCAMENTOS R$                                               301,71

Encargos e Custo Efetivo Total (CET) válidos para o período atual
Data do processamento                           Data do documento                                      Mora/Multa
11/06/2021                                      11/06/2021                                             R$ 0,00
PicPay - CNPJ 22.896.431/0001-10
https://ajuda.picpay.com/picpay
TXT;

        $parser = new PicPayInvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertSame(['mes' => 6, 'ano' => 2021], $parser->extractPeriod($text));

        $transactions = $parser->parse($text);
        $this->assertCount(11, $transactions);

        $this->assertSame('2021-05-13', $transactions[0]['data']);
        $this->assertSame('PAGAMENTO DE FATURA PELO PICPA', $transactions[0]['estabelecimento']);
        $this->assertSame(288.28, $transactions[0]['valor']);
        $this->assertSame('payment', $transactions[0]['tipo']);

        $this->assertSame('2021-05-16', $transactions[1]['data']);
        $this->assertSame('INVESTGAS LTDA', $transactions[1]['estabelecimento']);
        $this->assertSame(27.5, $transactions[1]['valor']);
        $this->assertSame('purchase', $transactions[1]['tipo']);

        $this->assertSame('2021-05-20', $transactions[2]['data']);
        $this->assertSame('PAGAMENTO ENTRE PESSOAS', $transactions[2]['estabelecimento']);
        $this->assertSame(100.0, $transactions[2]['valor']);
        $this->assertSame('purchase', $transactions[2]['tipo']);

        $this->assertSame('INVESTGAS LTDA', $transactions[3]['estabelecimento']);
        $this->assertSame(43.99, $transactions[3]['valor']);

        $this->assertSame('2021-01-11', $transactions[4]['data']);
        $this->assertSame('MERCPAGO*ASOUN', $transactions[4]['estabelecimento']);
        $this->assertSame(5, $transactions[4]['parcela_atual']);
        $this->assertSame(10, $transactions[4]['parcelas_total']);
        $this->assertSame(15.25, $transactions[4]['valor']);

        $this->assertSame('2021-04-27', $transactions[5]['data']);
        $this->assertSame('PAG*LETICIAMOD', $transactions[5]['estabelecimento']);
        $this->assertSame(2, $transactions[5]['parcela_atual']);
        $this->assertSame(3, $transactions[5]['parcelas_total']);

        $this->assertSame('P L DA SILVA SUPERMERC', $transactions[6]['estabelecimento']);
        $this->assertSame('MP *ACAI', $transactions[7]['estabelecimento']);
        $this->assertSame('MAGALU *MAGAZI', $transactions[8]['estabelecimento']);
        $this->assertSame(1, $transactions[8]['parcela_atual']);
        $this->assertSame(3, $transactions[8]['parcelas_total']);
        $this->assertSame('POSTO GERACAO', $transactions[9]['estabelecimento']);
        $this->assertSame('2021-05-30', $transactions[10]['data']);
        $this->assertSame('FARMAQUALY', $transactions[10]['estabelecimento']);
        $this->assertSame(5.0, $transactions[10]['valor']);

        $compras = 0.0;
        foreach ($transactions as $tx) {
            $this->assertNotSame(38.74, $tx['valor']);
            $this->assertNotSame(0.0, $tx['valor']);
            if ($tx['tipo'] === 'purchase') {
                $compras += $tx['valor'];
            }
        }
        $this->assertEqualsWithDelta(301.71, $compras, 0.001);
    }

    public function test_parse_layout_antigo_prioriza_coluna_em_reais(): void
    {
        $text = <<<'TXT'
Fatura 20 JUN 2021 | Data de fechamento 10 JUN 2021
PicPay - CNPJ 22.896.431/0001-10
https://ajuda.picpay.com/picpay
Transações Valores em U$ Valores em R$
02 jun AMAZON US 1,50 8,90
TXT;

        $parser = new PicPayInvoiceParser();
        $transactions = $parser->parse($text);

        $this->assertCount(1, $transactions);
        $this->assertSame('2021-06-02', $transactions[0]['data']);
        $this->assertSame('AMAZON US', $transactions[0]['estabelecimento']);
        $this->assertSame(8.9, $transactions[0]['valor']);
    }

    public function test_supports_exige_contexto_picpay(): void
    {
        $this->assertFalse((new PicPayInvoiceParser())->supports("Picpay*wc5 Joycesilv\nParcela 1/1"));
        $this->assertFalse((new PicPayInvoiceParser())->supports("Picpay*wc5 Joycesilv\nTransações Nacionais\nParcela 1/1"));
        $this->assertTrue((new PicPayInvoiceParser())->supports("PicPay Bank\nTransações Nacionais\n"));
        $this->assertTrue((new PicPayInvoiceParser())->supports("Picpay Card final 7025\n"));
    }

    public function test_supports_nao_casa_estabelecimento_picpay_em_extrato_sofisa(): void
    {
        $text = <<<'TXT'
SOFISA DIRETO MASTERCARD
Detalhamento da Fatura
15/11 PICPAY*WC5 JOYCESILV 10,00
Transações Nacionais
TXT;

        $this->assertFalse((new PicPayInvoiceParser())->supports($text));
        $this->assertTrue((new SofisaInvoiceParser())->supports($text));
    }
}
