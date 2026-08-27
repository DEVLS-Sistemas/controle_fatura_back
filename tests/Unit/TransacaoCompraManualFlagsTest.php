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

    public function test_compra_manual_cadastrada_precisa_conciliar(): void
    {
        $manualPendente = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => true,
            'importada_pdf' => false,
            'status_conciliacao' => Transacao::CONCILIACAO_NAO_CONCILIADA,
        ];

        $this->assertTrue(Transacao::isCompraManualRow($manualPendente));
        $this->assertTrue(Transacao::precisaConciliarRow($manualPendente));
        $this->assertSame(
            'Compra manual · conciliar com a fatura',
            Transacao::PRECISA_CONCILIAR_LABEL
        );

        $manualPendente['status_conciliacao'] = Transacao::CONCILIACAO_PENDENTE;
        $this->assertTrue(Transacao::precisaConciliarRow($manualPendente));
    }

    public function test_lancamento_pdf_nao_precisa_conciliar(): void
    {
        $pdf = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => false,
            'importada_pdf' => true,
            'status_conciliacao' => null,
        ];
        $this->assertFalse(Transacao::isCompraManualRow($pdf));
        $this->assertFalse(Transacao::precisaConciliarRow($pdf));
    }

    public function test_parcela_automatica_de_fatura_sem_anexo_nao_precisa_conciliar(): void
    {
        $automatica = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => false,
            'importada_pdf' => false,
            'status_conciliacao' => null,
        ];

        $this->assertFalse(Transacao::isCompraManualRow($automatica));
        $this->assertFalse(Transacao::precisaConciliarRow($automatica));

        $automaticaComStatusLegado = $automatica;
        $automaticaComStatusLegado['status_conciliacao'] = Transacao::CONCILIACAO_NAO_CONCILIADA;
        $this->assertFalse(Transacao::isCompraManualRow($automaticaComStatusLegado));
        $this->assertFalse(Transacao::precisaConciliarRow($automaticaComStatusLegado));
    }

    public function test_compra_manual_conciliada_nao_pede_conciliar(): void
    {
        $conciliada = [
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => true,
            'importada_pdf' => false,
            'status_conciliacao' => Transacao::CONCILIACAO_CONCILIADA,
        ];
        $this->assertTrue(Transacao::isCompraManualRow($conciliada));
        $this->assertFalse(Transacao::precisaConciliarRow($conciliada));
    }

    public function test_fallback_sem_campo_compra_manual_usa_importada_pdf(): void
    {
        $this->assertTrue(Transacao::isCompraManualRow([
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => false,
        ]));
        $this->assertFalse(Transacao::isCompraManualRow([
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => true,
        ]));
    }
}
