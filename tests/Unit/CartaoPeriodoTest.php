<?php

namespace Tests\Unit;

use App\Models\Cartao;
use PHPUnit\Framework\TestCase;

class CartaoPeriodoTest extends TestCase
{
    public function test_periodo_fatura_antes_e_depois_do_limite(): void
    {
        $cartao = new Cartao([
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 12,
        ]);

        $this->assertSame(['mes' => 8, 'ano' => 2026], $cartao->periodoFaturaParaData('2026-08-05'));
        $this->assertSame(['mes' => 9, 'ano' => 2026], $cartao->periodoFaturaParaData('2026-08-06'));
    }

    public function test_intervalo_periodo_com_limite_no_mesmo_mes_do_vencimento(): void
    {
        $cartao = new Cartao([
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 12,
        ]);

        $intervalo = $cartao->intervaloPeriodoFatura(8, 2026);

        $this->assertSame('2026-07-06', $intervalo['periodo_inicio']);
        $this->assertSame('2026-08-05', $intervalo['periodo_fim']);
        $this->assertSame('2026-08-12', $intervalo['data_vencimento']);
    }

    public function test_intervalo_periodo_com_vencimento_no_mes_seguinte(): void
    {
        $cartao = new Cartao([
            'dia_limite_fatura' => 25,
            'dia_vencimento_fatura' => 5,
        ]);

        $intervalo = $cartao->intervaloPeriodoFatura(8, 2026);

        $this->assertSame('2026-07-26', $intervalo['periodo_inicio']);
        $this->assertSame('2026-08-25', $intervalo['periodo_fim']);
        $this->assertSame('2026-09-05', $intervalo['data_vencimento']);
    }

    public function test_intervalo_legado_sem_dia_limite_usa_mes_calendario(): void
    {
        $cartao = new Cartao([
            'dia_limite_fatura' => null,
            'dia_vencimento_fatura' => 10,
        ]);

        $intervalo = $cartao->intervaloPeriodoFatura(2, 2026);

        $this->assertSame('2026-02-01', $intervalo['periodo_inicio']);
        $this->assertSame('2026-02-28', $intervalo['periodo_fim']);
        $this->assertSame('2026-02-10', $intervalo['data_vencimento']);
    }
}
