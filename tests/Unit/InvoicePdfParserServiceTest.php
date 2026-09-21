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
        $this->tempDir = sys_get_temp_dir().'/invoice_parser_'.uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_parse_csv_inter_com_metadados_e_extensao_txt(): void
    {
        $content = " Fatura ;;;\r\n"
            ."Conta ;19560290;;\r\n"
            ."Cartao ;5117.XXXX.XXXX.6645;;\r\n"
            ."Periodo ;02/677;;\r\n"
            ."Vencimento ;15/09/2019;;\r\n"
            ."Saldo ;840,65;;\r\n"
            .";;;\r\n"
            ."Data da Transacao;Estabelecimento;Tipo da Transacao;Valor\r\n"
            ."01/09/2019;Mercadinho Tavares L;Parcela 1/1;30,65\r\n"
            ."01/09/2019;Picpay*wc5 Joycesilv;Parcela 1/1;800\r\n"
            ."01/09/2019;Picpay *wc5 Recargac;Parcela 1/1;10\r\n"
            ."05/09/2019;Pagamento Recebido;Parcela 1/1;-100\r\n"
            ."06/09/2019;Estorno Loja X;Parcela 1/1;-20,50\r\n";

        $path = $this->tempDir.'/inter_fatura.txt';
        file_put_contents($path, $content);

        $parsed = (new InvoicePdfParserService)->parseFile($path);

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
            ."2019-04-13,outros,Atacado dos Presentes 2/3,15.03\n"
            ."2019-04-16,,Pagamento recebido,-522\n";

        $path = $this->tempDir.'/nubank.csv';
        file_put_contents($path, $content);

        $parsed = (new InvoicePdfParserService)->parseFile($path);

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

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(2271.47, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_picpay_ignora_total_a_pagar_do_rotativo(): void
    {
        $text = <<<'TXT'
PicPay Bank Banco Múltiplo S.A.
Esta é a sua fatura de Setembro.
              Total da sua fatura                              Vencimento                                                     Limite total
            R$ 2.288,25                                  10/09/2026                                              R$ 15.400,00
Total da fatura                                      R$ 2.288,25
                 Pagamento total                            Pagamento mínimo
           R$ 2.288,25                                    R$ 185,53
3. Pagamento mínimo + Crédito rotativo
                                                                                                      Total a pagar                                                 R$ 2.509,08
Total geral dos lançamentos                                2.288,25
Valor total da fatura                                            R$ 2.288,25
Valor total a pagar                                              R$ 2.509,08
TXT;

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(2288.25, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_itau_nao_descarta_por_limite_de_credito(): void
    {
        // Capa Itaú: o limite vem logo depois do total. Abortar por "limite"
        // na janela descartava o cabeçalho e gravava a soma das linhas (ex.: 1200).
        $text = <<<'TXT'
Banco Itaú S.A.
Com vencimento em:
14/09/2026
O total da sua fatura é:
R$ 1.544,66
Preparamos outra opção de pagamento abaixo, válida até a data de vencimento:
Limite total de crédito:
R$ 8.311,00
TXT;

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(1544.66, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_itau_duas_colunas_ignora_limite(): void
    {
        $text = <<<'TXT'
                                LEONARDO DA SILVA FERREIRA
                                                                             Postagem: 06/09/2026
                                                                            Vencimento: 14/09/2026
                                                                               Emissão: 06/09/2026

           Titular LEONARDO DA SILVA FERREIRA
           Cartão 4705.XXXX.XXXX.8201

    O total da sua fatura é:                                                   Com vencimento em:
    R$ 1.544,66                                                                      14/09/2026

                      Banco Itaú S.A. 341-7 34191758012859651252650484150003315060000154466
                      Nome do Beneficiário/CPF/CNPJ   ITAU UNIBANCO HOLDING S.A.

    Limite total de crédito:
    R$ 8.311,00
TXT;

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(1544.66, $method->invoke($service, $text));
    }

    public function test_parse_itau_prevalece_total_do_pdf_sobre_soma_das_linhas(): void
    {
        $text = <<<'TXT'
                                LEONARDO DA SILVA FERREIRA
                                                                             Postagem: 06/09/2026
                                                                            Vencimento: 14/09/2026
                                                                               Emissão: 06/09/2026

           Titular LEONARDO DA SILVA FERREIRA
           Cartão 4705.XXXX.XXXX.8201

    O total da sua fatura é:                                                   Com vencimento em:
    R$ 1.544,66                                                                      14/09/2026

                      Banco Itaú S.A. 341-7 34191758012859651252650484150003315060000154466
                      Nome do Beneficiário/CPF/CNPJ   ITAU UNIBANCO HOLDING S.A.

    Limite total de crédito:
    R$ 8.311,00

                 Pagamentos efetuados                                                     Encargos cobrados nesta fatura
                 DATA                                                 VALOR EM R$
                 17/08    PAGAMENTO                                      -1.200,00
                P Total dos pagamentos                                   -1.200,00
                 Lançamentos: compras e saques
                 LEONARDO DA SILVA FERREIR
                 DATA     ESTABELECIMENTO                             VALOR EM R$
                 28/11    PERNAMBUCO MOT 08/10                            1.200,00
                          outros PAULISTA
                 Lançamentos no cartão                                    1.200,00
                L Total dos lançamentos atuais                            1.200,00
TXT;

        $parsed = (new InvoicePdfParserService)->parseExtractedText($text);

        $this->assertSame('itau', $parsed['parser']);
        $this->assertSame(1544.66, $parsed['valor_fatura']);
        $this->assertFalse($parsed['conferencia']['bate']);
        $this->assertSame(1544.66, $parsed['conferencia']['valor_cabecalho']);
        $this->assertSame(1200.0, $parsed['conferencia']['soma_transacoes']);
    }

    public function test_parse_itau_com_todas_as_linhas_bate_com_cabecalho(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/itau-click-valores-direita.txt');
        $this->assertNotFalse($text);

        $parsed = (new InvoicePdfParserService)->parseExtractedText($text);

        $this->assertSame('itau', $parsed['parser']);
        $this->assertSame(1544.66, $parsed['valor_fatura']);
        $this->assertTrue($parsed['conferencia']['bate']);
        $this->assertSame(1544.66, $parsed['conferencia']['soma_transacoes']);
    }

    public function test_homologado_nao_rebaixa_cabecalho_quando_falta_linha(): void
    {
        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'sanitizarCabecalhoSeLimite');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'parser' => 'nubank',
            'valor_fatura' => 2288.25,
            'conferencia' => [
                'valor_cabecalho' => 2288.25,
                'soma_transacoes' => 2150.68,
                'bate' => false,
                'diferenca' => 137.57,
            ],
        ]);

        $this->assertSame(2288.25, $result['valor_fatura']);

        $picpay = $method->invoke($service, [
            'parser' => 'picpay',
            'valor_fatura' => 2288.25,
            'conferencia' => [
                'valor_cabecalho' => 2288.25,
                'soma_transacoes' => 2150.68,
                'bate' => false,
                'diferenca' => 137.57,
            ],
        ]);
        $this->assertSame(2288.25, $picpay['valor_fatura']);
    }

    public function test_inter_rebaixa_cabecalho_quando_e_limite_do_cartao(): void
    {
        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'sanitizarCabecalhoSeLimite');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'parser' => 'inter',
            'valor_fatura' => 17560.00,
            'conferencia' => [
                'valor_cabecalho' => 17560.00,
                'soma_transacoes' => 7512.20,
                'bate' => false,
                'diferenca' => 10047.80,
            ],
        ]);

        $this->assertSame(7512.20, $result['valor_fatura']);
    }

    public function test_conferencia_payload_detalhe(): void
    {
        $bate = InvoicePdfParserService::conferenciaPayload(2288.25, 2288.25);
        $this->assertTrue($bate['bate']);
        $this->assertSame(0.0, $bate['diferenca']);

        $gap = InvoicePdfParserService::conferenciaPayload(2288.25, 2150.68);
        $this->assertFalse($gap['bate']);
        $this->assertSame(2288.25, $gap['valor_cabecalho']);
        $this->assertSame(2150.68, $gap['soma_transacoes']);
        $this->assertSame(137.57, $gap['diferenca']);
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

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(7512.20, $method->invoke($service, $text));
    }

    public function test_conferencia_detecta_divergencia_cabecalho_vs_soma(): void
    {
        $service = new InvoicePdfParserService;
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
        $service = new InvoicePdfParserService;
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
        $service = new InvoicePdfParserService;
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

        $service = new InvoicePdfParserService;
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

        $service = new InvoicePdfParserService;
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

        $service = new InvoicePdfParserService;
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

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(157.92, $method->invoke($service, $text));
    }

    public function test_extract_valor_fatura_sofisa_total_a_pagar_ignora_minimo(): void
    {
        $text = <<<'TXT'
Olá, LEONARDO chegou a fatura com                                            Total a Pagar                    Vencimento
                                                                              R$ 162,04                       10/09/2026
as compras e pagamentos feitos até
01/09/2026 com o seu cartão SOFISA
                                                                          Pagamento mínimo              Melhor dia para compra
DIRETO MASTERCARD.                                                             R$ 24,31                       02/09/2026

(+) Total a Pagar                                         162,04
TXT;

        $service = new InvoicePdfParserService;
        $method = new \ReflectionMethod(InvoicePdfParserService::class, 'extractValorFaturaHeader');
        $method->setAccessible(true);

        $this->assertSame(162.04, $method->invoke($service, $text));
    }

    public function test_parse_uploaded_file_temp_sem_extensao_usa_nome_original_csv(): void
    {
        $content = "date,title,amount\n"
            ."2019-04-13,Loja Teste,15.03\n";

        // Simula /tmp/phpXXXX (sem extensão) — bug do cadastro com só anexo.
        $path = $this->tempDir.'/php'.bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        $upload = new UploadedFile($path, 'fatura-inter.csv', 'text/csv', null, true);
        $parsed = (new InvoicePdfParserService)->parseUploadedFile($upload);

        $this->assertSame('csv', $parsed['parser']);
        $this->assertCount(1, $parsed['transactions']);
        $this->assertSame('Loja Teste', $parsed['transactions'][0]['estabelecimento']);
    }

    public function test_parse_file_temp_sem_extensao_detecta_pdf_por_magic_bytes(): void
    {
        $path = $this->tempDir.'/php'.bin2hex(random_bytes(4));
        // Cabeçalho PDF + conteúdo mínimo (pdftotext deve falhar, mas NÃO como "formato não suportado")
        file_put_contents($path, '%PDF-1.4\n%âãÏÓ\n');

        try {
            (new InvoicePdfParserService)->parseFile($path);
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
                'Mensagem inesperada: '.$e->getMessage()
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

    public function test_texto_sofisa_com_estabelecimento_picpay_usa_parser_sofisa(): void
    {
        $text = <<<'TXT'
SOFISA DIRETO MASTERCARD
Vencimento: 10/09/2026
Detalhamento da Fatura
15/11 PICPAY*WC5 JOYCESILV 10,00
Transações Nacionais
TXT;

        $parsed = (new InvoicePdfParserService)->parseExtractedText($text);

        $this->assertSame('sofisa', $parsed['parser']);
        $this->assertSame('sofisa', $parsed['metadata']['parser']);
        $this->assertSame('Mastercard', $parsed['metadata']['bandeira_sugerida']);
    }

    public function test_pdf_criptografado_abre_com_senha_e_falha_sem_senha(): void
    {
        $service = new InvoicePdfParserService;
        if (! $service->ghostscriptDisponivel()) {
            $this->markTestSkipped('Ghostscript (gs) é necessário para abrir PDF com senha.');
        }

        $enc = $this->criarPdfProtegido('segredo123');
        $this->assertTrue($service->pdfEstaCriptografado($enc));

        try {
            $service->caminhoPdfAberto($enc, null);
            $this->fail('Esperava PdfPasswordException sem senha');
        } catch (PdfPasswordException $e) {
            $this->assertSame(PdfPasswordException::MOTIVO_AUSENTE, $e->motivo);
        }

        try {
            $service->caminhoPdfAberto($enc, 'errada');
            $this->fail('Esperava PdfPasswordException com senha errada');
        } catch (PdfPasswordException $e) {
            $this->assertSame(PdfPasswordException::MOTIVO_INCORRETA, $e->motivo);
        }

        $aberto = $service->caminhoPdfAberto($enc, 'segredo123');
        $this->assertTrue($aberto['temporario']);
        $this->assertFileExists($aberto['path']);
        $this->assertFalse($service->pdfEstaCriptografado($aberto['path']));
        @unlink($aberto['path']);
    }

    private function criarPdfProtegido(string $senha): string
    {
        $plain = $this->tempDir.'/plain.pdf';
        $enc = $this->tempDir.'/sofisa-protegido.pdf';
        $gs = '/usr/bin/gs';

        $criar = proc_open(
            [$gs, '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite', '-sOutputFile='.$plain, '-c', 'showpage'],
            [2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($criar)) {
            fclose($pipes[2]);
            proc_close($criar);
        }

        $criptografar = proc_open(
            [
                $gs, '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite',
                '-sOwnerPassword=owner',
                '-sUserPassword='.$senha,
                '-dEncryptionR=3',
                '-dKeyLength=128',
                '-sOutputFile='.$enc,
                $plain,
            ],
            [2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($criptografar)) {
            fclose($pipes[2]);
            proc_close($criptografar);
        }

        return $enc;
    }
}
