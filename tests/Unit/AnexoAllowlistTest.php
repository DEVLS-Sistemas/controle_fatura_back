<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoTipoArquivo;
use App\Support\AnexoAllowlist;
use PHPUnit\Framework\TestCase;

class AnexoAllowlistTest extends TestCase
{
    public function test_pdf_de_fatura_e_aceito(): void
    {
        $this->assertTrue(AnexoAllowlist::aceita(
            AnexoOrigem::Fatura,
            'application/pdf',
            'pdf'
        ));
    }

    public function test_csv_de_fatura_e_aceito(): void
    {
        $this->assertTrue(AnexoAllowlist::aceita(
            AnexoOrigem::Fatura,
            'text/csv',
            '.csv'
        ));
    }

    public function test_exe_de_fatura_e_recusado(): void
    {
        $this->assertTrue(AnexoAllowlist::rejeita(
            AnexoOrigem::Fatura,
            'application/x-msdownload',
            'exe'
        ));
        $this->assertFalse(AnexoAllowlist::aceita(
            AnexoOrigem::Fatura,
            'application/octet-stream',
            'exe'
        ));
    }

    public function test_imagem_de_compra_e_aceita(): void
    {
        $this->assertTrue(AnexoAllowlist::aceita(
            AnexoOrigem::Compra,
            'image/png',
            'png'
        ));
    }

    public function test_csv_de_compra_e_recusado(): void
    {
        $this->assertTrue(AnexoAllowlist::rejeita(
            AnexoOrigem::Compra,
            'text/csv',
            'csv'
        ));
    }

    public function test_tipo_de_pdf_de_fatura(): void
    {
        $this->assertSame(
            AnexoTipoArquivo::Pdf,
            AnexoAllowlist::tipo(AnexoOrigem::Fatura, 'application/pdf', 'pdf')
        );
        $this->assertNull(AnexoAllowlist::tipo(AnexoOrigem::Fatura, 'application/x-msdownload', 'exe'));
    }

    public function test_tamanho_maximo_10mb(): void
    {
        $this->assertTrue(AnexoAllowlist::tamanhoPermitido(1024));
        $this->assertTrue(AnexoAllowlist::tamanhoPermitido(AnexoAllowlist::TAMANHO_MAXIMO_BYTES));
        $this->assertFalse(AnexoAllowlist::tamanhoPermitido(AnexoAllowlist::TAMANHO_MAXIMO_BYTES + 1));
        $this->assertFalse(AnexoAllowlist::tamanhoPermitido(0));
        $this->assertFalse(AnexoAllowlist::tamanhoPermitido(null));
    }
}
