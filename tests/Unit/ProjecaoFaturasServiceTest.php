<?php

namespace Tests\Unit;

use App\Models\Transacao;
use App\Services\Dashboard\ProjecaoFaturasService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ProjecaoFaturasServiceTest extends TestCase
{
    private ProjecaoFaturasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjecaoFaturasService();
    }

    public function test_build_colunas_comeca_no_mes_anterior(): void
    {
        $colunas = $this->service->buildColunas(Carbon::create(2026, 7, 1));

        $this->assertCount(13, $colunas);
        $this->assertSame('2026-06', $colunas[0]['chave']);
        $this->assertSame('Jun/2026', $colunas[0]['label']);
        $this->assertFalse($colunas[0]['referencia']);
        $this->assertTrue($colunas[1]['referencia']);
        $this->assertSame('2026-07', $colunas[1]['chave']);
        $this->assertSame('2027-06', $colunas[12]['chave']);
    }

    public function test_build_colunas_virada_de_ano(): void
    {
        $colunas = $this->service->buildColunas(Carbon::create(2026, 1, 1));

        $this->assertSame('2025-12', $colunas[0]['chave']);
        $this->assertSame('2026-12', $colunas[12]['chave']);
    }
}
