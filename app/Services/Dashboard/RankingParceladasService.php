<?php

namespace App\Services\Dashboard;

use App\Services\Cartao\BandeiraCoresPreset;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RankingParceladasService
{
    public const ORDENAR_RESTANTES_DESC = 'restantes_desc';
    public const ORDENAR_RESTANTES_ASC = 'restantes_asc';
    public const ORDENAR_PERCENTUAL_ASC = 'percentual_asc';
    public const ORDENAR_PERCENTUAL_DESC = 'percentual_desc';
    public const ORDENAR_VALOR_ABERTO_DESC = 'valor_aberto_desc';
    public const ORDENAR_DATA_COMPRA_DESC = 'data_compra_desc';

    public const ORDENACOES = [
        self::ORDENAR_RESTANTES_DESC,
        self::ORDENAR_RESTANTES_ASC,
        self::ORDENAR_PERCENTUAL_ASC,
        self::ORDENAR_PERCENTUAL_DESC,
        self::ORDENAR_VALOR_ABERTO_DESC,
        self::ORDENAR_DATA_COMPRA_DESC,
    ];

    private const MESES_JANELA = 13;
    private const MESES_ANTES_DO_CENTRO = 6;

    private const MESES_LABEL = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    public function handleRanking(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $mes = (int) ($atributes->mes ?? now()->month);
            $ano = (int) ($atributes->ano ?? now()->year);
            $apenasAbertas = $this->parseApenasAbertas($atributes->apenas_abertas ?? 1);
            // Ranking fixo: menor percentual de conclusão no topo (ignora ordenar legado do front)
            $ordenar = self::ORDENAR_PERCENTUAL_ASC;
            if (!empty($atributes->ordenar) && (string) $atributes->ordenar === self::ORDENAR_PERCENTUAL_DESC) {
                $ordenar = self::ORDENAR_PERCENTUAL_DESC;
            }

            $colunas = $this->buildColunas($mes, $ano);

            $parcelas = $this->loadParcelas($userId);
            $itens = $this->agruparCompras($parcelas, $mes, $ano);
            $itens = $this->aplicarFiltros($itens, $atributes);

            if ($apenasAbertas) {
                $refKey = $this->competenciaKey($mes, $ano);
                $itens = $itens
                    ->filter(fn (array $item) => $this->estaVisivelNoRanking($item, $refKey))
                    ->values();
            }

            $itens = $itens->map(function (array $item) use ($colunas) {
                $item['quitada'] = $this->estaQuitada($item);
                $item['estimativa_termino'] = $this->formatCompetenciaLabel(
                    (int) ($item['ultima_parcela']['mes'] ?? 0),
                    (int) ($item['ultima_parcela']['ano'] ?? 0)
                );
                $item['timeline'] = $this->buildTimeline($item, $colunas);

                return $item;
            });

            $itens = $this->ordenarItens($itens, $ordenar);
            $totais = $this->buildTotais($itens);

            return (object) [
                'data' => [
                    'referencia' => [
                        'mes' => $mes,
                        'ano' => $ano,
                    ],
                    'ordenar_aplicada' => $ordenar,
                    'colunas' => $colunas,
                    'totais' => $totais,
                    'itens' => $itens->values()->all(),
                ],
                'status' => true,
                'message' => 'Ranking de parceladas carregado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Janela de 13 meses com a competência filtrada sempre no centro (índice 6).
     *
     * @return array<int, array{mes: int, ano: int, chave: string, label: string, centro: bool, indice: int}>
     */
    public function buildColunas(int $mes, int $ano): array
    {
        $inicio = Carbon::create($ano, $mes, 1)->subMonthsNoOverflow(self::MESES_ANTES_DO_CENTRO);
        $colunas = [];

        for ($i = 0; $i < self::MESES_JANELA; $i++) {
            $cursor = $inicio->copy()->addMonthsNoOverflow($i);
            $m = (int) $cursor->month;
            $a = (int) $cursor->year;
            $colunas[] = [
                'mes' => $m,
                'ano' => $a,
                'chave' => sprintf('%04d-%02d', $a, $m),
                'label' => $this->formatCompetenciaLabel($m, $a),
                'centro' => $i === self::MESES_ANTES_DO_CENTRO,
                'indice' => $i,
            ];
        }

        return $colunas;
    }

    /**
     * @return Collection<int, object>
     */
    private function loadParcelas(int $userId): Collection
    {
        return DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 't.categoria_id')->whereNull('cat.deleted_at');
            })
            ->leftJoin('subcategorias as sub', function ($join) {
                $join->on('sub.id', '=', 't.subcategoria_id')->whereNull('sub.deleted_at');
            })
            ->leftJoin('responsaveis as resp', function ($join) {
                $join->on('resp.id', '=', 't.responsavel_id')->whereNull('resp.deleted_at');
            })
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_bandeiras as cb', function ($join) {
                $join->on('cb.id', '=', 'f.cartao_bandeira_id')->whereNull('cb.deleted_at');
            })
            ->where('t.user_id', $userId)
            ->whereNull('t.deleted_at')
            ->where('t.tipo', 'purchase')
            ->where('t.parcelas_total', '>', 1)
            ->whereNotNull('t.compra_grupo_id')
            ->select([
                't.id',
                't.compra_grupo_id',
                't.data',
                't.valor',
                't.valor_parcela',
                't.parcela_atual',
                't.parcelas_total',
                't.observacoes',
                't.descricao',
                't.origem_compra',
                't.categoria_id',
                't.subcategoria_id',
                't.responsavel_id',
                't.estabelecimento_id',
                't.fatura_id',
                'f.mes as fatura_mes',
                'f.ano as fatura_ano',
                'f.cartao_id',
                'f.cartao_bandeira_id',
                'est.nome as estabelecimento_nome',
                'cat.nome as categoria_nome',
                'sub.nome as subcategoria_nome',
                'resp.nome as responsavel_nome',
                'c.nome as cartao_nome',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'cb.bandeira as bandeira_nome',
            ])
            ->orderBy('t.compra_grupo_id')
            ->orderBy('t.parcela_atual')
            ->get();
    }

    /**
     * @param Collection<int, object> $parcelas
     * @return Collection<int, array>
     */
    public function agruparCompras(Collection $parcelas, int $mesRef, int $anoRef): Collection
    {
        $refKey = $this->competenciaKey($mesRef, $anoRef);

        return $parcelas
            ->groupBy('compra_grupo_id')
            ->map(fn (Collection $grupo) => $this->buildItemFromGrupo($grupo, $refKey))
            ->values();
    }

    /**
     * @param Collection<int, object> $grupo
     */
    public function buildItemFromGrupo(Collection $grupo, int $refKey): array
    {
        $ordenado = $grupo->sortBy(fn ($p) => $this->competenciaKey((int) $p->fatura_mes, (int) $p->fatura_ano))->values();
        $meta = $ordenado->sortBy('parcela_atual')->first();

        $pagas = $ordenado->filter(
            fn ($p) => $this->competenciaKey((int) $p->fatura_mes, (int) $p->fatura_ano) <= $refKey
        );
        $abertas = $ordenado->filter(
            fn ($p) => $this->competenciaKey((int) $p->fatura_mes, (int) $p->fatura_ano) > $refKey
        );

        $parcelasTotal = (int) ($meta->parcelas_total ?? $ordenado->count());
        $parcelasPagas = $pagas->count();
        $parcelasRestantes = max($parcelasTotal - $parcelasPagas, 0);

        $valorPago = round((float) $pagas->sum('valor'), 2);
        $valorAberto = round((float) $abertas->sum('valor'), 2);
        $valorTotal = round((float) $ordenado->sum('valor'), 2);
        $percentualPago = $valorTotal > 0
            ? round(($valorPago / $valorTotal) * 100, 2)
            : 0.0;

        $parcelaAtualRow = $pagas->sortByDesc('parcela_atual')->first();
        $parcelaAtual = $parcelaAtualRow
            ? (int) $parcelaAtualRow->parcela_atual
            : 0;

        $proxima = $abertas->sortBy(
            fn ($p) => $this->competenciaKey((int) $p->fatura_mes, (int) $p->fatura_ano)
        )->first();

        $primeira = $ordenado->first();
        $ultima = $ordenado->last();

        $tituloInfo = $this->resolveTitulo(
            $meta->observacoes ?? null,
            $meta->estabelecimento_nome ?? null,
            $meta->descricao ?? null
        );

        $valorParcela = $meta->valor_parcela !== null
            ? round((float) $meta->valor_parcela, 2)
            : round((float) $meta->valor, 2);

        $primeiraParcela = $primeira ? $this->mapParcelaResumo($primeira) : null;
        $ultimaParcela = $ultima ? $this->mapParcelaResumo($ultima) : null;
        $competenciaAtual = $parcelaAtualRow
            ? $this->mapParcelaResumo($parcelaAtualRow)
            : $primeiraParcela;
        $coresBandeira = BandeiraCoresPreset::anexar($meta->bandeira_nome ?? null);

        return [
            'compra_grupo_id' => (string) $meta->compra_grupo_id,
            'titulo' => $tituloInfo['titulo'],
            'titulo_origem' => $tituloInfo['titulo_origem'],
            'observacoes' => $meta->observacoes,
            'descricao' => $meta->descricao ?? null,
            'estabelecimento_id' => $meta->estabelecimento_id !== null ? (int) $meta->estabelecimento_id : null,
            'estabelecimento_nome' => $meta->estabelecimento_nome,
            'data_compra' => $meta->data,
            'parcelas_total' => $parcelasTotal,
            'parcela_atual' => $parcelaAtual,
            'parcelas_pagas' => $parcelasPagas,
            'parcelas_restantes' => $parcelasRestantes,
            'valor_parcela' => $valorParcela,
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_aberto' => $valorAberto,
            'percentual_pago' => $percentualPago,
            'categoria_id' => $meta->categoria_id !== null ? (int) $meta->categoria_id : null,
            'categoria_nome' => $meta->categoria_nome,
            'subcategoria_id' => $meta->subcategoria_id !== null ? (int) $meta->subcategoria_id : null,
            'subcategoria_nome' => $meta->subcategoria_nome,
            'responsavel_id' => $meta->responsavel_id !== null ? (int) $meta->responsavel_id : null,
            'responsavel_nome' => $meta->responsavel_nome,
            'cartao_id' => $meta->cartao_id !== null ? (int) $meta->cartao_id : null,
            'cartao_nome' => $meta->cartao_nome,
            'cartao_cor_fundo' => $meta->cartao_cor_fundo ?? null,
            'cartao_cor_texto' => $meta->cartao_cor_texto ?? null,
            'cartao_bandeira_id' => $meta->cartao_bandeira_id !== null ? (int) $meta->cartao_bandeira_id : null,
            'bandeira_nome' => $meta->bandeira_nome,
            'bandeira_cor_principal' => $coresBandeira['cor_principal'],
            'bandeira_cor_secundaria' => $coresBandeira['cor_secundaria'],
            'origem_compra' => $meta->origem_compra,
            'proxima_parcela' => $proxima ? $this->mapParcelaResumo($proxima) : null,
            'primeira_parcela' => $primeiraParcela,
            'ultima_parcela' => $ultimaParcela,
            'competencia_atual' => $competenciaAtual,
        ];
    }

    /**
     * Visível com apenas_abertas=1 quando a última parcela ainda é na competência
     * de referência ou no futuro. Some do ranking se a última parcela foi no mês anterior.
     *
     * @param array $item
     */
    public function estaVisivelNoRanking(array $item, int $refKey): bool
    {
        $ultima = $item['ultima_parcela'] ?? null;
        if (!$ultima || empty($ultima['mes']) || empty($ultima['ano'])) {
            return ($item['parcelas_restantes'] ?? 0) > 0;
        }

        return $this->competenciaKey((int) $ultima['mes'], (int) $ultima['ano']) >= $refKey;
    }

    /**
     * Compra 100% paga na referência (sem valor em aberto).
     */
    public function estaQuitada(array $item): bool
    {
        if (($item['percentual_pago'] ?? 0) >= 100) {
            return true;
        }

        return ((float) ($item['valor_aberto'] ?? 0)) <= 0.009
            && ((int) ($item['parcelas_restantes'] ?? 0)) === 0;
    }

    /**
     * @param array<int, array{chave: string, indice: int}> $colunas
     * @return array{
     *   inicio_chave: ?string,
     *   fim_chave: ?string,
     *   progresso_chave: ?string,
     *   indice_inicio: ?int,
     *   indice_fim: ?int,
     *   indice_progresso: ?int,
     *   fora_da_janela: bool
     * }
     */
    public function buildTimeline(array $item, array $colunas): array
    {
        $porChave = [];
        foreach ($colunas as $coluna) {
            $porChave[$coluna['chave']] = (int) $coluna['indice'];
        }

        $inicioChave = $this->chaveFromParcela($item['primeira_parcela'] ?? null);
        $fimChave = $this->chaveFromParcela($item['ultima_parcela'] ?? null);
        $progressoChave = $this->chaveFromParcela($item['competencia_atual'] ?? null)
            ?? $inicioChave;

        if ($this->estaQuitada($item)) {
            $progressoChave = $fimChave;
        }

        $indiceInicio = $inicioChave !== null ? ($porChave[$inicioChave] ?? null) : null;
        $indiceFim = $fimChave !== null ? ($porChave[$fimChave] ?? null) : null;
        $indiceProgresso = $progressoChave !== null ? ($porChave[$progressoChave] ?? null) : null;

        // Se o período cruza a janela mas início/fim caem fora, clipa nas bordas
        if ($inicioChave !== null && $fimChave !== null && $indiceInicio === null && $indiceFim === null) {
            $primeiro = $colunas[0]['chave'] ?? null;
            $ultimo = $colunas[self::MESES_JANELA - 1]['chave'] ?? null;
            if ($primeiro && $ultimo && $inicioChave <= $ultimo && $fimChave >= $primeiro) {
                $indiceInicio = 0;
                $indiceFim = self::MESES_JANELA - 1;
            }
        } else {
            if ($inicioChave !== null && $indiceInicio === null && $fimChave !== null && isset($porChave[$fimChave])) {
                $indiceInicio = 0;
            }
            if ($fimChave !== null && $indiceFim === null && $inicioChave !== null && isset($porChave[$inicioChave])) {
                $indiceFim = self::MESES_JANELA - 1;
            }
        }

        if ($indiceProgresso === null && $indiceInicio !== null && $indiceFim !== null) {
            if ($progressoChave !== null && $progressoChave < ($colunas[0]['chave'] ?? '')) {
                $indiceProgresso = $indiceInicio;
            } elseif ($progressoChave !== null && $progressoChave > ($colunas[self::MESES_JANELA - 1]['chave'] ?? '')) {
                $indiceProgresso = $indiceFim;
            }
        }

        $fora = $indiceInicio === null || $indiceFim === null;

        return [
            'inicio_chave' => $inicioChave,
            'fim_chave' => $fimChave,
            'progresso_chave' => $progressoChave,
            'indice_inicio' => $indiceInicio,
            'indice_fim' => $indiceFim,
            'indice_progresso' => $indiceProgresso,
            'fora_da_janela' => $fora,
        ];
    }

    /**
     * @return array{titulo: string, titulo_origem: string}
     */
    public function resolveTitulo(
        ?string $observacoes,
        ?string $estabelecimentoNome,
        ?string $descricao = null
    ): array {
        $obs = trim((string) ($observacoes ?? ''));
        if ($obs !== '') {
            return [
                'titulo' => $obs,
                'titulo_origem' => 'observacoes',
            ];
        }

        $desc = trim((string) ($descricao ?? ''));
        if ($desc !== '') {
            return [
                'titulo' => $desc,
                'titulo_origem' => 'descricao',
            ];
        }

        $nome = trim((string) ($estabelecimentoNome ?? ''));

        return [
            'titulo' => $nome !== '' ? $nome : 'Compra parcelada',
            'titulo_origem' => 'estabelecimento',
        ];
    }

    /**
     * @param Collection<int, array> $itens
     * @return Collection<int, array>
     */
    public function ordenarItens(Collection $itens, string $ordenar): Collection
    {
        $itens = $itens->map(function (array $item) {
            $item['quitada'] = $this->estaQuitada($item);
            $item['percentual_pago'] = round((float) ($item['percentual_pago'] ?? 0), 2);

            return $item;
        });

        // Ranking principal: menor percentual de conclusão no topo; 100% no final
        if ($ordenar === self::ORDENAR_PERCENTUAL_ASC) {
            return $itens
                ->sort(fn (array $a, array $b) => $this->compararMenorPercentualNoTopo($a, $b))
                ->values();
        }

        $sorted = match ($ordenar) {
            self::ORDENAR_RESTANTES_ASC => $itens->sort(function (array $a, array $b) {
                return $this->compararComQuitadasPorUltimo($a, $b)
                    ?? (((int) $a['parcelas_restantes']) <=> ((int) $b['parcelas_restantes']))
                    ?: (((float) $b['valor_aberto']) <=> ((float) $a['valor_aberto']))
                    ?: strcmp((string) $a['titulo'], (string) $b['titulo']);
            }),
            self::ORDENAR_RESTANTES_DESC => $itens->sort(function (array $a, array $b) {
                return $this->compararComQuitadasPorUltimo($a, $b)
                    ?? (((int) $b['parcelas_restantes']) <=> ((int) $a['parcelas_restantes']))
                    ?: (((float) $b['valor_aberto']) <=> ((float) $a['valor_aberto']))
                    ?: strcmp((string) $a['titulo'], (string) $b['titulo']);
            }),
            self::ORDENAR_PERCENTUAL_DESC => $itens->sort(function (array $a, array $b) {
                return $this->compararComQuitadasPorUltimo($a, $b)
                    ?? (((float) $b['percentual_pago']) <=> ((float) $a['percentual_pago']))
                    ?: strcmp((string) $a['titulo'], (string) $b['titulo']);
            }),
            self::ORDENAR_VALOR_ABERTO_DESC => $itens->sort(function (array $a, array $b) {
                return $this->compararComQuitadasPorUltimo($a, $b)
                    ?? (((float) $b['valor_aberto']) <=> ((float) $a['valor_aberto']))
                    ?: strcmp((string) $a['titulo'], (string) $b['titulo']);
            }),
            self::ORDENAR_DATA_COMPRA_DESC => $itens->sort(function (array $a, array $b) {
                return $this->compararComQuitadasPorUltimo($a, $b)
                    ?? strcmp((string) ($b['data_compra'] ?? ''), (string) ($a['data_compra'] ?? ''))
                    ?: strcmp((string) $a['titulo'], (string) $b['titulo']);
            }),
            default => $itens->sort(fn (array $a, array $b) => $this->compararMenorPercentualNoTopo($a, $b)),
        };

        return $sorted->values();
    }

    /**
     * Menor percentual de conclusão no topo; quitadas (100%) sempre no final.
     */
    public function compararMenorPercentualNoTopo(array $a, array $b): int
    {
        $quitadas = $this->compararComQuitadasPorUltimo($a, $b);
        if ($quitadas !== null) {
            return $quitadas;
        }

        $pa = (float) ($a['percentual_pago'] ?? 0);
        $pb = (float) ($b['percentual_pago'] ?? 0);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcmp((string) ($a['titulo'] ?? ''), (string) ($b['titulo'] ?? ''));
    }

    /**
     * @return int|null null = ambas abertas ou ambas quitadas (seguir critério seguinte)
     */
    private function compararComQuitadasPorUltimo(array $a, array $b): ?int
    {
        $qa = !empty($a['quitada']) ? 1 : 0;
        $qb = !empty($b['quitada']) ? 1 : 0;

        if ($qa === $qb) {
            return null;
        }

        return $qa <=> $qb;
    }

    public function competenciaKey(int $mes, int $ano): int
    {
        return ($ano * 12) + $mes;
    }

    public function formatCompetenciaLabel(int $mes, int $ano): string
    {
        if ($mes < 1 || $mes > 12 || $ano < 1) {
            return '';
        }

        return self::MESES_LABEL[$mes] . '/' . $ano;
    }

    /**
     * @param Collection<int, array> $itens
     * @return Collection<int, array>
     */
    private function aplicarFiltros(Collection $itens, object $atributes): Collection
    {
        if (!empty($atributes->cartao_id)) {
            $cartaoId = (int) $atributes->cartao_id;
            $itens = $itens->filter(fn (array $i) => (int) ($i['cartao_id'] ?? 0) === $cartaoId);
        }

        if (!empty($atributes->responsavel_id)) {
            $responsavelId = (int) $atributes->responsavel_id;
            $itens = $itens->filter(fn (array $i) => (int) ($i['responsavel_id'] ?? 0) === $responsavelId);
        }

        if (!empty($atributes->categoria_id)) {
            $categoriaId = (int) $atributes->categoria_id;
            $itens = $itens->filter(fn (array $i) => (int) ($i['categoria_id'] ?? 0) === $categoriaId);
        }

        $palavra = trim((string) ($atributes->palavra_chave ?? ''));
        if ($palavra !== '') {
            $needle = mb_strtolower($palavra);
            $itens = $itens->filter(function (array $i) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $i['titulo'] ?? '',
                    $i['descricao'] ?? '',
                    $i['observacoes'] ?? '',
                    $i['estabelecimento_nome'] ?? '',
                ])));

                return str_contains($haystack, $needle);
            });
        }

        return $itens->values();
    }

    /**
     * @param Collection<int, array> $itens
     */
    private function buildTotais(Collection $itens): array
    {
        $valorTotal = round((float) $itens->sum('valor_total'), 2);
        $valorPago = round((float) $itens->sum('valor_pago'), 2);
        $valorAberto = round((float) $itens->sum('valor_aberto'), 2);

        return [
            'compras' => $itens->count(),
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_aberto' => $valorAberto,
            'percentual_pago' => $valorTotal > 0
                ? round(($valorPago / $valorTotal) * 100, 2)
                : 0.0,
        ];
    }

    private function mapParcelaResumo(object $parcela): array
    {
        return [
            'parcela_atual' => (int) $parcela->parcela_atual,
            'mes' => (int) $parcela->fatura_mes,
            'ano' => (int) $parcela->fatura_ano,
            'valor' => round((float) $parcela->valor, 2),
            'fatura_id' => (int) $parcela->fatura_id,
        ];
    }

    private function chaveFromParcela(?array $parcela): ?string
    {
        if (!$parcela || empty($parcela['mes']) || empty($parcela['ano'])) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $parcela['ano'], (int) $parcela['mes']);
    }

    private function parseApenasAbertas(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array($value, [0, '0', false, 'false', 'off', 'no'], true);
    }

    private function resolveOrdenacao(mixed $ordenar): string
    {
        $ordenar = (string) $ordenar;

        return in_array($ordenar, self::ORDENACOES, true)
            ? $ordenar
            : self::ORDENAR_PERCENTUAL_ASC;
    }
}
