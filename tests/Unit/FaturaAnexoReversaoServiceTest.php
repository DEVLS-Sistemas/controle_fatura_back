<?php

namespace Tests\Unit;

use App\Services\Fatura\FaturaAnexoReversaoService;
use PHPUnit\Framework\TestCase;

class FaturaAnexoReversaoServiceTest extends TestCase
{
    public function test_avisos_com_lancamentos_parcelas_compras_e_stub(): void
    {
        $avisos = FaturaAnexoReversaoService::montarAvisos(42, 18, 1, [
            ['id' => 74, 'competencia' => '09/2026'],
        ]);

        $this->assertSame([
            '42 lançamentos importados deste PDF serão apagados nesta fatura.',
            '18 parcelas automáticas em faturas anteriores/futuras serão apagadas.',
            'A fatura 09/2026 ficará vazia e será removida.',
            '1 compra manual volta a precisar de conciliação.',
        ], $avisos);
    }

    public function test_avisos_singular(): void
    {
        $avisos = FaturaAnexoReversaoService::montarAvisos(1, 1, 2, []);

        $this->assertSame([
            '1 lançamento importado deste PDF será apagado nesta fatura.',
            '1 parcela automática em faturas anteriores/futuras será apagada.',
            '2 compras manuais voltam a precisar de conciliação.',
        ], $avisos);
    }

    public function test_avisos_vazios_tem_fallback(): void
    {
        $avisos = FaturaAnexoReversaoService::montarAvisos(0, 0, 0, []);

        $this->assertCount(1, $avisos);
        $this->assertSame(
            'O anexo será removido. Nenhuma parcela gerada nem compra conciliada foi encontrada.',
            $avisos[0]
        );
    }
}
