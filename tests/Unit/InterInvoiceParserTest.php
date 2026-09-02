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
        $this->assertSame('1668', $transactions[0]['ultimos_digitos']);

        $this->assertSame('fee', $transactions[1]['tipo']);
        $this->assertSame(16.56, $transactions[1]['valor']);
        $this->assertSame('1668', $transactions[1]['ultimos_digitos']);

        $this->assertSame('RI HAPPY', $transactions[2]['estabelecimento']);
        $this->assertSame(1, $transactions[2]['parcela_atual']);
        $this->assertSame(6, $transactions[2]['parcelas_total']);
        $this->assertSame(193.19, $transactions[2]['valor']);
        $this->assertSame('1668', $transactions[2]['ultimos_digitos']);

        $this->assertSame('refund', $transactions[3]['tipo']);
        $this->assertSame(179.95, $transactions[3]['valor']);

        $this->assertSame('PIX CRED PARCELADO', $transactions[4]['estabelecimento']);
        $this->assertSame(4, $transactions[4]['parcela_atual']);
        $this->assertSame(4, $transactions[4]['parcelas_total']);
    }

    public function test_troca_final_por_cabecalho_de_cartao(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura

CARTÃO 5364****1668
12 de jun. 2026 PAGTO DEBITO AUTOMATICO - + R$ 100,00
Total CARTÃO 5364****1668 R$ 0,00

CARTÃO 2306****8480
07 de dez. 2025 EC *5PRODUTOS (Parcela 06 de 06) - R$ 47,62
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(2, $transactions);
        $this->assertSame('1668', $transactions[0]['ultimos_digitos']);
        $this->assertSame('8480', $transactions[1]['ultimos_digitos']);
        $this->assertSame('EC *5PRODUTOS', $transactions[1]['estabelecimento']);
    }

    public function test_nao_detecta_itau_por_lancamento_parc_itau(): void
    {
        $text = "Banco Inter\nDespesas da fatura\nClientes Inter Digital\n"
            . "10 de jun. 2026 BOLETO CRED PARC ITAU U (Parcela 02 de 04) - R$ 10,00\n";

        $this->assertTrue((new InterInvoiceParser())->supports($text));
        $this->assertFalse((new ItauInvoiceParser())->supports($text));
    }

    public function test_pgto_boleto_parcel_sem_credito_e_compra(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura

CARTÃO 5364****0854
17 de jun. 2026 PGTO BOLETO PARCEL (Parcela 02 de 04) U UNIBANCO HOLDIN R$ 350,20
13 de jul. 2026 PAGTO DEBITO AUTOMATICO - + R$ 6.137,69
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(2, $transactions);

        $this->assertSame('purchase', $transactions[0]['tipo']);
        $this->assertSame(350.20, $transactions[0]['valor']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(4, $transactions[0]['parcelas_total']);

        $this->assertSame('payment', $transactions[1]['tipo']);
        $this->assertSame(6137.69, $transactions[1]['valor']);
    }

    public function test_ignora_quadro_proxima_fatura_abaixo_das_despesas(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura
CARTÃO 2306****8480
Data Movimentação Beneficiário Valor
09 de mai. 2026 MERCADOLIVRE*ABEXPECA (Parcela 02 de 02) - R$ 87,13
13 de mai. 2026 99 *99Pay*Leonardo da (Parcela 02 de 02) - R$ 339,03
13 de mai. 2026 MOBILE HUB (Parcela 02 de 06) - R$ 583,33
Total CARTÃO 2306****8480 R$ 1.009,49

Próxima fatura
Data de corte: 05/08/2026
Essas são as compras parceladas realizadas até o
fechamento da fatura atual, e que farão parte da sua
próxima fatura:
Movimentação Valor
FARMACIA PERMANENTE (Parcela 03 de 04) R$ 78,48
MOBILE HUB (Parcela 03 de 06) R$ 583,33
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(3, $transactions);

        $this->assertSame('MERCADOLIVRE*ABEXPECA', $transactions[0]['estabelecimento']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(2, $transactions[0]['parcelas_total']);

        $this->assertSame('MOBILE HUB', $transactions[2]['estabelecimento']);
        $this->assertSame('2026-05-13', $transactions[2]['data']);
        $this->assertSame(2, $transactions[2]['parcela_atual']);
        $this->assertSame(6, $transactions[2]['parcelas_total']);
        $this->assertSame(583.33, $transactions[2]['valor']);

        $estabelecimentos = array_column($transactions, 'estabelecimento');
        $this->assertNotContains('FARMACIA PERMANENTE', $estabelecimentos);
        $this->assertSame(1, count(array_filter(
            $transactions,
            static fn (array $tx) => $tx['estabelecimento'] === 'MOBILE HUB'
        )));
    }

    public function test_ignora_coluna_proxima_fatura_colada_na_mesma_linha(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura                                          Próxima fatura
CARTÃO 2306****8480                                         Data de corte: 05/08/2026
Data Movimentação Beneficiário Valor                        Movimentação Valor
09 de mai. 2026 MERCADOLIVRE*ABEXPECA (Parcela 02 de 02) - R$ 87,13    FARMACIA PERMANENTE (Parcela 03 de 04) R$ 78,48
13 de mai. 2026 MOBILE HUB (Parcela 02 de 06) - R$ 583,33    MOBILE HUB (Parcela 03 de 06) R$ 583,33
05/08/2026 MOBILE HUB (Parcela 03 de 06) R$ 583,33
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(2, $transactions);

        $this->assertSame('MERCADOLIVRE*ABEXPECA', $transactions[0]['estabelecimento']);
        $this->assertSame(87.13, $transactions[0]['valor']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);

        $this->assertSame('MOBILE HUB', $transactions[1]['estabelecimento']);
        $this->assertSame('2026-05-13', $transactions[1]['data']);
        $this->assertSame(2, $transactions[1]['parcela_atual']);
        $this->assertSame(6, $transactions[1]['parcelas_total']);
        $this->assertSame(583.33, $transactions[1]['valor']);
        $this->assertSame('8480', $transactions[1]['ultimos_digitos']);
    }

    public function test_omite_compra_parcelada_integralmente_estornada(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura
CARTÃO 2306****8480
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 01 de 05) - R$ 416,81
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 02 de 05) - R$ 416,81
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 03 de 05) - R$ 416,81
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 04 de 05) - R$ 416,81
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 05 de 05) - R$ 416,81
10 de dez. 2025 GOL LINHAS A*QKSQOO017 - + R$ 2.084,05
11 de dez. 2025 99Pay *Recarga celula - R$ 40,00
15 de dez. 2025 GOL LINHAS A*IYCWFC018 (Parcela 01 de 05) - R$ 561,46
15 de dez. 2025 GOL LINHAS A*IYCWFC018 - + R$ 2.807,22
15 de dez. 2025 GOL LINHAS A*IYCWFC018 (Parcela 02 de 05) - R$ 561,44
15 de dez. 2025 GOL LINHAS A*IYCWFC018 (Parcela 03 de 05) - R$ 561,44
15 de dez. 2025 GOL LINHAS A*IYCWFC018 (Parcela 04 de 05) - R$ 561,44
15 de dez. 2025 GOL LINHAS A*IYCWFC018 (Parcela 05 de 05) - R$ 561,44
15 de dez. 2025 MERCADOLIVRE*2PRODUTOS - R$ 64,85
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(2, $transactions);
        $this->assertSame('99Pay *Recarga celula', $transactions[0]['estabelecimento']);
        $this->assertSame(40.0, $transactions[0]['valor']);
        $this->assertSame('MERCADOLIVRE*2PRODUTOS', $transactions[1]['estabelecimento']);

        $nomes = array_column($transactions, 'estabelecimento');
        $this->assertNotContains('GOL LINHAS A*QKSQOO017', $nomes);
        $this->assertNotContains('GOL LINHAS A*IYCWFC018', $nomes);
    }

    public function test_mantem_parcela_quando_nao_ha_estorno_total(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura
CARTÃO 2306****8480
10 de dez. 2025 GOL LINHAS A*QKSQOO017 (Parcela 02 de 05) - R$ 416,81
TXT;

        $transactions = (new InterInvoiceParser())->parse($text);
        $this->assertCount(1, $transactions);
        $this->assertSame('GOL LINHAS A*QKSQOO017', $transactions[0]['estabelecimento']);
        $this->assertSame(2, $transactions[0]['parcela_atual']);
        $this->assertSame(5, $transactions[0]['parcelas_total']);
    }
}
