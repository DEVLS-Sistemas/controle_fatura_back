<?php

namespace Tests\Unit;

use App\Services\Cartao\CartaoCoresPreset;
use App\Services\Cartao\CartaoService;
use PHPUnit\Framework\TestCase;

class CartaoCoresPresetTest extends TestCase
{
    public function test_nubank_e_itau_com_acento(): void
    {
        $nubank = CartaoCoresPreset::resolver('Nubank Principal');
        $this->assertSame('nubank', $nubank['chave']);
        $this->assertSame('#820ad1', $nubank['cor_fundo']);
        $this->assertSame('#ffffff', $nubank['cor_texto']);
        $this->assertFalse($nubank['padrao']);

        $itau = CartaoCoresPreset::resolver('Itaú Pessoal', 'Itaú');
        $this->assertSame('itau', $itau['chave']);
        $this->assertSame('#003b70', $itau['cor_fundo']);
    }

    public function test_match_por_banco_quando_nome_generico(): void
    {
        $inter = CartaoCoresPreset::resolver('Cartão empresa', 'Inter');
        $this->assertSame('inter', $inter['chave']);
        $this->assertSame('#ff7a00', $inter['cor_fundo']);
    }

    public function test_c6_sofisa_picpay_e_bb(): void
    {
        $this->assertSame('c6', CartaoCoresPreset::resolver('C6 Bank')['chave']);
        $this->assertSame('#111111', CartaoCoresPreset::resolver('C6')['cor_fundo']);
        $this->assertSame('sofisa', CartaoCoresPreset::resolver('Sofisa')['chave']);
        $this->assertSame('#008f5a', CartaoCoresPreset::resolver('Sofisa')['cor_fundo']);
        $this->assertSame('picpay', CartaoCoresPreset::resolver('PicPay')['chave']);
        $this->assertSame('#21c25e', CartaoCoresPreset::resolver('PicPay')['cor_fundo']);
        $this->assertSame('#000000', CartaoCoresPreset::resolver('PicPay')['cor_texto']);

        $bb = CartaoCoresPreset::resolver('Banco do Brasil');
        $this->assertSame('bb', $bb['chave']);
        $this->assertSame('#f8d117', $bb['cor_fundo']);
        $this->assertSame('#003da5', $bb['cor_texto']);
    }

    public function test_alias_magalu_e_pao_de_acucar(): void
    {
        $this->assertSame('magalu', CartaoCoresPreset::resolver('Magazine Luiza')['chave']);
        $this->assertSame('#0086ff', CartaoCoresPreset::resolver('Magalu')['cor_fundo']);
        $this->assertSame('paodeacucar', CartaoCoresPreset::resolver('Pão de Açúcar')['chave']);
        $this->assertSame('#00843d', CartaoCoresPreset::resolver('Pao de Acucar Card')['cor_fundo']);
    }

    public function test_alias_curto_nao_casa_dentro_de_outra_palavra(): void
    {
        $this->assertTrue(CartaoCoresPreset::resolver('XP Investimentos')['chave'] === 'xp');
        $this->assertTrue(CartaoCoresPreset::resolver('Cartão desconhecido')['padrao']);
        $this->assertSame(CartaoCoresPreset::CHAVE_PADRAO, CartaoCoresPreset::resolver('Experiência Gold')['chave']);
    }

    public function test_desconhecido_usa_cinza_claro(): void
    {
        $padrao = CartaoCoresPreset::resolver('Cartão da empresa XYZ');

        $this->assertTrue($padrao['padrao']);
        $this->assertSame('#e5e7eb', $padrao['cor_fundo']);
        $this->assertSame('#111827', $padrao['cor_texto']);
        $this->assertSame($padrao, CartaoCoresPreset::padrao());
    }

    public function test_vazio_usa_padrao(): void
    {
        $this->assertTrue(CartaoCoresPreset::resolver(null, null)['padrao']);
        $this->assertTrue(CartaoCoresPreset::resolver('   ')['padrao']);
    }

    public function test_lookups_incluem_todas_as_variacoes_e_o_padrao(): void
    {
        $pares = CartaoCoresPreset::paresParaLookups();
        $this->assertSame('padrao', $pares[0]['chave']);
        $this->assertCount(count(CartaoCoresPreset::all()) + 1, $pares);

        $chaves = array_column($pares, 'chave');
        $this->assertContains('nubank', $chaves);
        $this->assertContains('shopee', $chaves);
        $this->assertContains('amazon', $chaves);

        $fundos = CartaoCoresPreset::coresFundo();
        $this->assertContains('#e5e7eb', $fundos);
        $this->assertContains('#820ad1', $fundos);
        $this->assertContains('#ee4d2d', $fundos);

        $textos = CartaoCoresPreset::coresTexto();
        $this->assertContains('#ffffff', $textos);
        $this->assertContains('#000000', $textos);
        $this->assertContains('#003da5', $textos);
        $this->assertContains('#111827', $textos);

        $nubank = collect($pares)->firstWhere('chave', 'nubank');
        $this->assertTrue($nubank['importacao_pdf_homologada']);
        $santander = collect($pares)->firstWhere('chave', 'santander');
        $this->assertFalse($santander['importacao_pdf_homologada']);
        $this->assertFalse($pares[0]['importacao_pdf_homologada']);
    }

    public function test_alias_mais_especifico_vence(): void
    {
        $this->assertSame('will', CartaoCoresPreset::resolver('Will Bank')['chave']);
        $this->assertSame('#6c2bd9', CartaoCoresPreset::resolver('Will Bank')['cor_fundo']);
        $this->assertSame('sams', CartaoCoresPreset::resolver("Sam's Club")['chave']);
    }

    public function test_cor_personalizada_nao_entra_nos_swatches(): void
    {
        $pares = CartaoCoresPreset::paresParaLookups();
        $chaves = array_column($pares, 'chave');

        $this->assertSame('padrao', $pares[0]['chave']);
        $this->assertContains('nubank', $chaves);
        $this->assertContains('inter', $chaves);
        $this->assertContains('c6', $chaves);
        $this->assertNotContains('personalizada', $chaves);

        $chip = CartaoCoresPreset::corPersonalizada();
        $this->assertSame('personalizada', $chip['chave']);
        $this->assertSame('Cor personalizada', $chip['label']);
        $this->assertNull($chip['cor_fundo']);
        $this->assertNull($chip['cor_texto']);
    }

    public function test_lookups_do_service_trazem_personalizada_e_presets(): void
    {
        $lookups = (new CartaoService())->handleLookupsCartao();

        $this->assertSame('personalizada', $lookups['cor_personalizada']['chave']);
        $this->assertSame('padrao', $lookups['pares_cores'][0]['chave']);
        $this->assertContains('nubank', array_column($lookups['pares_cores'], 'chave'));
        $this->assertContains('inter', array_column($lookups['pares_cores'], 'chave'));
        $this->assertSame($lookups['cor_padrao']['cor_fundo'], CartaoCoresPreset::COR_PADRAO_FUNDO);
    }

    public function test_hex_livre_persiste_com_texto_por_contraste(): void
    {
        $nubank = CartaoCoresPreset::resolver('Nubank');
        $par = CartaoCoresPreset::resolverParCadastro('#1a2b3c', null, $nubank);

        $this->assertSame('#1a2b3c', $par['cor_fundo']);
        $this->assertSame('#ffffff', $par['cor_texto']);
        $this->assertFalse(CartaoCoresPreset::casaComSwatch('#1a2b3c'));
        $this->assertTrue(CartaoCoresPreset::casaComSwatch('#820ad1'));
        $this->assertSame('#aabbcc', CartaoCoresPreset::expandirHex('#AbC'));
    }

    public function test_sem_cor_continua_auto_apply_do_banco(): void
    {
        $nubank = CartaoCoresPreset::resolver('Nubank Principal');
        $par = CartaoCoresPreset::resolverParCadastro(null, null, $nubank);

        $this->assertSame('#820ad1', $par['cor_fundo']);
        $this->assertSame('#ffffff', $par['cor_texto']);
    }

    public function test_par_enviado_nao_e_sobrescrito_pelo_contraste(): void
    {
        $nubank = CartaoCoresPreset::resolver('Nubank');
        $par = CartaoCoresPreset::resolverParCadastro('#f8d117', '#003da5', $nubank);

        $this->assertSame('#f8d117', $par['cor_fundo']);
        $this->assertSame('#003da5', $par['cor_texto']);
    }

    public function test_texto_por_contraste_claro_e_escuro(): void
    {
        $this->assertSame('#111827', CartaoCoresPreset::corTextoPorContraste('#e5e7eb'));
        $this->assertSame('#111827', CartaoCoresPreset::corTextoPorContraste('#f8d117'));
        $this->assertSame('#ffffff', CartaoCoresPreset::corTextoPorContraste('#111111'));
        $this->assertSame('#ffffff', CartaoCoresPreset::corTextoPorContraste('#820ad1'));
    }
}
