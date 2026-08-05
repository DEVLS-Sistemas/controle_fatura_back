<?php

namespace Tests\Unit;

use App\Models\Repasse;
use App\Services\Repasse\RepasseService;
use Carbon\Carbon;
use Exception;
use PHPUnit\Framework\TestCase;

class RepasseServiceTest extends TestCase
{
    private RepasseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RepasseService();
    }

    public function test_status_pendente_quando_sem_pagamento(): void
    {
        $this->assertSame(Repasse::STATUS_PENDENTE, $this->service->resolveStatusRepasse(100, 0));
    }

    public function test_status_parcial(): void
    {
        $this->assertSame(Repasse::STATUS_PARCIAL, $this->service->resolveStatusRepasse(100, 40));
    }

    public function test_status_pago_exato(): void
    {
        $this->assertSame(Repasse::STATUS_PAGO, $this->service->resolveStatusRepasse(100, 100));
    }

    public function test_status_pago_com_tolerancia_centavo(): void
    {
        $this->assertSame(Repasse::STATUS_PAGO, $this->service->resolveStatusRepasse(100, 99.995));
    }

    public function test_compute_status_parcela(): void
    {
        $status = $this->service->computeStatusParcela(300, 150);

        $this->assertSame(300.0, $status['valor_devido']);
        $this->assertSame(150.0, $status['valor_pago']);
        $this->assertSame(150.0, $status['valor_aberto']);
        $this->assertSame(Repasse::STATUS_PARCIAL, $status['status_repasse']);
    }

    public function test_compute_status_parcela_quitada(): void
    {
        $status = $this->service->computeStatusParcela(300, 300);

        $this->assertSame(0.0, $status['valor_aberto']);
        $this->assertSame(Repasse::STATUS_PAGO, $status['status_repasse']);
    }

    public function test_chave_compra_com_grupo(): void
    {
        $this->assertSame('abc-uuid', $this->service->chaveCompra('abc-uuid', 10));
    }

    public function test_chave_compra_avista(): void
    {
        $this->assertSame('t:42', $this->service->chaveCompra(null, 42));
        $this->assertSame('t:42', $this->service->chaveCompra('', 42));
    }

    public function test_build_colunas_13_meses(): void
    {
        $colunas = $this->service->buildColunas(Carbon::create(2026, 8, 1), 13);

        $this->assertCount(13, $colunas);
        $this->assertSame('2026-07', $colunas[0]['chave']);
        $this->assertSame('2026-08', $colunas[1]['chave']);
        $this->assertTrue($colunas[1]['referencia']);
        $this->assertFalse($colunas[0]['referencia']);
        $this->assertSame('Jul/2026', $colunas[0]['label']);
    }

    public function test_parse_valor_br(): void
    {
        $this->assertSame(150.5, $this->service->parseValor('150,50'));
        $this->assertSame(1234.56, $this->service->parseValor('1.234,56'));
    }

    public function test_parse_valor_invalido(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Valor inválido');
        $this->service->parseValor('abc');
    }
}
