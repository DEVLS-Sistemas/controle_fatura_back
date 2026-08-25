<?php

namespace Tests\Unit;

use App\Services\Pdf\FaturaParserHomologacao;
use PHPUnit\Framework\TestCase;

class FaturaParserHomologacaoTest extends TestCase
{
    public function test_lista_so_os_homologados_com_fatura_real(): void
    {
        $chaves = array_column(FaturaParserHomologacao::all(), 'chave');

        $this->assertSame(['nubank', 'inter', 'c6', 'sofisa', 'picpay', 'itau'], $chaves);
        $this->assertNotContains('santander', $chaves);
        $this->assertNotContains('bradesco', $chaves);
    }

    public function test_itau_tem_nota_de_click(): void
    {
        $itau = FaturaParserHomologacao::find('itau');

        $this->assertSame('Itaú', $itau['label']);
        $this->assertSame('Homologado com fatura Itaú Click', $itau['nota']);
    }

    public function test_parser_inter_csv_e_homologado_generico_nao(): void
    {
        $this->assertTrue(FaturaParserHomologacao::isParserHomologado('inter'));
        $this->assertTrue(FaturaParserHomologacao::isParserHomologado('inter-csv'));
        $this->assertFalse(FaturaParserHomologacao::isParserHomologado('generico'));
        $this->assertFalse(FaturaParserHomologacao::isParserHomologado('csv'));
        $this->assertFalse(FaturaParserHomologacao::isParserHomologado('xml'));
        $this->assertFalse(FaturaParserHomologacao::isParserHomologado(null));
    }

    public function test_cartao_nubank_homologado_santander_nao(): void
    {
        $nubank = FaturaParserHomologacao::anexarCartao('Nubank Principal');
        $this->assertTrue($nubank['importacao_pdf_homologada']);
        $this->assertSame('nubank', $nubank['parser_homologado']['chave']);

        $santander = FaturaParserHomologacao::anexarCartao('Santander SX');
        $this->assertFalse($santander['importacao_pdf_homologada']);
        $this->assertNull($santander['parser_homologado']);

        $desconhecido = FaturaParserHomologacao::anexarCartao('Cartão da empresa');
        $this->assertFalse($desconhecido['importacao_pdf_homologada']);
    }

    public function test_aviso_so_quando_nao_homologado(): void
    {
        $ok = FaturaParserHomologacao::anexarParser('nubank');
        $this->assertTrue($ok['importacao_pdf_homologada']);
        $this->assertNull($ok['aviso_parser']);

        $gen = FaturaParserHomologacao::anexarParser('generico');
        $this->assertFalse($gen['importacao_pdf_homologada']);
        $this->assertSame(FaturaParserHomologacao::AVISO, $gen['aviso_parser']);
    }
}
