<?php

namespace Tests\Unit;

use App\Services\Pdf\Parsers\C6InvoiceParser;
use App\Services\Pdf\Parsers\GenericInvoiceParser;
use PHPUnit\Framework\TestCase;

class C6InvoiceParserTest extends TestCase
{
    public function test_parse_transacoes_c6_com_parcela_e_ano_pelo_fechamento(): void
    {
        $text = <<<'TXT'
Olá, Leonardo Silva! Sua fatura com
vencimento em Julho chegou no
valor de R$ 157,92.

© 2022 BANCO C6 S.A. CNPJ: 31.872.495/0001-72

                           Valor da fatura: R$ 157,92               Anuidade: R$0,00                    Cartão C6

  Resumo da fatura
  Compras e pagamentos feitos até o fechamento desta fatura em 03/07/26.

  Transações do cartão principal
  Lembrando: nesta fatura serão lançadas apenas transações feitas até 03/07/26.

      Cartão C6 Final 0264 - LEONARDO S FERREIRA                                                  Subtotal deste cartão R$ 0,00

                                                                                                                  Valores em reais

      10 jun     Inclusao de Pagamento                                                                                    157,92

      Cartão C6 Virtual Final 2399 - LEONARDO S                       Cartão Virtual         Subtotal deste cartão R$ 157,92

      06 nov   AMAZON RETAIL CPI - Parcela 8/12                                                                            68,03

      11 jun   CLARO FLEX                                                                                                  59,99

      25 jun   AMAZONPRIMEBR                                                                                               19,90

      26 jun   AMAZON AD FREE FOR PRI                                                                                      10,00

            Formas de pagamento
            Você pode pagar sua fatura com débito em conta, QR Code Pix ou boleto.
TXT;

        $parser = new C6InvoiceParser();
        $this->assertTrue($parser->supports($text));
        $this->assertTrue((new GenericInvoiceParser())->supports($text));

        $transactions = $parser->parse($text);
        $this->assertCount(5, $transactions);

        $this->assertSame('2026-06-10', $transactions[0]['data']);
        $this->assertSame('Inclusao de Pagamento', $transactions[0]['estabelecimento']);
        $this->assertSame(157.92, $transactions[0]['valor']);
        $this->assertSame('payment', $transactions[0]['tipo']);

        // nov > julho (fechamento) → ano anterior
        $this->assertSame('2025-11-06', $transactions[1]['data']);
        $this->assertSame('AMAZON RETAIL CPI', $transactions[1]['estabelecimento']);
        $this->assertSame(8, $transactions[1]['parcela_atual']);
        $this->assertSame(12, $transactions[1]['parcelas_total']);
        $this->assertSame(68.03, $transactions[1]['valor']);
        $this->assertSame('purchase', $transactions[1]['tipo']);

        $this->assertSame('2026-06-11', $transactions[2]['data']);
        $this->assertSame('CLARO FLEX', $transactions[2]['estabelecimento']);
        $this->assertSame(59.99, $transactions[2]['valor']);

        $this->assertSame('AMAZONPRIMEBR', $transactions[3]['estabelecimento']);
        $this->assertSame(19.9, $transactions[3]['valor']);

        $this->assertSame('2026-06-26', $transactions[4]['data']);
        $this->assertSame('AMAZON AD FREE FOR PRI', $transactions[4]['estabelecimento']);
        $this->assertSame(10.0, $transactions[4]['valor']);
    }

    public function test_nao_detecta_sem_c6(): void
    {
        $text = "Transações do cartão principal\n10 jun LOJA 10,00\n";
        $this->assertFalse((new C6InvoiceParser())->supports($text));
    }

    public function test_ignora_linhas_fora_da_secao_de_transacoes(): void
    {
        $text = <<<'TXT'
C6 Bank
Valor da fatura: R$ 100,00
fechamento desta fatura em 03/07/26

  1. Pagamento total                    Recomendado                                                                                      R$ 100,00

  Transações do cartão principal
      11 jun   LOJA TESTE                                                                                                  100,00

            Formas de pagamento
TXT;

        $transactions = (new C6InvoiceParser())->parse($text);
        $this->assertCount(1, $transactions);
        $this->assertSame('LOJA TESTE', $transactions[0]['estabelecimento']);
    }
}
