<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Enums\AnexoTipoArquivo;
use PHPUnit\Framework\TestCase;

class AnexoEnumsTest extends TestCase
{
    public function test_origem_tem_valor_e_label(): void
    {
        $this->assertSame('fatura', AnexoOrigem::Fatura->value);
        $this->assertSame('Fatura', AnexoOrigem::Fatura->label());
        $this->assertSame('compra', AnexoOrigem::Compra->value);
        $this->assertSame('Compra', AnexoOrigem::Compra->label());
    }

    public function test_status_tem_valor_e_label(): void
    {
        $this->assertSame('pendente', AnexoStatus::Pendente->value);
        $this->assertSame('Pendente', AnexoStatus::Pendente->label());
        $this->assertSame('excluido', AnexoStatus::Excluido->value);
        $this->assertSame('Excluído', AnexoStatus::Excluido->label());
    }

    public function test_tipo_arquivo_tem_valor_e_label(): void
    {
        $this->assertSame('pdf', AnexoTipoArquivo::Pdf->value);
        $this->assertSame('PDF', AnexoTipoArquivo::Pdf->label());
        $this->assertSame('csv', AnexoTipoArquivo::Csv->value);
        $this->assertSame('CSV', AnexoTipoArquivo::Csv->label());
        $this->assertSame('imagem', AnexoTipoArquivo::Imagem->value);
        $this->assertSame('Imagem', AnexoTipoArquivo::Imagem->label());
        $this->assertSame('outro', AnexoTipoArquivo::Outro->value);
        $this->assertSame('Outro', AnexoTipoArquivo::Outro->label());
    }
}
