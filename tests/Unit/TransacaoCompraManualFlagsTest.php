<?php

namespace Tests\Unit;

use App\Models\Transacao;
use PHPUnit\Framework\TestCase;

class TransacaoCompraManualFlagsTest extends TestCase
{
    public function test_texto_compra_prioriza_observacoes(): void
    {
        $this->assertSame('Mouse Logitech', Transacao::textoCompraFromRow([
            'observacoes' => '  Mouse Logitech  ',
            'descricao' => 'outro',
        ]));

        $this->assertSame('Mouse Logitech', Transacao::textoCompraFromRow([
            'observacoes' => '',
            'descricao' => 'Mouse Logitech',
        ]));

        $this->assertNull(Transacao::textoCompraFromRow([
            'observacoes' => '  ',
            'descricao' => null,
        ]));
    }

    public function test_compra_manual_e_precisa_conciliar(): void
    {
        $manualPendente = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => false,
            'status_conciliacao' => Transacao::CONCILIACAO_NAO_CONCILIADA,
        ];

        $this->assertTrue(Transacao::isCompraManualRow($manualPendente));
        $this->assertTrue(Transacao::precisaConciliarRow($manualPendente));
        $this->assertSame(
            'Compra manual · conciliar com a fatura',
            Transacao::PRECISA_CONCILIAR_LABEL
        );

        $pdf = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => true,
            'status_conciliacao' => null,
        ];
        $this->assertFalse(Transacao::isCompraManualRow($pdf));
        $this->assertFalse(Transacao::precisaConciliarRow($pdf));

        $conciliada = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => false,
            'status_conciliacao' => Transacao::CONCILIACAO_CONCILIADA,
        ];
        $this->assertTrue(Transacao::isCompraManualRow($conciliada));
        $this->assertFalse(Transacao::precisaConciliarRow($conciliada));
    }
}
