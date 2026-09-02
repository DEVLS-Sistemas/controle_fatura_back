<?php

namespace Tests\Unit;

use App\Exceptions\PdfPasswordException;
use App\Services\Pdf\InvoicePdfParserService;
use Illuminate\Http\UploadedFile;
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

    public function test_extract_valor_fatura_inter_nao_pega_limite_do_cartao(): void
    {
        // Layout Inter real: rótulo do limite fica acima; o R$ do limite vem antes do total.
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital

                                                                                                     Limite de crédito total
         Total da sua fatura
                                                                                                     R$ 17.560,00

         R$ 7.512,20                                                                                 Data de Vencimento

         Este é o valor que você precisa pagar nesse mês                                             12/08/2026

 Fatura atual                                                                                                                     R$ 7.512,20
Despesas da fatura
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(7512.20, $method->invoke($service, $text));
    }

    public function test_conferencia_detecta_divergencia_cabecalho_vs_soma(): void
    {
        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'buildConferencia');
        $method->setAccessible(true);

        $transactions = [
            ['valor' => 5000.00, 'tipo' => 'purchase'],
            ['valor' => 2512.20, 'tipo' => 'purchase'],
            ['valor' => 1000.00, 'tipo' => 'payment'], // ignorado na soma do ciclo
        ];

        $conf = $method->invoke($service, 17560.00, $transactions);

        $this->assertSame(17560.00, $conf['valor_cabecalho']);
        $this->assertSame(7512.20, $conf['soma_transacoes']);
        $this->assertFalse($conf['bate']);
        $this->assertSame(10047.80, $conf['diferenca']);
    }

    public function test_conferencia_antecipacao_bate_quando_gap_cabe_nos_pagamentos(): void
    {
        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'buildConferencia');
        $method->setAccessible(true);

        $transactions = [
            ['valor' => 2009.53, 'tipo' => 'purchase'],
            ['valor' => 8.20, 'tipo' => 'refund'],
            ['valor' => 1480.62, 'tipo' => 'payment'],
            ['valor' => 51.00, 'tipo' => 'payment'],
        ];

        $conf = $method->invoke($service, 1950.33, $transactions);

        $this->assertTrue($conf['bate']);
        $this->assertSame(2001.33, $conf['soma_transacoes']);
        $this->assertSame(-51.0, $conf['diferenca']);
    }

    public function test_conferencia_bate_quando_soma_igual_cabecalho(): void
    {
        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'buildConferencia');
        $method->setAccessible(true);

        $transactions = [
            ['valor' => 100.00, 'tipo' => 'purchase'],
            ['valor' => 50.50, 'tipo' => 'purchase'],
            ['valor' => 10.00, 'tipo' => 'refund'],
        ];

        $conf = $method->invoke($service, 140.50, $transactions);

        $this->assertTrue($conf['bate']);
        $this->assertSame(140.50, $conf['soma_transacoes']);
        $this->assertSame(0.0, $conf['diferenca']);
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

    public function test_extract_valor_fatura_nubank_sem_marca_no_cumprimento(): void
    {
        $text = <<<'TXT'
Olá, Leonardo.
Esta é a sua fatura de
abril, no valor de
R$ 2.280,95

Data de vencimento: 13 ABR 2026
Nu Pagamentos S.A.
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(2280.95, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_nubank_zero(): void
    {
        $text = <<<'TXT'
Olá, Leonardo.
Esta é a sua fatura de
março, no valor de
R$ 0,00
Data de vencimento: 12 MAR 2026
TXT;

        $service = new InvoicePdfParserService();
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(0.0, $method->invoke($service, $text));
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

    public function test_parse_uploaded_file_temp_sem_extensao_usa_nome_original_csv(): void
    {
        $content = "date,title,amount\n"
            . "2019-04-13,Loja Teste,15.03\n";

        // Simula /tmp/phpXXXX (sem extensão) — bug do cadastro com só anexo.
        $path = $this->tempDir . '/php' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        $upload = new UploadedFile($path, 'fatura-inter.csv', 'text/csv', null, true);
        $parsed = (new InvoicePdfParserService())->parseUploadedFile($upload);

        $this->assertSame('csv', $parsed['parser']);
        $this->assertCount(1, $parsed['transactions']);
        $this->assertSame('Loja Teste', $parsed['transactions'][0]['estabelecimento']);
    }

    public function test_parse_file_temp_sem_extensao_detecta_pdf_por_magic_bytes(): void
    {
        $path = $this->tempDir . '/php' . bin2hex(random_bytes(4));
        // Cabeçalho PDF + conteúdo mínimo (pdftotext deve falhar, mas NÃO como "formato não suportado")
        file_put_contents($path, '%PDF-1.4\n%âãÏÓ\n');

        try {
            (new InvoicePdfParserService())->parseFile($path);
            $this->fail('Esperava erro ao extrair texto do PDF inválido');
        } catch (PdfPasswordException $e) {
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString(
                'Formato de arquivo não suportado',
                $e->getMessage()
            );
            $this->assertTrue(
                str_contains(mb_strtolower($e->getMessage()), 'pdf')
                || str_contains(mb_strtolower($e->getMessage()), 'texto'),
                'Mensagem inesperada: ' . $e->getMessage()
            );
        }
    }

    public function test_reconciliar_ano_substitui_chute_pelo_ano_escrito_no_pdf(): void
    {
        $text = "Data de vencimento: 10 JUL 2024\nFATURA 10 JUL 2024\n";

        $this->assertSame(2024, InvoicePdfParserService::reconciliarAnoComTexto($text, 2026));
        $this->assertSame(2024, InvoicePdfParserService::reconciliarAnoComTexto($text, 2024));
    }

    public function test_reconciliar_ano_mantem_quando_o_ano_inferido_esta_no_texto(): void
    {
        $text = "Data de vencimento: 10 AGO 2026\n";

        $this->assertSame(2026, InvoicePdfParserService::reconciliarAnoComTexto($text, 2026));
    }
}
