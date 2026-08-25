<?php

namespace Tests\Unit;

use App\Services\Dashboard\DashboardService;
use Exception;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase
{
    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
    }

    public function test_sem_filtro_consolida_o_ano(): void
    {
        $periodo = $this->service->resolverPeriodo((object) ['ano' => 2026]);

        $this->assertSame(2026, $periodo['ano']);
        $this->assertNull($periodo['mes']);
        $this->assertNull($periodo['mes_inicio']);
        $this->assertNull($periodo['mes_fim']);
        $this->assertSame('ano', $periodo['tipo']);
        $this->assertSame('2026', $periodo['label']);
        $this->assertSame(range(1, 12), $periodo['meses']);
        $this->assertNull($periodo['mes_inicio_filtro']);
        $this->assertNull($periodo['mes_fim_filtro']);
    }

    public function test_mes_especifico_via_mes(): void
    {
        $periodo = $this->service->resolverPeriodo((object) ['ano' => 2026, 'mes' => 7]);

        $this->assertSame(7, $periodo['mes']);
        $this->assertSame(7, $periodo['mes_inicio']);
        $this->assertSame(7, $periodo['mes_fim']);
        $this->assertSame('mes', $periodo['tipo']);
        $this->assertSame('Julho 2026', $periodo['label']);
        $this->assertSame([7], $periodo['meses']);
    }

    public function test_intervalo_entre_meses(): void
    {
        $periodo = $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes_inicio' => 3,
            'mes_fim' => 6,
        ]);

        $this->assertNull($periodo['mes']);
        $this->assertSame(3, $periodo['mes_inicio']);
        $this->assertSame(6, $periodo['mes_fim']);
        $this->assertSame('intervalo', $periodo['tipo']);
        $this->assertSame('Março – Junho 2026', $periodo['label']);
        $this->assertSame([3, 4, 5, 6], $periodo['meses']);
        $this->assertSame(3, $periodo['mes_inicio_filtro']);
        $this->assertSame(6, $periodo['mes_fim_filtro']);
    }

    public function test_intervalo_de_um_mes_vira_mes_especifico(): void
    {
        $periodo = $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes_inicio' => 8,
            'mes_fim' => 8,
        ]);

        $this->assertSame('mes', $periodo['tipo']);
        $this->assertSame(8, $periodo['mes']);
        $this->assertSame('Agosto 2026', $periodo['label']);
    }

    public function test_intervalo_janeiro_a_dezembro_vira_ano(): void
    {
        $periodo = $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes_inicio' => 1,
            'mes_fim' => 12,
        ]);

        $this->assertSame('ano', $periodo['tipo']);
        $this->assertNull($periodo['mes']);
        $this->assertSame('2026', $periodo['label']);
    }

    public function test_intervalo_tem_precedencia_sobre_mes(): void
    {
        $periodo = $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes' => 7,
            'mes_inicio' => 3,
            'mes_fim' => 6,
        ]);

        $this->assertSame('intervalo', $periodo['tipo']);
        $this->assertSame([3, 4, 5, 6], $periodo['meses']);
    }

    public function test_mes_fim_menor_que_inicio_lanca_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('mes_fim deve ser maior ou igual a mes_inicio');
        $this->expectExceptionCode(422);

        $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes_inicio' => 6,
            'mes_fim' => 3,
        ]);
    }

    public function test_mes_invalido_lanca_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('mes deve ser um mês entre 1 e 12');
        $this->expectExceptionCode(422);

        $this->service->resolverPeriodo((object) ['ano' => 2026, 'mes' => 13]);
    }

    public function test_ano_invalido_lanca_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ano inválido');
        $this->expectExceptionCode(422);

        $this->service->resolverPeriodo((object) ['ano' => 1999]);
    }

    public function test_so_mes_inicio_vai_ate_dezembro(): void
    {
        $periodo = $this->service->resolverPeriodo((object) [
            'ano' => 2026,
            'mes_inicio' => 10,
        ]);

        $this->assertSame('intervalo', $periodo['tipo']);
        $this->assertSame(10, $periodo['mes_inicio']);
        $this->assertSame(12, $periodo['mes_fim']);
        $this->assertSame('Outubro – Dezembro 2026', $periodo['label']);
    }
}
