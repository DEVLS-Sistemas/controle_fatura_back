<?php

namespace Tests\Unit;

use App\Services\Pdf\PdfSenhaRegra;
use PHPUnit\Framework\TestCase;

class PdfSenhaRegraTest extends TestCase
{
    public function test_catalogo_tem_todas_as_regras(): void
    {
        $values = collect(PdfSenhaRegra::all())->pluck('value')->all();

        $this->assertSame([
            PdfSenhaRegra::CPF_CNPJ_4_DIGITOS,
            PdfSenhaRegra::CPF_CNPJ_5_DIGITOS,
            PdfSenhaRegra::CPF_CNPJ_6_DIGITOS,
            PdfSenhaRegra::CPF_CNPJ_8_DIGITOS,
            PdfSenhaRegra::CPF_11_DIGITOS,
            PdfSenhaRegra::CNPJ_14_DIGITOS,
        ], $values);
    }

    public function test_sugerir_por_banco_c6(): void
    {
        $this->assertSame(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS, PdfSenhaRegra::sugerirPorBanco('C6'));
        $this->assertSame(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS, PdfSenhaRegra::sugerirPorBanco('C6 Bank'));
        $this->assertSame(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS, PdfSenhaRegra::sugerirPorBanco('c6bank'));
    }

    public function test_sugerir_por_banco_desconhecido(): void
    {
        $this->assertNull(PdfSenhaRegra::sugerirPorBanco('Nubank'));
        $this->assertNull(PdfSenhaRegra::sugerirPorBanco(null));
    }

    public function test_orientacao_e_digitos(): void
    {
        $this->assertStringContainsString('6 primeiros', (string) PdfSenhaRegra::orientacao(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS));
        $this->assertSame(4, PdfSenhaRegra::digitos(PdfSenhaRegra::CPF_CNPJ_4_DIGITOS));
        $this->assertSame(5, PdfSenhaRegra::digitos(PdfSenhaRegra::CPF_CNPJ_5_DIGITOS));
        $this->assertSame(8, PdfSenhaRegra::digitos(PdfSenhaRegra::CPF_CNPJ_8_DIGITOS));
        $this->assertSame(11, PdfSenhaRegra::digitos(PdfSenhaRegra::CPF_11_DIGITOS));
        $this->assertSame(14, PdfSenhaRegra::digitos(PdfSenhaRegra::CNPJ_14_DIGITOS));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(PdfSenhaRegra::isValid(PdfSenhaRegra::CPF_CNPJ_4_DIGITOS));
        $this->assertTrue(PdfSenhaRegra::isValid(PdfSenhaRegra::CPF_11_DIGITOS));
        $this->assertTrue(PdfSenhaRegra::isValid(PdfSenhaRegra::CNPJ_14_DIGITOS));
        $this->assertTrue(PdfSenhaRegra::isValid(null));
        $this->assertFalse(PdfSenhaRegra::isValid('regra_inventada'));
    }
}
