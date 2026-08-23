<?php

namespace Tests\Unit;

use App\Services\Estabelecimento\EstabelecimentoEstatisticasService;
use PHPUnit\Framework\TestCase;

class EstabelecimentoEstatisticasServiceTest extends TestCase
{
    private EstabelecimentoEstatisticasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EstabelecimentoEstatisticasService();
    }

    public function test_vinte_compras_em_sessenta_dias_e_uma_vez_a_cada_tres_dias(): void
    {
        $freq = $this->service->buildFrequencia(20, 60);

        $this->assertSame(20, $freq['compras']);
        $this->assertSame(60, $freq['periodo_dias']);
        $this->assertSame(3.0, $freq['intervalo_medio_dias']);
        $this->assertSame('1 vez a cada 3 dias', $freq['label']);
        $this->assertEqualsWithDelta(2.33, $freq['por_semana'], 0.05);
    }

    public function test_label_uma_vez_por_semana_e_por_mes(): void
    {
        $this->assertSame('1 vez por semana', $this->service->labelFrequencia(4, 7.0));
        $this->assertSame('1 vez a cada 2 semanas', $this->service->labelFrequencia(4, 14.0));
        $this->assertSame('1 vez por mês', $this->service->labelFrequencia(3, 30.0));
        $this->assertSame('1 vez por dia', $this->service->labelFrequencia(10, 1.0));
    }

    public function test_sem_compras_e_compra_unica(): void
    {
        $vazio = $this->service->buildFrequencia(0, 30);
        $this->assertSame('Nenhuma compra no período', $vazio['label']);
        $this->assertNull($vazio['intervalo_medio_dias']);

        $uma = $this->service->buildFrequencia(1, 90);
        $this->assertSame('1 compra no período', $uma['label']);
        $this->assertSame(90.0, $uma['intervalo_medio_dias']);
    }

    public function test_ticket_medio_e_soma_da_loja(): void
    {
        $periodo = ['inicio' => '2026-01-01', 'fim' => '2026-03-01', 'origem' => 'filtro', 'dias' => 60];
        $a = $this->service->montarEstatistica(10, 12, 1000.0, '2026-01-05', '2026-02-20', $periodo);
        $b = $this->service->montarEstatistica(10, 10, 500.0, '2026-01-10', '2026-02-28', $periodo);

        $this->assertSame(100.0, $a['ticket_medio']);
        $this->assertSame(12, $a['ocorrencias']);
        $this->assertSame('1 vez a cada 6 dias', $a['frequencia']['label']);

        $totais = $this->service->somarEstatisticas([$a, $b], $periodo);
        $this->assertSame(20, $totais['compras']);
        $this->assertSame(22, $totais['ocorrencias']);
        $this->assertSame(1500.0, $totais['valor_total']);
        $this->assertSame(75.0, $totais['ticket_medio']);
        $this->assertSame('2026-01-05', $totais['primeira_compra']);
        $this->assertSame('2026-02-28', $totais['ultima_compra']);
        $this->assertSame('1 vez a cada 3 dias', $totais['frequencia']['label']);
    }

    public function test_filtro_de_periodo(): void
    {
        $this->assertTrue($this->service->temFiltroPeriodo((object) ['mes' => 8, 'ano' => 2026]));
        $this->assertTrue($this->service->temFiltroPeriodo((object) ['data_inicio' => '2026-01-01']));
        $this->assertFalse($this->service->temFiltroPeriodo((object) []));
        $this->assertFalse($this->service->temFiltroPeriodo((object) ['mes' => 8]));
    }
}
