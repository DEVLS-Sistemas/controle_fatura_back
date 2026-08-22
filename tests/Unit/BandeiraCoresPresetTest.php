<?php

namespace Tests\Unit;

use App\Services\Cartao\BandeiraCoresPreset;
use PHPUnit\Framework\TestCase;

class BandeiraCoresPresetTest extends TestCase
{
    public function test_visa_mastercard_elo(): void
    {
        $visa = BandeiraCoresPreset::resolver('Visa');
        $this->assertSame('visa', $visa['chave']);
        $this->assertSame('#1a1f71', $visa['cor_principal']);
        $this->assertSame('#f7b600', $visa['cor_secundaria']);

        $master = BandeiraCoresPreset::resolver('Mastercard');
        $this->assertSame('mastercard', $master['chave']);
        $this->assertSame('#eb001b', $master['cor_principal']);
        $this->assertSame('#ff5f00', $master['cor_secundaria']);

        $elo = BandeiraCoresPreset::resolver('Elo');
        $this->assertSame('elo', $elo['chave']);
        $this->assertSame('#000000', $elo['cor_principal']);
        $this->assertSame('#ffcb05', $elo['cor_secundaria']);
    }

    public function test_amex_legado_e_american_express(): void
    {
        $amex = BandeiraCoresPreset::resolver('Amex');
        $oficial = BandeiraCoresPreset::resolver('American Express');

        $this->assertSame('amex', $amex['chave']);
        $this->assertSame($oficial['cor_principal'], $amex['cor_principal']);
        $this->assertSame('#006fcf', $oficial['cor_principal']);
        $this->assertSame('#ffffff', $oficial['cor_secundaria']);
        $this->assertTrue(BandeiraCoresPreset::isValida('Amex'));
        $this->assertTrue(BandeiraCoresPreset::isValida('American Express'));
        $this->assertContains('American Express', BandeiraCoresPreset::nomesLookups());
        $this->assertNotContains('Amex', BandeiraCoresPreset::nomesLookups());
    }

    public function test_novas_bandeiras(): void
    {
        $this->assertSame('#0079be', BandeiraCoresPreset::resolver('Diners Club')['cor_principal']);
        $this->assertSame('#ff6000', BandeiraCoresPreset::resolver('Discover')['cor_principal']);
        $this->assertSame('#00a94f', BandeiraCoresPreset::resolver('JCB')['cor_principal']);
        $this->assertSame('#d50000', BandeiraCoresPreset::resolver('UnionPay')['cor_principal']);
        $this->assertSame('#ed1c24', BandeiraCoresPreset::resolver('Maestro')['cor_principal']);
        $this->assertSame('#0054a6', BandeiraCoresPreset::resolver('Banricompras')['cor_principal']);
        $this->assertSame('#0066b3', BandeiraCoresPreset::resolver('Aura')['cor_principal']);
        $this->assertSame('#0066a1', BandeiraCoresPreset::resolver('Cabal')['cor_principal']);
        $this->assertSame('#0057a8', BandeiraCoresPreset::resolver('Sorocred')['cor_principal']);
        $this->assertSame('#e31837', BandeiraCoresPreset::resolver('Hipercard')['cor_principal']);
    }

    public function test_outra_e_desconhecida_usam_cinza(): void
    {
        $outra = BandeiraCoresPreset::resolver('Outra');
        $xyz = BandeiraCoresPreset::resolver('Bandeira XYZ');

        $this->assertTrue($outra['padrao']);
        $this->assertTrue($xyz['padrao']);
        $this->assertSame('#e5e7eb', $xyz['cor_principal']);
        $this->assertSame('#9ca3af', $xyz['cor_secundaria']);
    }

    public function test_anexar_respeita_override(): void
    {
        $auto = BandeiraCoresPreset::anexar('Visa');
        $this->assertSame('#1a1f71', $auto['cor_principal']);
        $this->assertFalse($auto['bandeira_padrao']);

        $custom = BandeiraCoresPreset::anexar('Visa', '#111111', '#ffffff');
        $this->assertSame('#111111', $custom['cor_principal']);
        $this->assertSame('#ffffff', $custom['cor_secundaria']);
    }

    public function test_lookups_terminam_com_outra(): void
    {
        $pares = BandeiraCoresPreset::paresParaLookups();
        $ultimo = $pares[array_key_last($pares)];

        $this->assertSame('outra', $ultimo['chave']);
        $this->assertCount(count(BandeiraCoresPreset::all()) + 1, $pares);
        $this->assertContains('Visa', BandeiraCoresPreset::nomesLookups());
        $this->assertContains('Sorocred', BandeiraCoresPreset::nomesLookups());
        $this->assertContains('Outra', BandeiraCoresPreset::nomesLookups());
    }
}
