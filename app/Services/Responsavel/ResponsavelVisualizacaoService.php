<?php

namespace App\Services\Responsavel;

use App\Models\Pessoa;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Services\Dashboard\RankingParceladasService;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ResponsavelVisualizacaoService
{
    private const TIPO_LABELS = [
        'pessoal' => 'Pessoal',
        'empresa' => 'Empresa',
    ];

    private const COMPRAS_RECENTES_LIMITE = 8;
    private const PARCELADAS_PREVIEW_LIMITE = 5;
    private const FATURAS_PADRAO_LIMITE = 8;
    private const TOLERANCIA = 0.01;

    private RankingParceladasService $rankingService;

    public function __construct(?RankingParceladasService $rankingService = null)
    {
        $this->rankingService = $rankingService ?? new RankingParceladasService();
    }

    public function handleVisualizar(int|string $id, object $atributes): object
    {
        $userId = (int) Auth::id();
        $mes = (int) ($atributes->mes ?? now()->month);
        $ano = (int) ($atributes->ano ?? now()->year);

        $responsavel = Responsavel::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$responsavel) {
            throw new Exception('Responsável não encontrado', 404);
        }

        $responsavelId = (int) $responsavel->id;
        $compras = $this->loadCompras($userId, $responsavelId);
        $parceladasAbertas = $this->buildParceladasAbertas($compras, $mes, $ano);
        $repasse = $this->buildResumoRepasse($userId, $compras, $mes, $ano);

        $data = [
            'id' => $responsavelId,
            'nome' => $responsavel->nome,
            'tipo' => $responsavel->tipo,
            'tipo_label' => self::TIPO_LABELS[$responsavel->tipo] ?? $responsavel->tipo,
            'ativo' => (bool) $responsavel->ativo,
            'eh_eu' => $this->ehEu($responsavel->nome),
            'referencia' => [
                'mes' => $mes,
                'ano' => $ano,
                'label' => $this->rankingService->formatCompetenciaLabel($mes, $ano),
            ],
            'pessoa' => $this->loadPessoa($userId, $responsavelId),
            'totais' => $this->buildTotaisHistorico($compras),
            'em_aberto' => $this->buildEmAberto($parceladasAbertas),
            'repasse' => $repasse,
            'competencia' => $this->buildCompetencia($compras, $mes, $ano),
            'por_cartao' => $this->buildPorCartaoCompetencia($compras, $mes, $ano),
            'por_categoria' => $this->buildPorCategoria($compras),
            'faturas_padrao' => $this->loadFaturasPadrao($userId, $responsavelId),
            'compras_recentes' => $this->buildComprasRecentes($compras),
            'parceladas_abertas' => $parceladasAbertas
                ->take(self::PARCELADAS_PREVIEW_LIMITE)
                ->values()
                ->all(),
            'atalhos' => [
                'fatura_responsavel' => [
                    'responsavel_id' => $responsavelId,
                    'mes' => $mes,
                    'ano' => $ano,
                ],
                'repasses' => [
                    'responsavel_id' => $responsavelId,
                    'mes' => $mes,
                    'ano' => $ano,
                ],
                'ranking_parceladas' => [
                    'responsavel_id' => $responsavelId,
                    'mes' => $mes,
                    'ano' => $ano,
                    'apenas_abertas' => 1,
                ],
                'compras' => [
                    'responsavel_id' => $responsavelId,
                    'tipo' => Transacao::TIPO_PURCHASE,
                ],
            ],
        ];

        return (object) [
            'data' => $data,
            'status' => true,
            'message' => 'Responsável carregado com sucesso!',
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function loadCompras(int $userId, int $responsavelId): Collection
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
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_bandeiras as cb', function ($join) {
                $join->on('cb.id', '=', 'f.cartao_bandeira_id')->whereNull('cb.deleted_at');
            })
            ->leftJoin('responsaveis as resp', function ($join) {
                $join->on('resp.id', '=', 't.responsavel_id')->whereNull('resp.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('t.responsavel_id', $responsavelId)
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->select([
                't.id',
                't.compra_grupo_id',
                't.data',
                't.valor',
                't.valor_parcela',
                't.parcela_atual',
                't.parcelas_total',
                't.observacoes',
                't.estabelecimento_id',
                't.categoria_id',
                't.subcategoria_id',
                't.responsavel_id',
                't.origem_compra',
                't.fatura_id',
                'f.mes as fatura_mes',
                'f.ano as fatura_ano',
                'f.cartao_id',
                'f.cartao_bandeira_id',
                'est.nome as estabelecimento_nome',
                'cat.nome as categoria_nome',
                'cat.cor as categoria_cor',
                'sub.nome as subcategoria_nome',
                'c.nome as cartao_nome',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'cb.bandeira as bandeira_nome',
                'resp.nome as responsavel_nome',
            ])
            ->orderByDesc('t.data')
            ->orderBy('t.parcela_atual')
            ->orderBy('t.id')
            ->get();
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<string, mixed>
     */
    private function buildTotaisHistorico(Collection $compras): array
    {
        $eventos = $this->agruparEventos($compras);
        $parceladas = $eventos->filter(fn (array $e) => $e['parcelada'])->count();
        $valorTotal = round((float) $compras->sum('valor'), 2);
        $qtd = $eventos->count();

        return [
            'compras' => $qtd,
            'ocorrencias' => $compras->count(),
            'avista' => $qtd - $parceladas,
            'parceladas' => $parceladas,
            'valor_total' => $valorTotal,
            'ticket_medio' => $qtd > 0 ? round($valorTotal / $qtd, 2) : 0.0,
            'primeira_compra' => $compras->min('data'),
            'ultima_compra' => $compras->max('data'),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $parceladasAbertas
     * @return array<string, mixed>
     */
    private function buildEmAberto(Collection $parceladasAbertas): array
    {
        $valorTotal = round((float) $parceladasAbertas->sum('valor_total'), 2);
        $valorPago = round((float) $parceladasAbertas->sum('valor_pago'), 2);
        $valorAberto = round((float) $parceladasAbertas->sum('valor_aberto'), 2);

        return [
            'compras' => $parceladasAbertas->count(),
            'parcelas_restantes' => (int) $parceladasAbertas->sum('parcelas_restantes'),
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_aberto' => $valorAberto,
            'percentual_pago' => $valorTotal > 0
                ? round(($valorPago / $valorTotal) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Parceladas ainda ativas na competência (última parcela ≥ referência).
     *
     * @param Collection<int, object> $compras
     * @return Collection<int, array<string, mixed>>
     */
    private function buildParceladasAbertas(Collection $compras, int $mes, int $ano): Collection
    {
        $parcelas = $compras->filter(function ($row) {
            return !empty($row->compra_grupo_id) && (int) ($row->parcelas_total ?? 0) > 1;
        })->values();

        if ($parcelas->isEmpty()) {
            return collect();
        }

        $refKey = $this->rankingService->competenciaKey($mes, $ano);

        return $this->rankingService
            ->agruparCompras($parcelas, $mes, $ano)
            ->map(function (array $item) {
                $item['quitada'] = $this->rankingService->estaQuitada($item);
                $item['estimativa_termino'] = $this->rankingService->formatCompetenciaLabel(
                    (int) ($item['ultima_parcela']['mes'] ?? 0),
                    (int) ($item['ultima_parcela']['ano'] ?? 0)
                );
                $item['identificador'] = $item['compra_grupo_id'];

                return $item;
            })
            ->filter(fn (array $item) => $this->rankingService->estaVisivelNoRanking($item, $refKey))
            ->sortByDesc('valor_aberto')
            ->values();
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<string, mixed>
     */
    private function buildResumoRepasse(int $userId, Collection $compras, int $mes, int $ano): array
    {
        $ids = $compras->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pagos = $this->loadPagosPorTransacao($userId, $ids);
        $refKey = $this->rankingService->competenciaKey($mes, $ano);

        $porCompra = [];
        $valorAbertoRef = 0.0;
        $parcelasPendentesRef = 0;

        foreach ($compras as $linha) {
            $chave = $this->chaveCompra($linha);
            if (!isset($porCompra[$chave])) {
                $porCompra[$chave] = [
                    'valor_total' => 0.0,
                    'valor_pago' => 0.0,
                ];
            }

            $valor = (float) $linha->valor;
            $pago = (float) ($pagos[(int) $linha->id]['valor_pago'] ?? 0);
            $aberto = max($valor - $pago, 0);

            $porCompra[$chave]['valor_total'] += $valor;
            $porCompra[$chave]['valor_pago'] += $pago;

            $linhaKey = $this->rankingService->competenciaKey(
                (int) $linha->fatura_mes,
                (int) $linha->fatura_ano
            );
            if ($linhaKey === $refKey && $aberto > self::TOLERANCIA) {
                $parcelasPendentesRef++;
                $valorAbertoRef += $aberto;
            }
        }

        $valorTotal = 0.0;
        $valorPago = 0.0;
        $comprasAbertas = 0;
        $comprasPagas = 0;

        foreach ($porCompra as $compra) {
            $valorTotal += $compra['valor_total'];
            $valorPago += $compra['valor_pago'];
            $aberto = $compra['valor_total'] - $compra['valor_pago'];
            if ($aberto > self::TOLERANCIA) {
                $comprasAbertas++;
            } else {
                $comprasPagas++;
            }
        }

        $valorAberto = max($valorTotal - $valorPago, 0);

        return [
            'valor_total_compras' => $this->roundMoney($valorTotal),
            'valor_pago' => $this->roundMoney($valorPago),
            'valor_aberto' => $this->roundMoney($valorAberto),
            'compras_abertas' => $comprasAbertas,
            'compras_pagas' => $comprasPagas,
            'parcelas_pendentes_na_referencia' => $parcelasPendentesRef,
            'valor_aberto_na_referencia' => $this->roundMoney($valorAbertoRef),
        ];
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<string, mixed>
     */
    private function buildCompetencia(Collection $compras, int $mes, int $ano): array
    {
        $doMes = $compras->filter(
            fn ($row) => (int) $row->fatura_mes === $mes && (int) $row->fatura_ano === $ano
        );
        $eventos = $this->agruparEventos($doMes);
        $valorTotal = round((float) $doMes->sum('valor'), 2);

        return [
            'mes' => $mes,
            'ano' => $ano,
            'label' => $this->rankingService->formatCompetenciaLabel($mes, $ano),
            'compras' => $eventos->count(),
            'ocorrencias' => $doMes->count(),
            'valor_total' => $valorTotal,
        ];
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<int, array<string, mixed>>
     */
    private function buildPorCartaoCompetencia(Collection $compras, int $mes, int $ano): array
    {
        $doMes = $compras->filter(
            fn ($row) => (int) $row->fatura_mes === $mes && (int) $row->fatura_ano === $ano
        );

        return $doMes
            ->groupBy(fn ($row) => (int) ($row->cartao_id ?? 0))
            ->map(function (Collection $linhas, $cartaoId) {
                $meta = $linhas->first();
                $eventos = $this->agruparEventos($linhas);
                $faturas = $linhas
                    ->groupBy(fn ($row) => (int) $row->fatura_id)
                    ->map(function (Collection $doFatura) {
                        $f = $doFatura->first();

                        return [
                            'id' => (int) $f->fatura_id,
                            'cartao_bandeira_id' => $f->cartao_bandeira_id !== null
                                ? (int) $f->cartao_bandeira_id
                                : null,
                            'bandeira' => $f->bandeira_nome,
                            'valor_total' => round((float) $doFatura->sum('valor'), 2),
                            'ocorrencias' => $doFatura->count(),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'cartao_id' => $cartaoId > 0 ? (int) $cartaoId : null,
                    'cartao_nome' => $meta->cartao_nome ?: 'Sem cartão',
                    'cor_fundo' => $meta->cartao_cor_fundo,
                    'cor_texto' => $meta->cartao_cor_texto,
                    'compras' => $eventos->count(),
                    'ocorrencias' => $linhas->count(),
                    'valor_total' => round((float) $linhas->sum('valor'), 2),
                    'fatura_id' => $faturas[0]['id'] ?? null,
                    'faturas' => $faturas,
                ];
            })
            ->sortByDesc('valor_total')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<int, array<string, mixed>>
     */
    private function buildPorCategoria(Collection $compras): array
    {
        return $this->agruparEventos($compras)
            ->groupBy(fn (array $e) => (int) ($e['categoria_id'] ?? 0))
            ->map(function (Collection $eventos, $categoriaId) {
                $meta = $eventos->first();
                $valorTotal = round((float) $eventos->sum('valor_total'), 2);

                return [
                    'categoria_id' => $categoriaId > 0 ? (int) $categoriaId : null,
                    'nome' => $meta['categoria_nome'] ?: 'Sem categoria',
                    'cor' => $meta['categoria_cor'],
                    'compras' => $eventos->count(),
                    'valor_total' => $valorTotal,
                ];
            })
            ->sortByDesc('valor_total')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFaturasPadrao(int $userId, int $responsavelId): array
    {
        return DB::table('faturas as f')
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('pessoas as p', function ($join) {
                $join->on('p.id', '=', 'f.pessoa_id')->whereNull('p.deleted_at');
            })
            ->whereNull('f.deleted_at')
            ->where('f.user_id', $userId)
            ->where('f.responsavel_id', $responsavelId)
            ->orderByDesc('f.ano')
            ->orderByDesc('f.mes')
            ->limit(self::FATURAS_PADRAO_LIMITE)
            ->select([
                'f.id',
                'f.mes',
                'f.ano',
                'f.valor_total',
                'f.status',
                'f.cartao_id',
                'c.nome as cartao_nome',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'f.pessoa_id',
                'p.nome as pessoa_nome',
                'p.sobrenome as pessoa_sobrenome',
            ])
            ->get()
            ->map(function ($row) {
                $pessoaNome = trim(implode(' ', array_filter([
                    (string) ($row->pessoa_nome ?? ''),
                    (string) ($row->pessoa_sobrenome ?? ''),
                ], static fn ($p) => $p !== '')));

                return [
                    'id' => (int) $row->id,
                    'mes' => (int) $row->mes,
                    'ano' => (int) $row->ano,
                    'competencia' => sprintf('%02d/%04d', (int) $row->mes, (int) $row->ano),
                    'label' => $this->rankingService->formatCompetenciaLabel(
                        (int) $row->mes,
                        (int) $row->ano
                    ),
                    'valor_total' => round((float) $row->valor_total, 2),
                    'status' => $row->status,
                    'cartao_id' => $row->cartao_id !== null ? (int) $row->cartao_id : null,
                    'cartao_nome' => $row->cartao_nome,
                    'cartao_cor_fundo' => $row->cartao_cor_fundo,
                    'cartao_cor_texto' => $row->cartao_cor_texto,
                    'pessoa_id' => $row->pessoa_id !== null ? (int) $row->pessoa_id : null,
                    'pessoa_nome' => $pessoaNome !== '' ? $pessoaNome : null,
                ];
            })
            ->all();
    }

    /**
     * @param Collection<int, object> $compras
     * @return array<int, array<string, mixed>>
     */
    private function buildComprasRecentes(Collection $compras): array
    {
        $recentes = [];

        foreach ($this->agruparEventos($compras) as $evento) {
            $recentes[] = [
                'identificador' => $evento['identificador'],
                'compra_grupo_id' => $evento['compra_grupo_id'],
                'transacao_id' => $evento['transacao_id'],
                'titulo' => $evento['titulo'],
                'data' => $evento['data'],
                'valor' => $evento['valor_linha'],
                'valor_total' => $evento['valor_total'],
                'parcelas_total' => $evento['parcelas_total'],
                'avista' => !$evento['parcelada'],
                'estabelecimento' => $evento['estabelecimento_nome'],
                'cartao_id' => $evento['cartao_id'],
                'cartao_nome' => $evento['cartao_nome'],
                'cartao_cor_fundo' => $evento['cartao_cor_fundo'],
                'cartao_cor_texto' => $evento['cartao_cor_texto'],
                'fatura_id' => $evento['fatura_id'],
                'fatura_mes' => $evento['fatura_mes'],
                'fatura_ano' => $evento['fatura_ano'],
            ];

            if (count($recentes) >= self::COMPRAS_RECENTES_LIMITE) {
                break;
            }
        }

        return $recentes;
    }

    /**
     * Uma entrada por compra (grupo = 1; à vista = 1 linha). Ordenado pela data mais recente.
     *
     * @param Collection<int, object> $compras
     * @return Collection<int, array<string, mixed>>
     */
    private function agruparEventos(Collection $compras): Collection
    {
        return $compras
            ->groupBy(fn ($row) => $this->chaveCompra($row))
            ->map(function (Collection $grupo) {
                $ordenado = $grupo->sortBy(function ($row) {
                    return sprintf(
                        '%04d-%02d-%03d',
                        (int) $row->fatura_ano,
                        (int) $row->fatura_mes,
                        (int) ($row->parcela_atual ?? 0)
                    );
                })->values();
                $meta = $ordenado->sortBy('parcela_atual')->first() ?? $ordenado->first();
                $parcelada = !empty($meta->compra_grupo_id) && (int) ($meta->parcelas_total ?? 0) > 1;
                $titulo = trim((string) ($meta->observacoes ?? ''));
                if ($titulo === '') {
                    $titulo = (string) ($meta->estabelecimento_nome ?? 'Compra');
                }

                return [
                    'identificador' => $parcelada
                        ? (string) $meta->compra_grupo_id
                        : (string) $meta->id,
                    'compra_grupo_id' => $parcelada ? (string) $meta->compra_grupo_id : null,
                    'transacao_id' => (int) $meta->id,
                    'titulo' => $titulo,
                    'data' => $meta->data,
                    'valor_linha' => round((float) $meta->valor, 2),
                    'valor_total' => round((float) $grupo->sum('valor'), 2),
                    'parcelas_total' => (int) ($meta->parcelas_total ?? 1),
                    'parcelada' => $parcelada,
                    'estabelecimento_nome' => $meta->estabelecimento_nome,
                    'categoria_id' => $meta->categoria_id !== null ? (int) $meta->categoria_id : null,
                    'categoria_nome' => $meta->categoria_nome,
                    'categoria_cor' => $meta->categoria_cor,
                    'cartao_id' => $meta->cartao_id !== null ? (int) $meta->cartao_id : null,
                    'cartao_nome' => $meta->cartao_nome,
                    'cartao_cor_fundo' => $meta->cartao_cor_fundo,
                    'cartao_cor_texto' => $meta->cartao_cor_texto,
                    'fatura_id' => $meta->fatura_id !== null ? (int) $meta->fatura_id : null,
                    'fatura_mes' => $meta->fatura_mes !== null ? (int) $meta->fatura_mes : null,
                    'fatura_ano' => $meta->fatura_ano !== null ? (int) $meta->fatura_ano : null,
                ];
            })
            ->sortByDesc(fn (array $e) => (string) ($e['data'] ?? ''))
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPessoa(int $userId, int $responsavelId): ?array
    {
        $pessoa = Pessoa::query()
            ->where('user_id', $userId)
            ->where('responsavel_id', $responsavelId)
            ->first();

        if (!$pessoa) {
            return null;
        }

        return $pessoa->toListArray();
    }

    /**
     * @param array<int> $transacaoIds
     * @return array<int, array{valor_pago: float}>
     */
    private function loadPagosPorTransacao(int $userId, array $transacaoIds): array
    {
        if ($transacaoIds === []) {
            return [];
        }

        $rows = DB::table('repasses')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereIn('transacao_id', $transacaoIds)
            ->groupBy('transacao_id')
            ->select(
                'transacao_id',
                DB::raw('SUM(valor) as valor_pago')
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->transacao_id] = [
                'valor_pago' => $this->roundMoney((float) $row->valor_pago),
            ];
        }

        return $map;
    }

    private function chaveCompra(object $row): string
    {
        if (!empty($row->compra_grupo_id)) {
            return (string) $row->compra_grupo_id;
        }

        return 'av-' . (int) $row->id;
    }

    private function ehEu(?string $nome): bool
    {
        return mb_strtolower(trim((string) $nome)) === 'eu';
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
