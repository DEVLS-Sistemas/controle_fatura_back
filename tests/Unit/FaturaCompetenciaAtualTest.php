<?php

namespace Tests\Unit;

use App\Services\Fatura\FaturaService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class FaturaCompetenciaAtualTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_competencia_atual_usa_mes_e_ano_de_hoje(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        $this->assertSame([
            'mes' => 8,
            'ano' => 2026,
            'label' => '08/2026',
        ], FaturaService::competenciaAtual());
    }

    public function test_mes_atual_preenche_filtros_de_mes_e_ano(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 5, 9, 0, 0));

        $atributes = (object) ['mes_atual' => '1'];
        $filtros = FaturaService::aplicarFiltroMesAtual($atributes);

        $this->assertSame(1, $atributes->mes);
        $this->assertSame(2026, $atributes->ano);
        $this->assertSame(1, $filtros['mes']);
        $this->assertSame(2026, $filtros['ano']);
        $this->assertTrue($filtros['mes_atual_ativo']);
        $this->assertSame('01/2026', $filtros['competencia_atual']['label']);
    }

    public function test_mes_atual_sobrescreve_mes_e_ano_enviados(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        $atributes = (object) [
            'mes_atual' => true,
            'mes' => 3,
            'ano' => 2024,
        ];
        FaturaService::aplicarFiltroMesAtual($atributes);

        $this->assertSame(8, $atributes->mes);
        $this->assertSame(2026, $atributes->ano);
    }

    public function test_sem_mes_atual_mantem_filtros_informados(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        $atributes = (object) ['mes' => 3, 'ano' => 2025];
        $filtros = FaturaService::aplicarFiltroMesAtual($atributes);

        $this->assertSame(3, $atributes->mes);
        $this->assertSame(2025, $atributes->ano);
        $this->assertFalse($filtros['mes_atual_ativo']);
    }

    public function test_mes_e_ano_iguais_ao_atual_ativam_o_botao(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        $atributes = (object) ['mes' => 8, 'ano' => 2026];
        $filtros = FaturaService::aplicarFiltroMesAtual($atributes);

        $this->assertTrue($filtros['mes_atual_ativo']);
    }

    public function test_sem_mes_nem_ano_nao_filtra_competencia(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        $atributes = (object) [];
        $filtros = FaturaService::aplicarFiltroMesAtual($atributes);

        $this->assertNull($filtros['mes']);
        $this->assertNull($filtros['ano']);
        $this->assertFalse($filtros['mes_atual_ativo']);
    }
}
