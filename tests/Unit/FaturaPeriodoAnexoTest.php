<?php

namespace Tests\Unit;

use App\Services\Fatura\FaturaService;
use PHPUnit\Framework\TestCase;

class FaturaPeriodoAnexoTest extends TestCase
{
    public function test_diverge_quando_mesmo_mes_e_ano_diferente(): void
    {
        $this->assertTrue(FaturaService::periodoDetectadoDiverge(7, 2024, 7, 2026));
    }

    public function test_nao_diverge_quando_competencia_igual(): void
    {
        $this->assertFalse(FaturaService::periodoDetectadoDiverge(8, 2026, 8, 2026));
    }

    public function test_nao_diverge_sem_ano_detectado(): void
    {
        $this->assertFalse(FaturaService::periodoDetectadoDiverge(7, null, 7, 2026));
    }

    public function test_stub_restaurado_nao_traz_pdf_nem_status_processada(): void
    {
        $attrs = FaturaService::atributosStubSemAnexo();

        $this->assertNull($attrs['arquivo_pdf']);
        $this->assertNull($attrs['arquivo_csv']);
        $this->assertSame('pendente', $attrs['status']);
        $this->assertNull($attrs['processado_em']);
    }
}
