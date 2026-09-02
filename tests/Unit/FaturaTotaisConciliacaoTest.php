<?php

namespace Tests\Unit;

use App\Models\Fatura;
use PHPUnit\Framework\TestCase;

class FaturaTotaisConciliacaoTest extends TestCase
{
    public function test_soma_extrato_com_compras_manuais_abertas(): void
    {
        $payload = Fatura::totaisConciliacaoPayload(3565.87, 177.48);

        $this->assertSame(3565.87, $payload['valor_extrato']);
        $this->assertSame(177.48, $payload['valor_nao_conciliado']);
        $this->assertSame(3743.35, $payload['valor_total_com_pendencias']);
        $this->assertTrue($payload['tem_compras_nao_conciliadas']);
        $this->assertSame('Compras ainda não conciliadas', $payload['compras_nao_conciliadas_label']);
    }

    public function test_sem_pendencia_total_exibido_igual_ao_extrato(): void
    {
        $payload = Fatura::totaisConciliacaoPayload(3565.87, 0);

        $this->assertSame(3565.87, $payload['valor_extrato']);
        $this->assertSame(0.0, $payload['valor_nao_conciliado']);
        $this->assertSame(3565.87, $payload['valor_total_com_pendencias']);
        $this->assertFalse($payload['tem_compras_nao_conciliadas']);
        $this->assertNull($payload['compras_nao_conciliadas_label']);
    }

    public function test_nao_conciliado_negativo_vira_zero(): void
    {
        $payload = Fatura::totaisConciliacaoPayload(100.0, -10.0);

        $this->assertSame(0.0, $payload['valor_nao_conciliado']);
        $this->assertSame(100.0, $payload['valor_total_com_pendencias']);
        $this->assertFalse($payload['tem_compras_nao_conciliadas']);
    }

    public function test_nao_usa_valor_de_quitacao_como_extrato(): void
    {
        $payload = Fatura::totaisConciliacaoPayload(3565.87, 177.48);

        $this->assertNotSame(3623.45, $payload['valor_total_com_pendencias']);
        $this->assertSame(3743.35, $payload['valor_total_com_pendencias']);
    }

    public function test_extrato_processada_usa_valor_da_fatura_nao_soma_das_linhas(): void
    {
        $fatura = new Fatura([
            'status' => 'processada',
            'valor_fatura' => 1909.46,
        ]);

        $this->assertSame(1909.46, $fatura->valorExtratoBase(2368.06));
        $this->assertSame(1909.46, $fatura->valorFaturaTravado());

        $payload = Fatura::totaisConciliacaoPayload(
            $fatura->valorExtratoBase(2368.06),
            0.0
        );
        $this->assertSame(1909.46, $payload['valor_total_com_pendencias']);
    }

    public function test_extrato_processada_soma_so_manuais_nao_conciliadas(): void
    {
        $fatura = new Fatura([
            'status' => 'processada',
            'valor_fatura' => 1909.46,
        ]);

        $payload = Fatura::totaisConciliacaoPayload(
            $fatura->valorExtratoBase(2368.06),
            177.48
        );

        $this->assertSame(1909.46, $payload['valor_extrato']);
        $this->assertSame(177.48, $payload['valor_nao_conciliado']);
        $this->assertSame(2086.94, $payload['valor_total_com_pendencias']);
    }

    public function test_extrato_pendente_usa_soma_das_linhas(): void
    {
        $fatura = new Fatura([
            'status' => 'pendente',
            'valor_fatura' => null,
        ]);

        $this->assertNull($fatura->valorFaturaTravado());
        $this->assertSame(458.60, $fatura->valorExtratoBase(458.60));
    }
}
