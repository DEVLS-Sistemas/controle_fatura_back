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
