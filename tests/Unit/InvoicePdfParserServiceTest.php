<?php

namespace Tests\Unit;

use App\Services\Pdf\InvoicePdfParserService;
use PHPUnit\Framework\TestCase;

class InvoicePdfParserServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/invoice_parser_' . uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_parse_csv_inter_com_metadados_e_extensao_txt(): void
    {
        $content = " Fatura ;;;\r\n"
            . "Conta ;19560290;;\r\n"
            . "Cartao ;5117.XXXX.XXXX.6645;;\r\n"
            . "Periodo ;02/677;;\r\n"
            . "Vencimento ;15/09/2019;;\r\n"
            . "Saldo ;840,65;;\r\n"
            . ";;;\r\n"
            . "Data da Transacao;Estabelecimento;Tipo da Transacao;Valor\r\n"
            . "01/09/2019;Mercadinho Tavares L;Parcela 1/1;30,65\r\n"
            . "01/09/2019;Picpay*wc5 Joycesilv;Parcela 1/1;800\r\n"
            . "01/09/2019;Picpay *wc5 Recargac;Parcela 1/1;10\r\n"
            . "05/09/2019;Pagamento Recebido;Parcela 1/1;-100\r\n"
            . "06/09/2019;Estorno Loja X;Parcela 1/1;-20,50\r\n";

        $path = $this->tempDir . '/inter_fatura.txt';
        file_put_contents($path, $content);

        $parsed = (new InvoicePdfParserService())->parseFile($path);

        $this->assertSame('inter-csv', $parsed['parser']);
        $this->assertCount(5, $parsed['transactions']);

        $first = $parsed['transactions'][0];
        $this->assertSame('2019-09-01', $first['data']);
        $this->assertSame('Mercadinho Tavares L', $first['estabelecimento']);
        $this->assertSame(30.65, $first['valor']);
        $this->assertSame(1, $first['parcela_atual']);
        $this->assertSame(1, $first['parcelas_total']);
        $this->assertSame('purchase', $first['tipo']);

        $this->assertSame('payment', $parsed['transactions'][3]['tipo']);
        $this->assertSame(100.0, $parsed['transactions'][3]['valor']);

        $this->assertSame('refund', $parsed['transactions'][4]['tipo']);
        $this->assertSame(20.5, $parsed['transactions'][4]['valor']);
    }

    public function test_parse_csv_nubank_padrao(): void
    {
        $content = "date,category,title,amount\n"
            . "2019-04-13,outros,Atacado dos Presentes 2/3,15.03\n"
            . "2019-04-16,,Pagamento recebido,-522\n";

        $path = $this->tempDir . '/nubank.csv';
        file_put_contents($path, $content);

        $parsed = (new InvoicePdfParserService())->parseFile($path);

        $this->assertSame('csv', $parsed['parser']);
        $this->assertCount(2, $parsed['transactions']);
        $this->assertSame('Atacado dos Presentes', $parsed['transactions'][0]['estabelecimento']);
        $this->assertSame(2, $parsed['transactions'][0]['parcela_atual']);
        $this->assertSame(3, $parsed['transactions'][0]['parcelas_total']);
        $this->assertSame('payment', $parsed['transactions'][1]['tipo']);
    }

    public function test_extract_valor_fatura_picpay_ignora_pagamento_minimo(): void
    {
        $text = <<<'TXT'
Total da sua fatura                              Vencimento                                                     Limite total

            R$ 2.271,47                                  10/07/2026                                              R$ 15.400,00

Total da fatura                                       R$ 2.271,47

                 Pagamento total                            Pagamento mínimo

           R$ 2.271,47                                     R$ 113,57
*O pagamento mínimo no valor de R$ 113,57 é composto
por R$ 0,00 de encargo financeiro do rotativo.
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(2271.47, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_nubank_com_mes(): void
    {
        $text = <<<'TXT'
Esta é a sua fatura Nubank de
maio, no valor de
R$ 899,02
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(899.02, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_c6(): void
    {
        $text = <<<'TXT'
Olá, Leonardo Silva! Sua fatura com
vencimento em Julho chegou no
valor de R$ 157,92.

                           Valor da fatura: R$ 157,92               Anuidade: R$0,00                    Cartão C6
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(157.92, $method->invoke($service, $text));
    }
}
