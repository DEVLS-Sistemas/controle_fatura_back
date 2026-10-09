<?php

namespace Tests\Unit;

use App\Models\Transacao;
use App\Services\Pdf\NubankCreditoVerde;
use PHPUnit\Framework\TestCase;

class NubankCreditoVerdeTest extends TestCase
{
    public function test_valor_verde_de_variacao_cambial_vira_estorno_e_o_preto_continua_compra(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<pdf2xml>
<page number="4">
	<fontspec id="8" size="9" family="Book" color="#4a4a4a"/>
	<fontspec id="9" size="12" family="Book" color="#000000"/>
	<fontspec id="16" size="12" family="Book" color="#6cc10d"/>
	<text top="446" left="215" font="8">24 JUN</text>
	<text top="443" left="357" font="9">Variação cambial</text>
	<text top="443" left="805" font="16">0,99</text>
	<text top="722" left="215" font="8">25 MAI</text>
	<text top="722" left="357" font="9">Variação cambial</text>
	<text top="722" left="808" font="9">5,23</text>
	<text top="340" left="400" font="9">Outros lançamentos</text>
	<text top="340" left="805" font="16">0,99</text>
</page>
</pdf2xml>
XML;

        $servico = new NubankCreditoVerde();
        $creditos = $servico->creditosDoXml($xml);

        $this->assertCount(1, $creditos);
        $this->assertSame(24, $creditos[0]['dia']);
        $this->assertSame(6, $creditos[0]['mes']);
        $this->assertSame(0.99, $creditos[0]['valor']);

        $transactions = $servico->aplicarCreditos([
            [
                'data' => '2018-06-24',
                'estabelecimento' => 'Variação cambial',
                'valor' => 0.99,
                'tipo' => Transacao::TIPO_PURCHASE,
            ],
            [
                'data' => '2018-05-25',
                'estabelecimento' => 'Variação cambial',
                'valor' => 5.23,
                'tipo' => Transacao::TIPO_PURCHASE,
            ],
            [
                'data' => '2018-06-19',
                'estabelecimento' => 'Pagamento em 19 JUN',
                'valor' => 121.50,
                'tipo' => Transacao::TIPO_PAYMENT,
            ],
        ], $creditos);

        $this->assertSame(Transacao::TIPO_REFUND, $transactions[0]['tipo']);
        $this->assertSame(Transacao::TIPO_PURCHASE, $transactions[1]['tipo']);
        $this->assertSame(Transacao::TIPO_PAYMENT, $transactions[2]['tipo']);
    }
}
