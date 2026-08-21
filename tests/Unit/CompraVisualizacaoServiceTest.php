<?php

namespace Tests\Unit;

use App\Models\Repasse;
use App\Services\Transacao\CompraVisualizacaoService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CompraVisualizacaoServiceTest extends TestCase
{
    private CompraVisualizacaoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompraVisualizacaoService();
    }

    public function test_status_parcela_paga_atual_aberta(): void
    {
        $refKey = (2026 * 12) + 8;

        $this->assertSame(
            CompraVisualizacaoService::STATUS_PARCELA_PAGA,
            $this->service->resolveStatusParcela((2026 * 12) + 7, $refKey)
        );
        $this->assertSame(
            CompraVisualizacaoService::STATUS_PARCELA_ATUAL,
            $this->service->resolveStatusParcela($refKey, $refKey)
        );
        $this->assertSame(
            CompraVisualizacaoService::STATUS_PARCELA_ABERTA,
            $this->service->resolveStatusParcela((2026 * 12) + 9, $refKey)
        );
    }

    public function test_detalhe_concentra_categoria_cartao_e_parcelas(): void
    {
        $grupo = $this->makeGrupo('g-tv', 'TV 55', 'Fast Shop', 12, 3, 250.0, 8, 2026);
        $detalhe = $this->service->buildDetalheFromGrupo($grupo, 8, 2026);

        $this->assertSame('g-tv', $detalhe['compra_grupo_id']);
        $this->assertSame('TV 55', $detalhe['titulo']);
        $this->assertSame('2026-06-10', $detalhe['data_compra']);
        $this->assertFalse($detalhe['avista']);
        $this->assertSame(3, $detalhe['parcelas_pagas']);
        $this->assertSame(9, $detalhe['parcelas_restantes']);
        $this->assertSame(12, $detalhe['parcelas_total']);
        $this->assertCount(12, $detalhe['parcelas']);

        $this->assertSame('Casa', $detalhe['categoria']['nome']);
        $this->assertSame('#22c55e', $detalhe['categoria']['cor']);
        $this->assertSame('Eletro', $detalhe['subcategoria']['nome']);
        $this->assertSame('Nubank', $detalhe['cartao']['nome']);
        $this->assertSame('#8b5cf6', $detalhe['cartao']['cor_fundo']);
        $this->assertSame('Mastercard', $detalhe['bandeira']['nome']);
        $this->assertSame('1234', $detalhe['cartao_numero']['ultimos_digitos']);
        $this->assertSame('Físico', $detalhe['cartao_numero']['tipo_label']);
        $this->assertSame('Magazine Luiza', $detalhe['estabelecimento']['loja_nome']);
        $this->assertSame('Eu', $detalhe['responsavel']['nome']);
        $this->assertSame('Compras presencial', $detalhe['origem_compra_label']);
        $this->assertSame('Mai/2027', $detalhe['estimativa_termino']);
        $this->assertSame(8, $detalhe['referencia']['mes']);
        $this->assertSame(2026, $detalhe['referencia']['ano']);
    }

    public function test_parcelas_marcam_pagas_atual_e_abertas(): void
    {
        $grupo = $this->makeGrupo('g1', 'Geladeira', 'Magazine', 4, 2, 100.0, 8, 2026);
        $detalhe = $this->service->buildDetalheFromGrupo($grupo, 8, 2026);
        $parcelas = $detalhe['parcelas'];

        $this->assertSame('paga', $parcelas[0]['status_parcela']);
        $this->assertTrue($parcelas[0]['paga']);
        $this->assertSame('Jul/2026', $parcelas[0]['fatura_label']);

        $this->assertSame('atual', $parcelas[1]['status_parcela']);
        $this->assertTrue($parcelas[1]['paga']);
        $this->assertSame('Ago/2026', $parcelas[1]['fatura_label']);

        $this->assertSame('aberta', $parcelas[2]['status_parcela']);
        $this->assertFalse($parcelas[2]['paga']);
        $this->assertSame('aberta', $parcelas[3]['status_parcela']);
        $this->assertFalse($parcelas[3]['paga']);
    }

    public function test_avista_tem_uma_parcela_e_grupo_nulo(): void
    {
        $grupo = collect([$this->makeParcela([
            'id' => 99,
            'compra_grupo_id' => null,
            'parcelas_total' => 1,
            'parcela_atual' => 1,
            'observacoes' => '',
            'estabelecimento_nome' => 'Padaria',
            'fatura_mes' => 8,
            'fatura_ano' => 2026,
        ])]);

        $detalhe = $this->service->buildDetalheFromGrupo($grupo, 8, 2026);

        $this->assertTrue($detalhe['avista']);
        $this->assertNull($detalhe['compra_grupo_id']);
        $this->assertSame('Padaria', $detalhe['titulo']);
        $this->assertCount(1, $detalhe['parcelas']);
        $this->assertSame('atual', $detalhe['parcelas'][0]['status_parcela']);
        $this->assertSame(99, $detalhe['transacao_id']);
    }

    public function test_repasse_pago_na_parcela(): void
    {
        $grupo = $this->makeGrupo('g-r', 'Notebook', 'Kalunga', 2, 1, 500.0, 8, 2026);
        $repasses = [
            1 => ['valor_pago' => 500.0, 'data_ultimo' => '2026-08-10'],
        ];

        $detalhe = $this->service->buildDetalheFromGrupo($grupo, 8, 2026, $repasses);

        $this->assertSame(Repasse::STATUS_PAGO, $detalhe['parcelas'][0]['repasse']['status_repasse']);
        $this->assertSame('Pago', $detalhe['parcelas'][0]['repasse']['status_repasse_label']);
        $this->assertSame(500.0, $detalhe['parcelas'][0]['repasse']['valor_pago']);
        $this->assertSame(0.0, $detalhe['parcelas'][0]['repasse']['valor_aberto']);
        $this->assertSame('2026-08-10', $detalhe['parcelas'][0]['repasse']['data_ultimo']);

        $this->assertSame(Repasse::STATUS_PENDENTE, $detalhe['parcelas'][1]['repasse']['status_repasse']);
        $this->assertSame(500.0, $detalhe['parcelas'][1]['repasse']['valor_aberto']);
    }

    public function test_categoria_e_cartao_nulos_quando_ausentes(): void
    {
        $grupo = collect([$this->makeParcela([
            'categoria_id' => null,
            'categoria_nome' => null,
            'subcategoria_id' => null,
            'cartao_numero_id' => null,
            'origem_compra' => null,
        ])]);

        $detalhe = $this->service->buildDetalheFromGrupo($grupo, 8, 2026);

        $this->assertNull($detalhe['categoria']);
        $this->assertNull($detalhe['subcategoria']);
        $this->assertNull($detalhe['cartao_numero']);
        $this->assertNull($detalhe['origem_compra_label']);
    }

    /**
     * @return Collection<int, object>
     */
    private function makeGrupo(
        string $grupoId,
        string $observacoes,
        string $estabelecimento,
        int $total,
        int $parcelaAtualNaRef,
        float $valorParcela,
        int $mesRef,
        int $anoRef
    ): Collection {
        $items = [];

        for ($i = 1; $i <= $total; $i++) {
            $offset = $i - $parcelaAtualNaRef;
            $mes = $mesRef + $offset;
            $ano = $anoRef;
            while ($mes > 12) {
                $mes -= 12;
                $ano++;
            }
            while ($mes < 1) {
                $mes += 12;
                $ano--;
            }

            $items[] = $this->makeParcela([
                'id' => $i,
                'compra_grupo_id' => $grupoId,
                'data' => sprintf('%04d-%02d-10', $anoRef, max(1, $mesRef - $parcelaAtualNaRef + 1)),
                'valor' => $valorParcela,
                'valor_parcela' => $valorParcela,
                'parcela_atual' => $i,
                'parcelas_total' => $total,
                'observacoes' => $observacoes,
                'estabelecimento_nome' => $estabelecimento,
                'fatura_id' => 100 + $i,
                'fatura_mes' => $mes,
                'fatura_ano' => $ano,
                'fatura_status' => $i <= $parcelaAtualNaRef ? 'processada' : 'pendente',
            ]);
        }

        return collect($items);
    }

    private function makeParcela(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'compra_grupo_id' => 'g-default',
            'data' => '2026-08-10',
            'valor' => 100.0,
            'valor_parcela' => 100.0,
            'parcela_atual' => 1,
            'parcelas_total' => 1,
            'observacoes' => 'Compra teste',
            'origem_compra' => 'COMPRAS_PRESENCIAL',
            'tipo' => 'purchase',
            'importada_pdf' => false,
            'categoria_id' => 1,
            'subcategoria_id' => 2,
            'responsavel_id' => 1,
            'estabelecimento_id' => 10,
            'fatura_id' => 80,
            'cartao_numero_id' => 7,
            'fatura_mes' => 8,
            'fatura_ano' => 2026,
            'fatura_status' => 'processada',
            'cartao_id' => 2,
            'cartao_bandeira_id' => 5,
            'estabelecimento_nome' => 'Loja',
            'loja_id' => 3,
            'loja_nome' => 'Magazine Luiza',
            'categoria_nome' => 'Casa',
            'categoria_cor' => '#22c55e',
            'subcategoria_nome' => 'Eletro',
            'responsavel_nome' => 'Eu',
            'responsavel_tipo' => 'eu',
            'cartao_nome' => 'Nubank',
            'cartao_banco' => 'Nu Pagamentos',
            'cartao_cor_fundo' => '#8b5cf6',
            'cartao_cor_texto' => '#ffffff',
            'bandeira_nome' => 'Mastercard',
            'ultimos_digitos' => '1234',
            'cartao_numero_tipo' => 'fisico',
            'cartao_numero_apelido' => 'Principal',
            'cartao_numero_nome_no_cartao' => 'LEO SILVA',
        ], $overrides);
    }
}
