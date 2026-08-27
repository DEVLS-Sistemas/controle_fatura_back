<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\C6InvoiceParser;
use App\Services\Pdf\Parsers\InterInvoiceParser;
use App\Services\Pdf\Parsers\NubankInvoiceParser;
use App\Services\Pdf\Parsers\SofisaInvoiceParser;
use PHPUnit\Framework\TestCase;

class InvoiceMetadataExtractionTest extends TestCase
{
    public function test_c6_extract_period_pelo_fechamento(): void
    {
        $text = <<<'TXT'
C6 Bank
Compras e pagamentos feitos até o fechamento desta fatura em 03/07/26.
Transações do cartão principal
      Cartão C6 Final 0264 - LEONARDO S FERREIRA
      11 jun   CLARO FLEX                                                                                                  59,99
TXT;

        $period = (new C6InvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 7, 'ano' => 2026], $period);
    }

    public function test_nubank_extract_period_pelo_vencimento(): void
    {
        $text = <<<'TXT'
Nubank
Data de vencimento: 12 MAI 2026
RESUMO 5162 •••• •••• 7495
05 ABR •••• 7402 Amazon R$ 35,21
TXT;

        $period = (new NubankInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 5, 'ano' => 2026], $period);
    }

    public function test_nubank_extract_period_nao_usa_ano_corrente_em_fatura_antiga(): void
    {
        $text = <<<'TXT'
Olá, Leonardo
Esta é a sua fatura Nubank de
julho, no valor de
R$ 2.274,33

Data de vencimento: 10 JUL 2024
Período vigente: 06 JUN a 05 JUL

FATURA 10 JUL 2024
TXT;

        $period = (new NubankInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 7, 'ano' => 2024], $period);
        $this->assertNotSame((int) date('Y'), $period['ano']);
    }

    public function test_nubank_extract_period_vencimento_quebrado_em_linhas(): void
    {
        $text = <<<'TXT'
Nubank
Data de vencimento:
10 JUL 2024
TRANSAÇÕES DE 06 JUN A 05 JUL
TXT;

        $period = (new NubankInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 7, 'ano' => 2024], $period);
    }

    public function test_nubank_extract_period_mes_por_extenso(): void
    {
        $text = <<<'TXT'
Nubank
Esta é a sua fatura Nubank de julho de 2024, no valor de R$ 100,00
TXT;

        $period = (new NubankInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 7, 'ano' => 2024], $period);
    }

    public function test_sofisa_extract_period_pelo_vencimento(): void
    {
        $text = <<<'TXT'
Chegou a fatura com as compras e pagamentos feitos até 05/06/2026 com o seu cartão SOFISA DIRETO VISA.
     Total a pagar                               Vencimento:
   R$ 2.275,10                                10/06/2026
Detalhamento da Fatura
TXT;

        $period = (new SofisaInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 6, 'ano' => 2026], $period);
    }

    public function test_inter_extract_period_pela_data_mais_recente(): void
    {
        $text = <<<'TXT'
Banco Inter
Clientes Inter Digital
Despesas da fatura
12 de jun. 2026 PAGTO - + R$ 100,00
02 de jul. 2026 LOJA - R$ 50,00
TXT;

        $period = (new InterInvoiceParser())->extractPeriod($text);
        $this->assertSame(['mes' => 7, 'ano' => 2026], $period);
    }

}
