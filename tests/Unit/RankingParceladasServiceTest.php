<?php

namespace Tests\Unit;

use App\Services\Dashboard\RankingParceladasService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class RankingParceladasServiceTest extends TestCase
{
    private RankingParceladasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RankingParceladasService();
    }

    public function test_resolve_titulo_prioriza_observacoes(): void
    {
        $comObs = $this->service->resolveTitulo('  Geladeira Frost Free  ', 'Magazine');
        $this->assertSame('Geladeira Frost Free', $comObs['titulo']);
        $this->assertSame('observacoes', $comObs['titulo_origem']);

        $semObs = $this->service->resolveTitulo('   ', 'Kalunga');
        $this->assertSame('Kalunga', $semObs['titulo']);
        $this->assertSame('estabelecimento', $semObs['titulo_origem']);

        $vazio = $this->service->resolveTitulo(null, null);
        $this->assertSame('Compra parcelada', $vazio['titulo']);
        $this->assertSame('estabelecimento', $vazio['titulo_origem']);
    }

    public function test_ranking_ordena_por_parcelas_restantes_desc(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);

        $geladeira = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g1', 'Geladeira', 'Magazine', 12, 1, 291.67, 8, 2026),
            $refKey
        );
        $impressora = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g2', 'Impressora', 'Kalunga', 12, 2, 108.33, 8, 2026),
            $refKey
        );

        $this->assertSame(11, $geladeira['parcelas_restantes']);
        $this->assertSame(10, $impressora['parcelas_restantes']);
        $this->assertSame('Geladeira', $geladeira['titulo']);
        $this->assertSame('observacoes', $geladeira['titulo_origem']);

        $ordenado = $this->service->ordenarItens(
            collect([$impressora, $geladeira]),
            RankingParceladasService::ORDENAR_RESTANTES_DESC
        );

        $this->assertSame('g1', $ordenado[0]['compra_grupo_id']);
        $this->assertSame('g2', $ordenado[1]['compra_grupo_id']);
    }

    public function test_ranking_empate_restantes_usa_maior_valor_aberto_depois_menor_percentual(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);

        // Mesmas 10 parcelas restantes; TV tem maior valor em aberto
        $fone = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-fone', 'Fone', 'Loja A', 12, 2, 50.0, 8, 2026),
            $refKey
        );
        $tv = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-tv', 'TV', 'Loja B', 12, 2, 400.0, 8, 2026),
            $refKey
        );

        $this->assertSame(10, $fone['parcelas_restantes']);
        $this->assertSame(10, $tv['parcelas_restantes']);
        $this->assertGreaterThan($fone['valor_aberto'], $tv['valor_aberto']);

        $ordenado = $this->service->ordenarItens(
            collect([$fone, $tv]),
            RankingParceladasService::ORDENAR_RESTANTES_DESC
        );

        $this->assertSame('g-tv', $ordenado[0]['compra_grupo_id']);
        $this->assertSame('g-fone', $ordenado[1]['compra_grupo_id']);
    }

    public function test_ultima_parcela_no_mes_atual_continua_visivel(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);

        // Última parcela (12/12) cai em ago/2026 = referência
        $item = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-fim-atual', 'Notebook', 'Loja', 12, 12, 100.0, 8, 2026),
            $refKey
        );

        $this->assertSame(0, $item['parcelas_restantes']);
        $this->assertSame(8, $item['ultima_parcela']['mes']);
        $this->assertSame(2026, $item['ultima_parcela']['ano']);
        $this->assertTrue($this->service->estaVisivelNoRanking($item, $refKey));
    }

    public function test_ultima_parcela_no_mes_anterior_nao_aparece(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);

        // Última parcela caiu em jul/2026 (parcela 12 na ref seria ago; parcelaAtual=13 impossível)
        // Simula: parcela 12 na competência jul = ref com parcelaAtual deslocada
        // makeGrupo: parcelaAtualNaRef=12 means parcela 12 is in mesRef.
        // For last in previous month: parcela 12 in jul when ref is ago → parcelaAtualNaRef would be
        // such that i=12 is in jul: offset = 12 - parcelaAtualNaRef, mes = 8 + offset = 7
        // => 8 + (12 - p) = 7 => 12 - p = -1 => p = 13 — can't use makeGrupo directly for past-complete.

        $grupo = $this->makeGrupo('g-fim-ant', 'Mouse', 'Loja', 12, 12, 80.0, 7, 2026);
        $item = $this->service->buildItemFromGrupo($grupo, $refKey);

        $this->assertSame(7, $item['ultima_parcela']['mes']);
        $this->assertSame(2026, $item['ultima_parcela']['ano']);
        $this->assertSame(0, $item['parcelas_restantes']);
        $this->assertFalse($this->service->estaVisivelNoRanking($item, $refKey));
    }

    public function test_quitadas_sempre_no_final_do_ranking(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);

        $aberta = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-aberta', 'Geladeira', 'Magazine', 12, 1, 291.67, 8, 2026),
            $refKey
        );
        $quitada = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-quitada', 'Notebook', 'Loja', 12, 12, 100.0, 8, 2026),
            $refKey
        );

        $this->assertTrue($this->service->estaQuitada($quitada));
        $this->assertFalse($this->service->estaQuitada($aberta));

        // Mesmo com ordenação por % desc (que colocaria 100% no topo), quitada vai ao final
        $ordenado = $this->service->ordenarItens(
            collect([$quitada, $aberta]),
            RankingParceladasService::ORDENAR_PERCENTUAL_DESC
        );

        $this->assertSame('g-aberta', $ordenado[0]['compra_grupo_id']);
        $this->assertSame('g-quitada', $ordenado[1]['compra_grupo_id']);
        $this->assertTrue($ordenado[1]['quitada']);
    }

    public function test_build_colunas_centra_mes_filtrado(): void
    {
        $colunas = $this->service->buildColunas(8, 2026);

        $this->assertCount(13, $colunas);
        $this->assertSame('2026-02', $colunas[0]['chave']);
        $this->assertSame('2026-08', $colunas[6]['chave']);
        $this->assertTrue($colunas[6]['centro']);
        $this->assertSame('2027-02', $colunas[12]['chave']);
        $this->assertFalse($colunas[0]['centro']);
    }

    public function test_timeline_usa_indices_na_janela(): void
    {
        $colunas = $this->service->buildColunas(8, 2026);
        $refKey = $this->service->competenciaKey(8, 2026);
        $item = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g1', 'Geladeira', 'Magazine', 12, 1, 100.0, 8, 2026),
            $refKey
        );

        $timeline = $this->service->buildTimeline($item, $colunas);

        $this->assertSame('2026-08', $timeline['inicio_chave']);
        $this->assertSame('2027-07', $timeline['fim_chave']);
        $this->assertSame(6, $timeline['indice_inicio']);
        $this->assertSame(12, $timeline['indice_fim']); // jul/2027 fora → clip no fim da janela
        $this->assertFalse($timeline['fora_da_janela']);
        $this->assertSame(6, $timeline['indice_progresso']);
        $this->assertSame('Ago/2026', $this->service->formatCompetenciaLabel(8, 2026));
    }

    public function test_build_item_calcula_pago_aberto_e_proxima(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);
        $item = $this->service->buildItemFromGrupo(
            $this->makeGrupo('g-tv', 'TV 55', 'Fast Shop', 12, 3, 250.0, 8, 2026),
            $refKey
        );

        $this->assertSame(3, $item['parcela_atual']);
        $this->assertSame(3, $item['parcelas_pagas']);
        $this->assertSame(9, $item['parcelas_restantes']);
        $this->assertSame(750.0, $item['valor_pago']);
        $this->assertSame(2250.0, $item['valor_aberto']);
        $this->assertSame(3000.0, $item['valor_total']);
        $this->assertSame(25.0, $item['percentual_pago']);
        $this->assertSame(4, $item['proxima_parcela']['parcela_atual']);
        $this->assertSame(9, $item['proxima_parcela']['mes']);
        $this->assertSame(2026, $item['proxima_parcela']['ano']);
        $this->assertSame(1, $item['primeira_parcela']['parcela_atual']);
        $this->assertSame(12, $item['ultima_parcela']['parcela_atual']);
    }

    public function test_agrupar_compras_por_grupo(): void
    {
        $refKey = $this->service->competenciaKey(8, 2026);
        $parcelas = $this->makeGrupo('g1', 'Geladeira', 'Magazine', 12, 1, 100.0, 8, 2026)
            ->concat($this->makeGrupo('g2', 'Impressora', 'Kalunga', 12, 2, 50.0, 8, 2026));

        $itens = $this->service->agruparCompras($parcelas, 8, 2026);

        $this->assertCount(2, $itens);
        $ids = $itens->pluck('compra_grupo_id')->sort()->values()->all();
        $this->assertSame(['g1', 'g2'], $ids);
        $this->assertSame($refKey, $this->service->competenciaKey(8, 2026));
    }

    /**
     * Gera N parcelas materializadas a partir da competência da parcela `parcelaAtual`.
     *
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

            $items[] = (object) [
                'id' => $i,
                'compra_grupo_id' => $grupoId,
                'data' => sprintf('%04d-%02d-10', $anoRef, max(1, $mesRef - $parcelaAtualNaRef + 1)),
                'valor' => $valorParcela,
                'valor_parcela' => $valorParcela,
                'parcela_atual' => $i,
                'parcelas_total' => $total,
                'observacoes' => $observacoes,
                'origem_compra' => 'COMPRAS_PRESENCIAL',
                'categoria_id' => 1,
                'subcategoria_id' => null,
                'responsavel_id' => 1,
                'estabelecimento_id' => 10,
                'fatura_id' => 100 + $i,
                'fatura_mes' => $mes,
                'fatura_ano' => $ano,
                'cartao_id' => 2,
                'cartao_bandeira_id' => 5,
                'estabelecimento_nome' => $estabelecimento,
                'categoria_nome' => 'Casa',
                'subcategoria_nome' => null,
                'responsavel_nome' => 'Eu',
                'cartao_nome' => 'Nubank',
                'cartao_cor_fundo' => '#8b5cf6',
                'cartao_cor_texto' => '#ffffff',
                'bandeira_nome' => 'Mastercard',
            ];
        }

        return collect($items);
    }
}
