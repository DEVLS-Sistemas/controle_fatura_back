<?php

namespace Tests\Unit;

use App\Services\Dashboard\GastosPorCategoriaService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class GastosPorCategoriaServiceTest extends TestCase
{
    private GastosPorCategoriaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GastosPorCategoriaService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_categoria_traz_as_duas_subcategorias_de_maior_gasto(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 1800.0, 'subcategoria_id' => 10, 'subcategoria_nome' => 'Delivery']),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 1000.0, 'subcategoria_id' => 11, 'subcategoria_nome' => 'Supermercado']),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 400.0, 'subcategoria_id' => 12, 'subcategoria_nome' => 'Padaria']),
            $this->linha(['id' => 4, 'compra_chave' => 'av-4', 'valor' => 200.0, 'subcategoria_id' => null, 'subcategoria_nome' => null]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->agregarCategorias($linhas, $periodo, $totais);

        $this->assertCount(1, $categorias);
        $alimentacao = $categorias[0];
        $this->assertSame('Alimentação', $alimentacao['nome']);
        $this->assertSame(3400.0, $alimentacao['valor_total']);
        $this->assertSame(4, $alimentacao['compras']);
        $this->assertSame(3, $alimentacao['subcategorias_total']);
        $this->assertCount(2, $alimentacao['top_subcategorias']);
        $this->assertSame('Delivery', $alimentacao['top_subcategorias'][0]['nome']);
        $this->assertSame(1800.0, $alimentacao['top_subcategorias'][0]['valor_total']);
        $this->assertSame(52.9, $alimentacao['top_subcategorias'][0]['percentual_da_categoria']);
        $this->assertSame('Supermercado', $alimentacao['top_subcategorias'][1]['nome']);
        $this->assertSame(1, $alimentacao['outras_subcategorias']['quantidade']);
        $this->assertSame(400.0, $alimentacao['outras_subcategorias']['valor_total']);
        $this->assertSame(200.0, $alimentacao['sem_subcategoria']['valor_total']);
        $this->assertSame('transacoes', $alimentacao['top_subcategorias'][0]['atalho']['rota']);
        $this->assertSame('10', $alimentacao['top_subcategorias'][0]['atalho']['query']['subcategoria_id']);
        $this->assertCount(3, $alimentacao['subcategorias']);
        $this->assertSame(2, $alimentacao['subcategorias'][0]['categoria_id']);
        $this->assertSame('Alimentação', $alimentacao['subcategorias'][0]['categoria_nome']);
    }

    public function test_dashboard_top_10_e_subcategorias_escravas_da_categoria(): void
    {
        $linhas = [];
        $id = 1;
        for ($c = 1; $c <= 12; $c++) {
            $valorCat = 1200 - ($c * 50);
            $linhas[] = $this->linha([
                'id' => $id++,
                'compra_chave' => 'av-c-' . $c,
                'valor' => (float) $valorCat,
                'categoria_id' => $c,
                'categoria_nome' => 'Cat ' . $c,
                'categoria_cor' => '#111111',
                'subcategoria_id' => 100 + $c,
                'subcategoria_nome' => 'Sub ' . $c,
            ]);
        }
        $linhas[] = $this->linha([
            'id' => $id++,
            'compra_chave' => 'av-extra',
            'valor' => 400.0,
            'categoria_id' => 1,
            'categoria_nome' => 'Cat 1',
            'subcategoria_id' => 201,
            'subcategoria_nome' => 'Sub extra Cat 1',
        ]);

        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->agregarCategorias($linhas, $periodo, $totais);
        $subcategorias = $this->service->montarSubcategorias($categorias);
        $dashboards = $this->service->montarDashboards($categorias, $subcategorias);

        $this->assertSame(10, $dashboards['limite']);
        $this->assertCount(10, $dashboards['categorias']);
        $this->assertSame('Cat 1', $dashboards['categorias'][0]['nome']);
        $this->assertCount(10, $dashboards['subcategorias']);
        $this->assertSame(1, $dashboards['subcategorias'][0]['categoria_id']);

        $escravo = $this->service->filtrarSubcategoriasPorCategoria($subcategorias, 1);
        $this->assertCount(2, $escravo);
        $this->assertSame('Sub 1', $escravo[0]['nome']);
        $this->assertSame('Sub extra Cat 1', $escravo[1]['nome']);
        $this->assertSame(1, $escravo[0]['categoria_id']);

        $global = $this->service->filtrarSubcategoriasPorCategoria($subcategorias, null);
        $this->assertCount(10, $global);
    }

    public function test_parcelado_conta_uma_compra_e_soma_valores(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'grupo-tv', 'valor' => 500.0, 'categoria_id' => 4, 'categoria_nome' => 'Compras', 'subcategoria_id' => 20, 'subcategoria_nome' => 'Eletrônicos']),
            $this->linha(['id' => 2, 'compra_chave' => 'grupo-tv', 'valor' => 500.0, 'categoria_id' => 4, 'categoria_nome' => 'Compras', 'subcategoria_id' => 20, 'subcategoria_nome' => 'Eletrônicos']),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->agregarCategorias($linhas, $periodo, $totais);

        $this->assertSame(1, $totais['compras']);
        $this->assertSame(2, $totais['ocorrencias']);
        $this->assertSame(1000.0, $totais['valor_total']);
        $this->assertSame(1, $categorias[0]['compras']);
        $this->assertSame(1000.0, $categorias[0]['valor_total']);
        $this->assertSame(1, $categorias[0]['top_subcategorias'][0]['compras']);
    }

    public function test_ordena_categorias_pelo_maior_gasto_e_separa_sem_categoria(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 200.0, 'categoria_id' => 2, 'categoria_nome' => 'Alimentação']),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 800.0, 'categoria_id' => 4, 'categoria_nome' => 'Compras', 'categoria_cor' => '#3b82f6', 'subcategoria_id' => 20, 'subcategoria_nome' => 'Eletrônicos']),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 50.0, 'categoria_id' => null, 'categoria_nome' => 'Sem categoria', 'categoria_cor' => null, 'subcategoria_id' => null, 'subcategoria_nome' => null]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->montarCategorias($linhas, [], $periodo, $totais);

        $this->assertSame(3, $totais['categorias_com_gasto']);
        $this->assertSame(50.0, $totais['sem_categoria']['valor_total']);
        $this->assertSame('Compras', $categorias[0]['nome']);
        $this->assertSame('Alimentação', $categorias[1]['nome']);
        $this->assertSame('Sem categoria', $categorias[2]['nome']);
        $this->assertNull($categorias[2]['categoria_id']);
        $this->assertSame(76.2, $categorias[0]['percentual_gasto']);
    }

    public function test_categoria_sem_cor_devolve_preto_e_bucket_sem_categoria_devolve_cinza(): void
    {
        $linhas = [
            $this->linha([
                'id' => 1,
                'compra_chave' => 'av-1',
                'valor' => 200.0,
                'categoria_id' => 2,
                'categoria_nome' => 'Alimentação',
                'categoria_cor' => null,
            ]),
            $this->linha([
                'id' => 2,
                'compra_chave' => 'av-2',
                'valor' => 50.0,
                'categoria_id' => null,
                'categoria_nome' => 'Sem categoria',
                'categoria_cor' => null,
                'subcategoria_id' => null,
                'subcategoria_nome' => null,
            ]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->montarCategorias($linhas, [], $periodo, $totais);
        $subcategorias = $this->service->montarSubcategorias($categorias);
        $dashboards = $this->service->montarDashboards($categorias, $subcategorias);

        $this->assertSame('Alimentação', $categorias[0]['nome']);
        $this->assertSame('#000000', $categorias[0]['cor']);
        $this->assertSame('Sem categoria', $categorias[1]['nome']);
        $this->assertNull($categorias[1]['categoria_id']);
        $this->assertSame('#9ca3af', $categorias[1]['cor']);
        $this->assertSame('#000000', $dashboards['categorias'][0]['cor']);
        $this->assertSame('#9ca3af', $dashboards['categorias'][1]['cor']);
        $this->assertSame('#000000', $categorias[0]['subcategorias'][0]['categoria_cor']);
        $this->assertSame('#000000', $subcategorias[0]['categoria_cor']);
    }

    public function test_destaque_usa_categoria_nomeada_e_as_duas_subcategorias(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 1800.0, 'subcategoria_id' => 10, 'subcategoria_nome' => 'Delivery']),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 1000.0, 'subcategoria_id' => 11, 'subcategoria_nome' => 'Supermercado']),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 900.0, 'categoria_id' => null, 'categoria_nome' => 'Sem categoria', 'categoria_cor' => null, 'subcategoria_id' => null]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->montarCategorias($linhas, [], $periodo, $totais);
        $destaque = $this->service->montarDestaque($categorias, $periodo);

        $this->assertNotNull($destaque);
        $this->assertSame('Alimentação', $destaque['categoria']['nome']);
        $this->assertCount(2, $destaque['subcategorias']);
        $this->assertSame('Delivery', $destaque['subcategorias'][0]['nome']);
        $this->assertSame('Supermercado', $destaque['subcategorias'][1]['nome']);
        $this->assertSame(
            'Você mais gastou em Alimentação nos últimos 3 meses: R$ 2.800,00 (75,7% do total). As duas maiores fatias são Delivery e Supermercado.',
            $destaque['frase']
        );
        $this->assertStringContainsString('Destaques: Delivery e Supermercado.', $categorias[0]['frase']);
    }

    public function test_agrega_tipos_de_compra_por_origem(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 700.0, 'origem_compra' => 'COMPRAS_PRESENCIAL']),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 200.0, 'origem_compra' => 'COMPRAS_ONLINE']),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 100.0, 'origem_compra' => null]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $porOrigem = $this->service->montarPorOrigem($linhas, [], $periodo, $totais);
        $categorias = $this->service->agregarCategorias($linhas, $periodo, $totais);

        $this->assertSame('COMPRAS_PRESENCIAL', $porOrigem[0]['origem_compra']);
        $this->assertSame('Compras presencial', $porOrigem[0]['label']);
        $this->assertSame(700.0, $porOrigem[0]['valor_total']);
        $this->assertSame(70.0, $porOrigem[0]['percentual_gasto']);
        $this->assertSame('COMPRAS_ONLINE', $porOrigem[1]['origem_compra']);
        $this->assertNull($porOrigem[2]['origem_compra']);
        $this->assertSame('Sem origem', $porOrigem[2]['label']);
        $this->assertSame(
            'Você gastou R$ 700,00 em compras presencial nos últimos 3 meses — 70% do total.',
            $porOrigem[0]['frase']
        );
        $this->assertSame('COMPRAS_PRESENCIAL', $categorias[0]['por_origem'][0]['origem_compra']);
        $this->assertSame('COMPRAS_PRESENCIAL', $porOrigem[0]['atalho']['query']['origem_compra']);
    }

    public function test_evolucao_por_categoria_alinha_meses_da_janela(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 100.0, 'data' => '2026-06-10']),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 250.0, 'data' => '2026-07-10']),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 80.0, 'data' => '2026-08-10', 'categoria_id' => 4, 'categoria_nome' => 'Compras', 'categoria_cor' => '#3b82f6']),
        ];
        $periodo = $this->periodoTresMeses();
        $periodo['inicio'] = '2026-06-01';
        $totais = $this->service->montarTotais($linhas, [], $periodo);
        $categorias = $this->service->agregarCategorias($linhas, $periodo, $totais);
        $evolucao = $this->service->montarEvolucaoPorCategoria($linhas, $categorias, $periodo);

        $this->assertSame('Alimentação', $evolucao[0]['nome']);
        $this->assertSame('2026-06', $evolucao[0]['serie'][0]['chave']);
        $this->assertSame(100.0, $evolucao[0]['serie'][0]['valor_total']);
        $this->assertSame(250.0, $evolucao[0]['serie'][1]['valor_total']);
        $this->assertSame(0.0, $evolucao[0]['serie'][2]['valor_total']);
        $this->assertSame('Compras', $evolucao[1]['nome']);
        $this->assertSame(80.0, $evolucao[1]['serie'][2]['valor_total']);
    }

    public function test_variacao_contra_periodo_anterior(): void
    {
        $atual = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'valor' => 140.0]),
        ];
        $anterior = [
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'valor' => 100.0]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = $this->service->montarTotais($atual, $anterior, $periodo);
        $categorias = $this->service->montarCategorias($atual, $anterior, $periodo, $totais);

        $this->assertSame(40.0, $totais['variacao_valor_percentual']);
        $this->assertSame(40.0, $categorias[0]['variacao_valor_percentual']);
        $this->assertSame(100.0, $categorias[0]['valor_anterior']);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function linha(array $over = []): array
    {
        return array_merge([
            'id' => 1,
            'compra_chave' => 'av-1',
            'data' => '2026-06-01',
            'valor' => 50.0,
            'categoria_id' => 2,
            'categoria_nome' => 'Alimentação',
            'categoria_cor' => '#f59e0b',
            'subcategoria_id' => 8,
            'subcategoria_nome' => 'Delivery',
            'origem_compra' => 'COMPRAS_ONLINE',
        ], $over);
    }

    /**
     * @return array<string, mixed>
     */
    private function periodoTresMeses(): array
    {
        return [
            'inicio' => '2026-05-24',
            'fim' => '2026-08-24',
            'origem' => 'janela',
            'meses' => 3,
            'dias' => 93,
            'label' => 'Últimos 3 meses',
            'label_frase' => 'nos últimos 3 meses',
            'label_anterior' => '3 meses anteriores',
            'label_anterior_frase' => 'aos 3 meses anteriores',
        ];
    }
}
