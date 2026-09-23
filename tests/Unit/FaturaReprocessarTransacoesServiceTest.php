<?php

namespace Tests\Unit;

use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Transacao;
use App\Services\Fatura\FaturaReprocessarTransacoesService;
use PHPUnit\Framework\TestCase;

class FaturaReprocessarTransacoesServiceTest extends TestCase
{
    public function test_pdf_parcial_para_fechado_atualiza_cria_remove_e_preserva_manual(): void
    {
        $svc = new FaturaReprocessarTransacoesService();

        $padaria = $this->transacao(1, 10, 10.00, importada: true);
        $mercado = $this->transacao(2, 11, 20.00, importada: true);
        $cafe = $this->transacao(3, 12, 5.00, importada: true);
        $uberSumiu = $this->transacao(4, 13, 8.00, importada: true);
        $presenteManual = $this->transacao(5, 14, 12.00, importada: false, manual: true);

        $pdfFechado = [
            ['estabelecimento_id' => 10, 'valor' => 10.00],
            ['estabelecimento_id' => 11, 'valor' => 20.00],
            ['estabelecimento_id' => 12, 'valor' => 5.00],
            ['estabelecimento_id' => 20, 'valor' => 40.00],
            ['estabelecimento_id' => 21, 'valor' => 15.00],
        ];

        $resultado = $svc->classificar(
            collect([$padaria, $mercado, $cafe, $uberSumiu, $presenteManual]),
            $pdfFechado
        );

        $this->assertSame([1, 2, 3], $resultado['atualizar_ids']);
        $this->assertCount(2, $resultado['criar']);
        $this->assertSame(20, $resultado['criar'][0]['estabelecimento_id']);
        $this->assertSame(21, $resultado['criar'][1]['estabelecimento_id']);
        $this->assertSame([4], $resultado['remover_ids']);
        $this->assertSame([5], $resultado['preservar_manuais_ids']);

        $totalFechado = ProcessInvoicePdfJob::calculateValorTotal($pdfFechado);
        $this->assertSame(90.0, $totalFechado);
        $this->assertGreaterThan(
            ProcessInvoicePdfJob::calculateValorTotal([
                ['valor' => 10.00],
                ['valor' => 20.00],
                ['valor' => 5.00],
            ]),
            $totalFechado
        );
    }

    public function test_match_mantem_o_mesmo_id_e_nao_duplica(): void
    {
        $svc = new FaturaReprocessarTransacoesService();
        $existente = $this->transacao(77, 10, 49.90, importada: true);
        $existente->categoria_id = 3;
        $existente->responsavel_id = 8;

        $match = $svc->findMatchingTransacao(collect([$existente]), [], 10, 49.90, null, null);

        $this->assertSame(77, $match?->id);

        $update = $svc->camposAtualizacaoDoMatch(
            $existente,
            ['data' => '2026-09-10', 'tipo' => Transacao::TIPO_PURCHASE],
            49.90,
            591,
            null,
            99,
            null
        );

        $this->assertSame('2026-09-10', $update['data']);
        $this->assertSame(49.90, $update['valor']);
        $this->assertArrayNotHasKey('categoria_id', $update);
        $this->assertArrayNotHasKey('responsavel_id', $update);
        $this->assertArrayNotHasKey('subcategoria_id', $update);
        $this->assertArrayNotHasKey('estabelecimento_id', $update);

        $updateComEstabelecimento = $svc->camposAtualizacaoDoMatch(
            $existente,
            ['data' => '2026-09-10', 'tipo' => Transacao::TIPO_PURCHASE],
            49.90,
            591,
            null,
            99,
            null,
            44
        );
        $this->assertSame(44, $updateComEstabelecimento['estabelecimento_id']);
    }

    public function test_responsavel_so_preenche_quando_estava_vazio(): void
    {
        $svc = new FaturaReprocessarTransacoesService();
        $semResponsavel = $this->transacao(1, 10, 10.00, importada: true);
        $semResponsavel->responsavel_id = null;

        $update = $svc->camposAtualizacaoDoMatch(
            $semResponsavel,
            ['tipo' => Transacao::TIPO_PURCHASE],
            10.00,
            591,
            null,
            42,
            null
        );

        $this->assertSame(42, $update['responsavel_id']);
    }

    public function test_nao_remove_kept_nem_manual(): void
    {
        $this->assertFalse(FaturaReprocessarTransacoesService::deveRemoverNoReprocesso(true, true, false, false));
        $this->assertFalse(FaturaReprocessarTransacoesService::deveRemoverNoReprocesso(false, false, true, false));
        $this->assertFalse(FaturaReprocessarTransacoesService::deveRemoverNoReprocesso(false, false, false, true));
        $this->assertTrue(FaturaReprocessarTransacoesService::deveRemoverNoReprocesso(false, true, false, false));
        $this->assertTrue(FaturaReprocessarTransacoesService::deveRemoverNoReprocesso(false, false, false, false));
    }

    private function transacao(
        int $id,
        int $estabelecimentoId,
        float $valor,
        bool $importada,
        bool $manual = false
    ): Transacao {
        $t = new Transacao([
            'estabelecimento_id' => $estabelecimentoId,
            'valor' => $valor,
            'parcela_atual' => null,
            'parcelas_total' => null,
            'importada_pdf' => $importada,
            'compra_manual' => $manual,
            'criada_como_manual' => $manual,
        ]);
        $t->id = $id;

        return $t;
    }
}
