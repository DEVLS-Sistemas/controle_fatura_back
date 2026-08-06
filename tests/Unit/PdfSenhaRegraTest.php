<?php

namespace Tests\Unit;

use App\Services\Pdf\PdfSenhaRegra;
use PHPUnit\Framework\TestCase;

class PdfSenhaRegraTest extends TestCase
{
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

    public function test_orientacao_c6(): void
    {
        $texto = PdfSenhaRegra::orientacao(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS);
        $this->assertNotNull($texto);
        $this->assertStringContainsString('6 primeiros', $texto);
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(PdfSenhaRegra::isValid(PdfSenhaRegra::CPF_CNPJ_6_DIGITOS));
        $this->assertTrue(PdfSenhaRegra::isValid(null));
        $this->assertFalse(PdfSenhaRegra::isValid('regra_inventada'));
    }
}
