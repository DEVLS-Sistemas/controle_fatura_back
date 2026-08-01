<?php

namespace Tests\Unit;

use App\Services\Transacao\TransacaoService;
use Exception;
use PHPUnit\Framework\TestCase;

class TransacaoParcelasTest extends TestCase
{
    private TransacaoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransacaoService();
    }

    public function test_split_igual_com_centavos_na_ultima(): void
    {
        $valores = $this->service->splitValorEmParcelas(100.00, 3);

        $this->assertSame([33.33, 33.33, 33.34], $valores);
        $this->assertEqualsWithDelta(100.00, array_sum($valores), 0.001);
    }

    public function test_split_dez_parcelas_exatas(): void
    {
        $valores = $this->service->splitValorEmParcelas(1000.00, 10);

        $this->assertCount(10, $valores);
        $this->assertSame(100.0, $valores[0]);
        $this->assertSame(100.0, $valores[9]);
        $this->assertEqualsWithDelta(1000.00, array_sum($valores), 0.001);
    }

    public function test_resolve_com_valor_compra_divide_igual(): void
    {
        $valores = $this->service->resolveValoresParcelas((object) [
            'valor_compra' => '1000,00',
            'parcelas_total' => 10,
        ], 10);

        $this->assertCount(10, $valores);
        $this->assertEqualsWithDelta(1000.00, array_sum($valores), 0.001);
        $this->assertSame(100.0, $valores[0]);
    }

    public function test_resolve_legado_valor_por_parcela(): void
    {
        $valores = $this->service->resolveValoresParcelas((object) [
            'valor' => 100,
            'parcelas_total' => 10,
        ], 10);

        $this->assertCount(10, $valores);
        $this->assertSame(100.0, $valores[0]);
        $this->assertEqualsWithDelta(1000.00, array_sum($valores), 0.001);
    }

    public function test_resolve_avista_com_valor(): void
    {
        $valores = $this->service->resolveValoresParcelas((object) [
            'valor' => '150,90',
        ], 1);

        $this->assertSame([150.9], $valores);
    }

    public function test_resolve_com_parcelas_custom_ok(): void
    {
        $valores = $this->service->resolveValoresParcelas((object) [
            'valor_compra' => '100,00',
            'parcelas' => [
                ['parcela' => 1, 'valor' => '40,00'],
                ['parcela' => 2, 'valor' => '30,00'],
                ['parcela' => 3, 'valor' => '30,00'],
            ],
        ], 3);

        $this->assertSame([40.0, 30.0, 30.0], $valores);
    }

    public function test_resolve_soma_invalida_lanca_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('soma das parcelas');

        $this->service->resolveValoresParcelas((object) [
            'valor_compra' => '100,00',
            'parcelas' => [
                ['parcela' => 1, 'valor' => '50,00'],
                ['parcela' => 2, 'valor' => '40,00'],
            ],
        ], 2);
    }

    public function test_parse_parcelas_duplicada(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Parcela 1 duplicada');

        $this->service->parseParcelasArray([
            ['parcela' => 1, 'valor' => 50],
            ['parcela' => 1, 'valor' => 50],
        ], 2);
    }

    public function test_parse_parcelas_quantidade_errada(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('quantidade de parcelas informadas');

        $this->service->parseParcelasArray([
            ['parcela' => 1, 'valor' => 100],
        ], 3);
    }
}
