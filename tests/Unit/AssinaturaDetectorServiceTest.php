<?php

namespace Tests\Unit;

use App\Models\Transacao;
use App\Services\Assinatura\AssinaturaDetectorService;
use PHPUnit\Framework\TestCase;

class AssinaturaDetectorServiceTest extends TestCase
{
    private AssinaturaDetectorService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AssinaturaDetectorService();
    }

    public function test_identificador_loja_e_estabelecimento(): void
    {
        $this->assertSame('loja-12', $this->detector->montarIdentificador('loja', 12));
        $this->assertSame(
            ['tipo_chave' => 'estabelecimento', 'referencia_id' => 45],
            $this->detector->parseIdentificador('estabelecimento-45')
        );
    }

    public function test_identificador_invalido_lanca_422(): void
    {
        $this->expectExceptionMessage('Identificador da assinatura inválido');
        $this->detector->parseIdentificador('netflix');
    }

    public function test_netflix_mensal_vira_candidata_com_estimativa_anual(): void
    {
        $eventos = [
            $this->evento(1, '2026-01-10', 55.90, 'NETFLIX.COM'),
            $this->evento(2, '2026-02-10', 55.90, 'NETFLIX.COM'),
            $this->evento(3, '2026-03-10', 55.90, 'NETFLIX.COM'),
        ];

        $item = $this->detector->classificarGrupo($eventos, '2026-08-24', 'estabelecimento-1');

        $this->assertNotNull($item);
        $this->assertSame('candidata', $item['status']);
        $this->assertSame('mensal', $item['periodicidade']);
        $this->assertSame(55.90, $item['valor_medio']);
        $this->assertEqualsWithDelta(670.80, $item['estimativa_anual'], 0.01);
        $this->assertEqualsWithDelta(55.90, $item['estimativa_mensal'], 0.01);
        $this->assertSame(3, $item['cobrancas']);
        $this->assertSame('2026-04-09', $item['proxima_estimada']);
        $this->assertSame('alta', $item['confianca']);
    }

    public function test_reajuste_de_preco_ainda_e_similar(): void
    {
        $this->assertTrue($this->detector->valoresSaoSimilares([55.90, 55.90, 59.90]));
        $this->assertFalse($this->detector->valoresSaoSimilares([32.0, 180.0, 95.0]));
    }

    public function test_mercado_com_valores_variados_nao_e_assinatura(): void
    {
        $eventos = [
            $this->evento(1, '2026-01-05', 32.50, 'ATACADAO'),
            $this->evento(2, '2026-02-08', 187.40, 'ATACADAO'),
            $this->evento(3, '2026-03-12', 94.10, 'ATACADAO'),
        ];

        $this->assertNull($this->detector->classificarGrupo($eventos, '2026-08-24', 'estabelecimento-1'));
    }

    public function test_compra_unica_marcada_como_servico_assume_mensal(): void
    {
        $eventos = [
            $this->evento(1, '2026-07-10', 21.90, 'SPOTIFY', Transacao::ORIGEM_PAGAMENTO_SERVICOS),
        ];

        $item = $this->detector->classificarGrupo($eventos, '2026-08-24', 'estabelecimento-9');

        $this->assertNotNull($item);
        $this->assertSame('confirmada', $item['status']);
        $this->assertSame('mensal', $item['periodicidade']);
        $this->assertTrue($item['periodicidade_assumida']);
        $this->assertSame('baixa', $item['confianca']);
        $this->assertEqualsWithDelta(262.80, $item['estimativa_anual'], 0.01);
        $this->assertSame('2026-08-10', $item['proxima_estimada']);
    }

    public function test_duas_compras_semanais_nao_bastam(): void
    {
        $eventos = [
            $this->evento(1, '2026-08-03', 12.00, 'PADARIA'),
            $this->evento(2, '2026-08-10', 12.00, 'PADARIA'),
        ];

        $this->assertNull($this->detector->classificarGrupo($eventos, '2026-08-24', 'estabelecimento-1'));
    }

    public function test_quatro_cobrancas_semanais_viram_candidata(): void
    {
        $eventos = [
            $this->evento(1, '2026-07-06', 39.90, 'IFOOD CLUBE'),
            $this->evento(2, '2026-07-13', 39.90, 'IFOOD CLUBE'),
            $this->evento(3, '2026-07-20', 39.90, 'IFOOD CLUBE'),
            $this->evento(4, '2026-07-27', 39.90, 'IFOOD CLUBE'),
        ];

        $item = $this->detector->classificarGrupo($eventos, '2026-08-24', 'estabelecimento-1');

        $this->assertNotNull($item);
        $this->assertSame('semanal', $item['periodicidade']);
        $this->assertEqualsWithDelta(2074.80, $item['estimativa_anual'], 0.01);
    }

    public function test_agrupa_por_loja_quando_valores_sao_parecidos(): void
    {
        $eventos = [
            $this->evento(1, '2026-01-10', 55.90, 'NETFLIX.COM', null, 10, 'Netflix', 1),
            $this->evento(2, '2026-02-10', 55.90, 'NETFLIX DIGITAL', null, 11, 'Netflix', 1),
            $this->evento(3, '2026-03-10', 55.90, 'NETFLIX.COM', null, 10, 'Netflix', 1),
        ];

        $grupos = $this->detector->agruparEventos($eventos);

        $this->assertArrayHasKey('loja-1', $grupos);
        $this->assertCount(1, $grupos);
        $this->assertCount(3, $grupos['loja-1']);
    }

    public function test_parte_por_estabelecimento_quando_loja_tem_valores_misturados(): void
    {
        $eventos = [
            $this->evento(1, '2026-01-10', 9.90, 'GOOGLE ONE', null, 20, 'Google', 2),
            $this->evento(2, '2026-02-10', 9.90, 'GOOGLE ONE', null, 20, 'Google', 2),
            $this->evento(3, '2026-01-15', 129.90, 'GOOGLE PLAY', null, 21, 'Google', 2),
            $this->evento(4, '2026-03-01', 4.99, 'GOOGLE PLAY', null, 21, 'Google', 2),
        ];

        $grupos = $this->detector->agruparEventos($eventos);

        $this->assertArrayHasKey('estabelecimento-20', $grupos);
        $this->assertArrayHasKey('estabelecimento-21', $grupos);
        $this->assertArrayNotHasKey('loja-2', $grupos);

        $googleOne = $this->detector->classificarGrupo(
            $grupos['estabelecimento-20'],
            '2026-08-24',
            'estabelecimento-20'
        );
        $play = $this->detector->classificarGrupo(
            $grupos['estabelecimento-21'],
            '2026-08-24',
            'estabelecimento-21'
        );

        $this->assertNotNull($googleOne);
        $this->assertSame('mensal', $googleOne['periodicidade']);
        $this->assertNull($play);
    }

    public function test_totais_somam_estimativa_anual(): void
    {
        $netflix = $this->detector->classificarGrupo([
            $this->evento(1, '2026-01-10', 50.00, 'NETFLIX'),
            $this->evento(2, '2026-02-10', 50.00, 'NETFLIX'),
        ], '2026-08-24', 'estabelecimento-1');

        $spotify = $this->detector->classificarGrupo([
            $this->evento(3, '2026-01-05', 21.90, 'SPOTIFY', Transacao::ORIGEM_PAGAMENTO_SERVICOS),
            $this->evento(4, '2026-02-05', 21.90, 'SPOTIFY', Transacao::ORIGEM_PAGAMENTO_SERVICOS),
        ], '2026-08-24', 'estabelecimento-2');

        $totais = $this->detector->montarTotais([$netflix, $spotify]);

        $this->assertSame(2, $totais['assinaturas']);
        $this->assertSame(1, $totais['confirmadas']);
        $this->assertSame(1, $totais['candidatas']);
        $this->assertEqualsWithDelta(600.0, $totais['estimativa_anual_candidatas'], 0.01);
        $this->assertEqualsWithDelta(262.80, $totais['estimativa_anual_confirmadas'], 0.01);
        $this->assertEqualsWithDelta(862.80, $totais['estimativa_anual'], 0.01);
    }

    public function test_ordenar_por_gasto_anual_desc(): void
    {
        $barata = $this->detector->classificarGrupo([
            $this->evento(1, '2026-01-10', 20.00, 'A'),
            $this->evento(2, '2026-02-10', 20.00, 'A'),
        ], '2026-08-24', 'estabelecimento-1');
        $cara = $this->detector->classificarGrupo([
            $this->evento(3, '2026-01-10', 90.00, 'B'),
            $this->evento(4, '2026-02-10', 90.00, 'B'),
        ], '2026-08-24', 'estabelecimento-2');

        $ordenado = $this->detector->ordenarItens(
            [$barata, $cara],
            AssinaturaDetectorService::ORDENAR_ANUAL_DESC
        );

        $this->assertSame('B', $ordenado[0]['titulo']);
        $this->assertSame('A', $ordenado[1]['titulo']);
    }

    public function test_periodicidade_anual_e_trimestral(): void
    {
        $this->assertSame('anual', $this->detector->detectarPeriodicidade([365]));
        $this->assertSame('trimestral', $this->detector->detectarPeriodicidade([90, 92]));
        $this->assertSame('irregular', $this->detector->detectarPeriodicidade([3, 80, 12]));
    }

    /**
     * @return array<string, mixed>
     */
    private function evento(
        int $id,
        string $data,
        float $valor,
        string $estabelecimento,
        ?string $origem = null,
        int $estabelecimentoId = 1,
        ?string $lojaNome = null,
        ?int $lojaId = null
    ): array {
        return [
            'id' => $id,
            'data' => $data,
            'valor' => $valor,
            'origem_compra' => $origem,
            'estabelecimento_id' => $estabelecimentoId,
            'estabelecimento_nome' => $estabelecimento,
            'loja_id' => $lojaId,
            'loja_nome' => $lojaNome,
            'categoria_id' => null,
            'categoria_nome' => null,
            'categoria_cor' => null,
            'subcategoria_id' => null,
            'subcategoria_nome' => null,
            'responsavel_id' => 1,
            'responsavel_nome' => 'Eu',
            'fatura_id' => 1,
            'fatura_mes' => (int) substr($data, 5, 2),
            'fatura_ano' => (int) substr($data, 0, 4),
            'observacoes' => null,
        ];
    }
}
