<?php

namespace Tests\Unit;

use App\Services\Categoria\CategoriaCorVariacao;
use App\Services\Dashboard\GastosCriticosService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class GastosCriticosServiceTest extends TestCase
{
    private GastosCriticosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GastosCriticosService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_janela_padrao_de_tres_meses_e_periodo_anterior_contiguo(): void
    {
        $hoje = Carbon::create(2026, 8, 24);
        $periodos = $this->service->resolverPeriodos((object) [], $hoje);

        $this->assertSame('2026-05-24', $periodos['atual']['inicio']);
        $this->assertSame('2026-08-24', $periodos['atual']['fim']);
        $this->assertSame(3, $periodos['atual']['meses']);
        $this->assertSame('janela', $periodos['atual']['origem']);
        $this->assertSame('nos últimos 3 meses', $periodos['atual']['label_frase']);

        $this->assertSame('2026-02-24', $periodos['anterior']['inicio']);
        $this->assertSame('2026-05-23', $periodos['anterior']['fim']);
        $this->assertSame('anterior', $periodos['anterior']['origem']);
        $this->assertSame('3 meses anteriores', $periodos['anterior']['label']);
    }

    public function test_meses_invalido_lanca_422(): void
    {
        $this->expectExceptionMessage('meses deve ser 1, 3, 6 ou 12');
        $this->service->resolverPeriodos((object) ['meses' => 2], Carbon::create(2026, 8, 24));
    }

    public function test_frase_frequencia_do_estabelecimento_nos_ultimos_tres_meses(): void
    {
        $item = $this->itemEstabelecimento(['compras' => 18]);
        $frase = $this->service->fraseFrequencia($item, $this->periodoTresMeses());

        $this->assertSame(
            'Você comprou 18 vezes neste estabelecimento nos últimos 3 meses.',
            $frase
        );
    }

    public function test_agrega_parcelado_como_uma_compra_e_soma_valores(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'grupo-tv', 'valor' => 100.0]),
            $this->linha(['id' => 2, 'compra_chave' => 'grupo-tv', 'valor' => 100.0]),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'valor' => 50.0, 'data' => '2026-07-01']),
        ];
        $periodo = $this->periodoTresMeses();
        $itens = $this->service->agregarPor($linhas, 'estabelecimento', $periodo, [
            'valor_total' => 250.0,
            'compras' => 2,
        ]);

        $this->assertCount(1, $itens);
        $this->assertSame(2, $itens[0]['compras']);
        $this->assertSame(3, $itens[0]['ocorrencias']);
        $this->assertSame(250.0, $itens[0]['valor_total']);
        $this->assertSame(125.0, $itens[0]['ticket_medio']);
        $this->assertSame(100.0, $itens[0]['percentual_gasto']);
    }

    public function test_loja_soma_maquinas_e_categoria_separa_subcategoria(): void
    {
        $linhas = [
            $this->linha([
                'id' => 1, 'compra_chave' => 'av-1', 'valor' => 80.0,
                'estabelecimento_id' => 10, 'estabelecimento_nome' => 'atacadao1',
                'loja_id' => 1, 'loja_nome' => 'Atacadão',
            ]),
            $this->linha([
                'id' => 2, 'compra_chave' => 'av-2', 'valor' => 120.0,
                'estabelecimento_id' => 11, 'estabelecimento_nome' => 'atacadao2',
                'loja_id' => 1, 'loja_nome' => 'Atacadão',
                'subcategoria_id' => 9, 'subcategoria_nome' => 'Supermercado',
            ]),
        ];
        $periodo = $this->periodoTresMeses();
        $totais = ['valor_total' => 200.0, 'compras' => 2];

        $lojas = $this->service->agregarPor($linhas, 'loja', $periodo, $totais);
        $this->assertCount(1, $lojas);
        $this->assertSame('Atacadão', $lojas[0]['nome_exibicao']);
        $this->assertSame(2, $lojas[0]['compras']);
        $this->assertSame(200.0, $lojas[0]['valor_total']);

        $subs = $this->service->agregarPor($linhas, 'subcategoria', $periodo, $totais);
        $this->assertCount(2, $subs);
        $this->assertSame('Supermercado', $subs[0]['nome']);
        $this->assertSame('Delivery', $subs[1]['nome']);
    }

    public function test_map_linhas_categoria_sem_cor_vira_preto(): void
    {
        $linhas = $this->service->mapLinhas(new Collection([(object) [
            'id' => 1,
            'compra_grupo_id' => null,
            'data' => '2026-08-01',
            'valor' => 10.0,
            'estabelecimento_id' => 1,
            'estabelecimento_nome' => 'X',
            'loja_id' => null,
            'loja_nome' => null,
            'categoria_id' => 2,
            'categoria_nome' => 'Casa',
            'categoria_cor' => null,
            'subcategoria_id' => 5,
            'subcategoria_nome' => 'Eletro',
            'subcategoria_cor' => null,
        ]]));

        $this->assertSame('#000000', $linhas[0]['categoria_cor']);
        $this->assertSame(
            CategoriaCorVariacao::variacoes('#000000', 1)[0],
            $linhas[0]['subcategoria_cor']
        );
    }

    public function test_agrega_categoria_cadastrada_sem_cor_como_preto(): void
    {
        $itens = $this->service->agregarPor(
            [$this->linha(['categoria_cor' => null, 'subcategoria_id' => null, 'subcategoria_nome' => null])],
            'categoria',
            $this->periodoTresMeses(),
            ['valor_total' => 50.0, 'compras' => 1]
        );

        $this->assertSame('#000000', $itens[0]['categoria_cor']);
        $this->assertSame('#000000', $itens[0]['cor']);
    }

    public function test_agrega_subcategoria_usa_variacao_quando_pivot_vazio(): void
    {
        $itens = $this->service->agregarPor(
            [$this->linha(['categoria_cor' => '#3b82f6', 'subcategoria_cor' => null])],
            'subcategoria',
            $this->periodoTresMeses(),
            ['valor_total' => 50.0, 'compras' => 1]
        );

        $this->assertSame('#3b82f6', $itens[0]['categoria_cor']);
        $this->assertSame(CategoriaCorVariacao::variacoes('#3b82f6', 1)[0], $itens[0]['cor']);
    }

    public function test_o_que_mais_gasta_pode_diferir_do_que_mais_compra(): void
    {
        $linhas = array_merge(
            $this->nCompras(18, 45, 'IFOOD *BK', 3, 'iFood', 40.0, 'Alimentação', 2),
            [
                $this->linha([
                    'id' => 100, 'compra_chave' => 'av-100', 'valor' => 1800.0,
                    'estabelecimento_id' => 99, 'estabelecimento_nome' => 'MAGAZINE',
                    'loja_id' => 7, 'loja_nome' => 'Magazine Luiza',
                    'categoria_id' => 4, 'categoria_nome' => 'Compras',
                    'subcategoria_id' => 20, 'subcategoria_nome' => 'Eletrônicos',
                    'data' => '2026-06-15',
                ]),
            ]
        );
        $totais = $this->service->montarTotais($linhas, [], $this->periodoTresMeses());
        $rankings = $this->service->montarRankings($linhas, [], $this->periodoTresMeses(), $totais);
        $destaques = $this->service->montarDestaques($rankings, $this->periodoTresMeses());

        $this->assertSame('Magazine Luiza', $destaques['maior_gasto']['nome']);
        $this->assertSame('iFood', $destaques['mais_comprado']['nome']);
        $this->assertSame(
            'Você comprou 18 vezes em iFood nos últimos 3 meses.',
            $destaques['mais_comprado']['frase']
        );
        $this->assertStringContainsString('Magazine Luiza', $destaques['maior_gasto']['frase']);
        $this->assertStringContainsString('R$', $destaques['maior_gasto']['frase']);
    }

    public function test_alerta_de_frequencia_e_nao_repete_maquina_quando_ja_tem_loja(): void
    {
        $linhas = $this->nCompras(18, 45, 'IFOOD *BK', 3, 'iFood', 50.0, 'Alimentação', 2);
        $totais = $this->service->montarTotais($linhas, [], $this->periodoTresMeses());
        $rankings = $this->service->montarRankings($linhas, [], $this->periodoTresMeses(), $totais);
        $alertas = $this->service->montarAlertas($rankings, $this->periodoTresMeses());

        $this->assertNotEmpty($alertas);
        $this->assertSame(
            'Você comprou 18 vezes neste estabelecimento nos últimos 3 meses.',
            $rankings['estabelecimento']['todos'][0]['frase_frequencia']
        );

        $chaves = array_map(fn (array $a) => $a['entidade']['chave'], $alertas);
        $this->assertContains('loja-3', $chaves);
        $this->assertNotContains('estabelecimento-45', $chaves);

        $loja = null;
        foreach ($alertas as $alerta) {
            if ($alerta['entidade']['chave'] === 'loja-3') {
                $loja = $alerta;
                break;
            }
        }
        $this->assertNotNull($loja);
        $this->assertContains('frequencia', $loja['motivos']);
        $this->assertSame('alta', $loja['severidade']);
        $this->assertSame('Você comprou 18 vezes em iFood nos últimos 3 meses.', $loja['frase']);
    }

    public function test_evolucao_mensal_marca_mes_parcial_e_variacao(): void
    {
        $linhas = [
            $this->linha(['id' => 1, 'compra_chave' => 'av-1', 'data' => '2026-06-10', 'valor' => 100.0]),
            $this->linha(['id' => 2, 'compra_chave' => 'av-2', 'data' => '2026-07-10', 'valor' => 150.0]),
            $this->linha(['id' => 3, 'compra_chave' => 'av-3', 'data' => '2026-08-10', 'valor' => 75.0]),
        ];
        $periodo = $this->periodoTresMeses();
        $periodo['inicio'] = '2026-06-01';
        $colunas = $this->service->montarEvolucao($linhas, [], $periodo);

        $this->assertSame('2026-06', $colunas[0]['chave']);
        $this->assertSame(100.0, $colunas[0]['valor_total']);
        $this->assertSame(150.0, $colunas[1]['valor_total']);
        $this->assertSame(50.0, $colunas[1]['variacao_percentual']);
        $this->assertTrue($colunas[count($colunas) - 1]['parcial']);
    }

    public function test_variacao_percentual_e_brl(): void
    {
        $this->assertSame(40.0, $this->service->variacaoPercentual(140.0, 100.0));
        $this->assertNull($this->service->variacaoPercentual(50.0, 0.0));
        $this->assertSame(0.0, $this->service->variacaoPercentual(0.0, 0.0));
        $this->assertSame('R$ 1.240,50', $this->service->formatBrl(1240.5));
        $this->assertSame(6, $this->service->minComprasFrequencia(3));
        $this->assertSame(4, $this->service->minComprasFrequencia(1));
    }

    public function test_gasto_subiu_em_relacao_ao_periodo_anterior(): void
    {
        $item = $this->itemEstabelecimento([
            'valor_total' => 890.0,
            'valor_anterior' => 500.0,
            'variacao_valor_percentual' => 78.0,
        ]);
        $frase = $this->service->fraseEvolucao($item, $this->periodoTresMeses());

        $this->assertSame(
            'Seu gasto em iFood subiu 78% em relação aos 3 meses anteriores.',
            $frase
        );
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
            'estabelecimento_id' => 45,
            'estabelecimento_nome' => 'IFOOD *BK',
            'loja_id' => 3,
            'loja_nome' => 'iFood',
            'categoria_id' => 2,
            'categoria_nome' => 'Alimentação',
            'categoria_cor' => '#f59e0b',
            'subcategoria_id' => 8,
            'subcategoria_nome' => 'Delivery',
            'subcategoria_cor' => null,
        ], $over);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nCompras(
        int $n,
        int $estabelecimentoId,
        string $estabNome,
        int $lojaId,
        string $lojaNome,
        float $valor,
        string $categoria,
        int $categoriaId
    ): array {
        $linhas = [];
        for ($i = 1; $i <= $n; $i++) {
            $linhas[] = $this->linha([
                'id' => $i,
                'compra_chave' => 'av-' . $i,
                'valor' => $valor,
                'data' => sprintf('2026-06-%02d', min($i, 28)),
                'estabelecimento_id' => $estabelecimentoId,
                'estabelecimento_nome' => $estabNome,
                'loja_id' => $lojaId,
                'loja_nome' => $lojaNome,
                'categoria_id' => $categoriaId,
                'categoria_nome' => $categoria,
            ]);
        }

        return $linhas;
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function itemEstabelecimento(array $over = []): array
    {
        return array_merge([
            'chave' => 'estabelecimento-45',
            'tipo' => 'estabelecimento',
            'id' => 45,
            'nome' => 'IFOOD *BK',
            'nome_exibicao' => 'iFood',
            'loja_id' => 3,
            'loja_nome' => 'iFood',
            'categoria_id' => 2,
            'categoria_nome' => 'Alimentação',
            'categoria_cor' => '#f59e0b',
            'subcategoria_id' => 8,
            'subcategoria_nome' => 'Delivery',
            'compras' => 18,
            'ocorrencias' => 18,
            'valor_total' => 890.0,
            'ticket_medio' => 49.44,
            'percentual_gasto' => 22.0,
            'percentual_compras' => 30.0,
            'valor_anterior' => 600.0,
            'compras_anterior' => 10,
            'variacao_valor_percentual' => 48.3,
            'variacao_compras_percentual' => 80.0,
            'frequencia' => ['label' => '1 vez a cada 5 dias'],
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
