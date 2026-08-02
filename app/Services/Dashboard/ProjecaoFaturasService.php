<?php

namespace App\Services\Dashboard;

use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProjecaoFaturasService
{
    private const MESES_TOTAL = 13;

    private const MESES_LABEL = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    public function handleProjecao(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $referencia = Carbon::create(
                (int) ($atributes->ano ?? now()->year),
                (int) ($atributes->mes ?? now()->month),
                1
            );

            $colunas = $this->buildColunas($referencia);
            $chavesColunas = collect($colunas)->pluck('chave')->all();

            $cartoes = Cartao::where('user_id', $userId)
                ->where('ativo', true)
                ->with(['bandeiras' => function ($q) {
                    $q->whereNull('deleted_at')->where('ativo', true);
                }])
                ->orderBy('nome')
                ->get([
                    'id',
                    'nome',
                    'dia_limite_fatura',
                    'dia_vencimento_fatura',
                    'cor_fundo',
                    'cor_texto',
                ]);

            $responsaveis = Responsavel::where('user_id', $userId)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo']);

            $faturas = $this->loadFaturas($userId, $colunas);
            $transacoes = $this->loadTransacoes($userId, $colunas);
            $parcelasExistentes = $this->indexParcelasExistentes($transacoes);
            $projecoes = $this->buildProjecoesParcelas($transacoes, $parcelasExistentes, $chavesColunas);

            $porCartao = $this->buildMatrizPorCartao($cartoes, $colunas, $faturas, $transacoes, $projecoes);
            $porResponsavel = $this->buildMatrizPorResponsavel($responsaveis, $colunas, $faturas, $transacoes, $projecoes);
            $porCartaoResponsavel = $this->buildMatrizPorCartaoResponsavel(
                $cartoes,
                $responsaveis,
                $colunas,
                $faturas,
                $transacoes,
                $projecoes
            );

            return (object) [
                'data' => [
                    'referencia' => [
                        'mes' => (int) $referencia->month,
                        'ano' => (int) $referencia->year,
                    ],
                    'colunas' => $colunas,
                    'por_cartao' => $porCartao,
                    'por_responsavel' => $porResponsavel,
                    'por_cartao_responsavel' => $porCartaoResponsavel,
                    'totais_por_coluna' => $this->buildTotaisPorColuna($colunas, $porCartao, $porResponsavel),
                ],
                'status' => true,
                'message' => 'Projeção carregada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array<int, array{mes: int, ano: int, chave: string, label: string, referencia: bool}>
     */
    public function buildColunas(Carbon $referencia): array
    {
        $inicio = $referencia->copy()->subMonth();
        $colunas = [];

        for ($i = 0; $i < self::MESES_TOTAL; $i++) {
            $mes = (int) $inicio->month;
            $ano = (int) $inicio->year;
            $colunas[] = [
                'mes' => $mes,
                'ano' => $ano,
                'chave' => $this->periodoChave($mes, $ano),
                'label' => (self::MESES_LABEL[$mes] ?? (string) $mes) . '/' . $ano,
                'referencia' => $mes === (int) $referencia->month && $ano === (int) $referencia->year,
            ];
            $inicio->addMonth();
        }

        return $colunas;
    }

    /**
     * @param array<int, array{mes: int, ano: int, chave: string}> $colunas
     */
    private function loadFaturas(int $userId, array $colunas): Collection
    {
        $anos = array_unique(array_column($colunas, 'ano'));

        return Fatura::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereIn('ano', $anos)
            ->get(['id', 'cartao_id', 'mes', 'ano', 'valor_total', 'status'])
            ->groupBy(fn ($f) => $this->periodoChave((int) $f->mes, (int) $f->ano))
            ->map(fn ($group) => $group->keyBy('cartao_id'));
    }

    /**
     * @param array<int, array{mes: int, ano: int, chave: string}> $colunas
     */
    private function loadTransacoes(int $userId, array $colunas): Collection
    {
        $anos = array_unique(array_column($colunas, 'ano'));

        return DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->whereIn('f.ano', $anos)
            ->select([
                't.id',
                't.fatura_id',
                't.estabelecimento_id',
                'est.nome as estabelecimento_nome',
                't.valor',
                't.parcelas_total',
                't.parcela_atual',
                't.valor_parcela',
                't.compra_grupo_id',
                't.tipo',
                't.responsavel_id',
                'f.cartao_id',
                'f.mes as fatura_mes',
                'f.ano as fatura_ano',
                'f.status as fatura_status',
            ])
            ->get();
    }

    /**
     * Índice de parcelas já registradas para evitar projeção duplicada.
     *
     * @return array<string, true>
     */
    private function indexParcelasExistentes(Collection $transacoes): array
    {
        $index = [];

        foreach ($transacoes as $t) {
            if ($t->tipo !== Transacao::TIPO_PURCHASE) {
                continue;
            }

            if (empty($t->parcelas_total) || (int) $t->parcelas_total <= 1 || empty($t->parcela_atual)) {
                continue;
            }

            // Sem valor na chave: última parcela costuma diferir por centavos.
            $index[$this->parcelaChave(
                (int) $t->cartao_id,
                (int) $t->estabelecimento_id,
                (int) $t->parcelas_total,
                (int) $t->parcela_atual
            )] = true;
        }

        return $index;
    }

    /**
     * @param array<string, true> $parcelasExistentes
     * @param array<int, string> $chavesColunas
     * @return array<string, array<int, array<int, float>>>
     */
    private function buildProjecoesParcelas(Collection $transacoes, array $parcelasExistentes, array $chavesColunas): array
    {
        $chavesValidas = array_flip($chavesColunas);
        $projecoes = [];

        foreach ($transacoes as $t) {
            if ($t->tipo !== Transacao::TIPO_PURCHASE) {
                continue;
            }

            // Compra manual parcelada já materializa N linhas — não projetar de novo.
            if (!empty($t->compra_grupo_id)) {
                continue;
            }

            $parcelasTotal = (int) ($t->parcelas_total ?? 0);
            $parcelaAtual = (int) ($t->parcela_atual ?? 0);

            if ($parcelasTotal <= 1 || $parcelaAtual < 1) {
                continue;
            }

            $valorParcela = round((float) ($t->valor_parcela ?? $t->valor), 2);
            $cartaoId = (int) $t->cartao_id;
            $responsavelId = (int) $t->responsavel_id;
            $estabelecimentoId = (int) $t->estabelecimento_id;
            $baseMes = (int) $t->fatura_mes;
            $baseAno = (int) $t->fatura_ano;

            for ($parcela = $parcelaAtual + 1; $parcela <= $parcelasTotal; $parcela++) {
                $offset = $parcela - $parcelaAtual;
                [$mes, $ano] = $this->addMeses($baseMes, $baseAno, $offset);
                $chave = $this->periodoChave($mes, $ano);

                if (!isset($chavesValidas[$chave])) {
                    continue;
                }

                $parcelaKey = $this->parcelaChave(
                    $cartaoId,
                    $estabelecimentoId,
                    $parcelasTotal,
                    $parcela
                );

                if (isset($parcelasExistentes[$parcelaKey])) {
                    continue;
                }

                // Marca como existente para não duplicar se houver outra linha-fonte.
                $parcelasExistentes[$parcelaKey] = true;

                $projecoes[$chave][$cartaoId][$responsavelId] = round(
                    ($projecoes[$chave][$cartaoId][$responsavelId] ?? 0) + $valorParcela,
                    2
                );
            }
        }

        return $projecoes;
    }

    /**
     * @param Collection<int, Cartao> $cartoes
     * @param array<int, array{mes: int, ano: int, chave: string}> $colunas
     */
    private function buildMatrizPorCartao(
        Collection $cartoes,
        array $colunas,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $linhas = [];

        foreach ($cartoes as $cartao) {
            $valores = [];
            $totalLinha = 0.0;
            $limiteCredito = $this->limiteCreditoDoCartao($cartao);

            foreach ($colunas as $coluna) {
                $celula = $this->resolveCelulaCartao(
                    (int) $cartao->id,
                    $coluna['chave'],
                    $faturas,
                    $transacoes,
                    $projecoes
                );
                $celula = $this->enrichCelulaComLimite($celula, $limiteCredito);
                $valores[] = $celula;
                $totalLinha += $celula['total'];
            }

            $linhas[] = array_merge(
                $this->metaCartao($cartao, $limiteCredito),
                [
                    'valores' => $valores,
                    'total' => round($totalLinha, 2),
                ]
            );
        }

        return $linhas;
    }

    /**
     * @param Collection<int, Responsavel> $responsaveis
     * @param array<int, array{mes: int, ano: int, chave: string}> $colunas
     */
    private function buildMatrizPorResponsavel(
        Collection $responsaveis,
        array $colunas,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $linhas = [];

        foreach ($responsaveis as $responsavel) {
            $valores = [];
            $totalLinha = 0.0;

            foreach ($colunas as $coluna) {
                $celula = $this->resolveCelulaResponsavel(
                    (int) $responsavel->id,
                    $coluna['chave'],
                    $faturas,
                    $transacoes,
                    $projecoes
                );
                $valores[] = $celula;
                $totalLinha += $celula['total'];
            }

            $linhas[] = [
                'responsavel_id' => (int) $responsavel->id,
                'nome' => $responsavel->nome,
                'tipo' => $responsavel->tipo,
                'valores' => $valores,
                'total' => round($totalLinha, 2),
            ];
        }

        return $linhas;
    }

    /**
     * Cruzamento cartão × responsável: em cada cartão, quanto cada responsável gastou/projetou.
     *
     * @param Collection<int, Cartao> $cartoes
     * @param Collection<int, Responsavel> $responsaveis
     * @param array<int, array{mes: int, ano: int, chave: string}> $colunas
     */
    private function buildMatrizPorCartaoResponsavel(
        Collection $cartoes,
        Collection $responsaveis,
        array $colunas,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $linhas = [];

        foreach ($cartoes as $cartao) {
            $porResponsavel = [];
            $valoresCartao = [];
            $totalCartao = 0.0;
            $limiteCredito = $this->limiteCreditoDoCartao($cartao);

            foreach ($colunas as $index => $coluna) {
                $valoresCartao[$index] = [
                    'realizado' => 0.0,
                    'projetado' => 0.0,
                    'total' => 0.0,
                    'fonte' => 'vazio',
                ];
            }

            foreach ($responsaveis as $responsavel) {
                $valores = [];
                $totalLinha = 0.0;

                foreach ($colunas as $index => $coluna) {
                    $celula = $this->resolveCelulaCartaoResponsavel(
                        (int) $cartao->id,
                        (int) $responsavel->id,
                        $coluna['chave'],
                        $faturas,
                        $transacoes,
                        $projecoes
                    );
                    $valores[] = $celula;
                    $totalLinha += $celula['total'];

                    $valoresCartao[$index]['realizado'] = round(
                        $valoresCartao[$index]['realizado'] + $celula['realizado'],
                        2
                    );
                    $valoresCartao[$index]['projetado'] = round(
                        $valoresCartao[$index]['projetado'] + $celula['projetado'],
                        2
                    );
                    $valoresCartao[$index]['total'] = round(
                        $valoresCartao[$index]['realizado'] + $valoresCartao[$index]['projetado'],
                        2
                    );
                    $valoresCartao[$index]['fonte'] = $this->mergeFonte(
                        $valoresCartao[$index]['fonte'],
                        $celula['fonte']
                    );
                }

                $porResponsavel[] = [
                    'responsavel_id' => (int) $responsavel->id,
                    'nome' => $responsavel->nome,
                    'tipo' => $responsavel->tipo,
                    'valores' => $valores,
                    'total' => round($totalLinha, 2),
                ];
                $totalCartao += $totalLinha;
            }

            $valoresCartao = array_map(
                fn (array $celula) => $this->enrichCelulaComLimite($celula, $limiteCredito),
                array_values($valoresCartao)
            );

            $linhas[] = array_merge(
                $this->metaCartao($cartao, $limiteCredito),
                [
                    'valores' => $valoresCartao,
                    'total' => round($totalCartao, 2),
                    'por_responsavel' => $porResponsavel,
                ]
            );
        }

        return $linhas;
    }

    /**
     * @return array{
     *   cartao_id: int,
     *   nome: string,
     *   qtd_bandeiras: int,
     *   limite_credito: float|null,
     *   cor_fundo: mixed,
     *   cor_texto: mixed,
     *   dia_limite_fatura: mixed,
     *   dia_vencimento_fatura: mixed
     * }
     */
    private function metaCartao(Cartao $cartao, ?float $limiteCredito): array
    {
        return [
            'cartao_id' => (int) $cartao->id,
            'nome' => $cartao->nome,
            'qtd_bandeiras' => $cartao->relationLoaded('bandeiras')
                ? $cartao->bandeiras->count()
                : 0,
            'limite_credito' => $limiteCredito,
            'cor_fundo' => $cartao->cor_fundo,
            'cor_texto' => $cartao->cor_texto,
            'dia_limite_fatura' => $cartao->dia_limite_fatura,
            'dia_vencimento_fatura' => $cartao->dia_vencimento_fatura,
        ];
    }

    /**
     * Soma dos limites das bandeiras ativas do grupo (projeção agregada por cartão).
     */
    private function limiteCreditoDoCartao(Cartao $cartao): ?float
    {
        if (!$cartao->relationLoaded('bandeiras')) {
            return null;
        }

        $soma = 0.0;
        $temLimite = false;

        foreach ($cartao->bandeiras as $bandeira) {
            $limite = $this->normalizeLimiteCredito($bandeira->limite_credito);
            if ($limite !== null) {
                $soma += $limite;
                $temLimite = true;
            }
        }

        return $temLimite ? round($soma, 2) : null;
    }

    /**
     * @param array{realizado: float, projetado: float, total: float, fonte: string} $celula
     * @return array{
     *   realizado: float,
     *   projetado: float,
     *   total: float,
     *   fonte: string,
     *   percentual_utilizado: float|null,
     *   disponivel: float|null
     * }
     */
    private function enrichCelulaComLimite(array $celula, ?float $limiteCredito): array
    {
        if ($limiteCredito === null || $limiteCredito <= 0) {
            $celula['percentual_utilizado'] = null;
            $celula['disponivel'] = null;

            return $celula;
        }

        $celula['percentual_utilizado'] = round(($celula['total'] / $limiteCredito) * 100, 1);
        $celula['disponivel'] = round($limiteCredito - $celula['total'], 2);

        return $celula;
    }

    private function normalizeLimiteCredito(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limite = round((float) $value, 2);

        return $limite > 0 ? $limite : null;
    }

    /**
     * @return array{realizado: float, projetado: float, total: float, fonte: string}
     */
    private function resolveCelulaCartaoResponsavel(
        int $cartaoId,
        int $responsavelId,
        string $chave,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $fatura = $faturas->get($chave)?->get($cartaoId);
        $realizado = round(
            $this->sumComprasCartaoResponsavel($transacoes, $cartaoId, $responsavelId, $chave),
            2
        );

        if ($fatura && $fatura->status === 'processada') {
            return [
                'realizado' => $realizado,
                'projetado' => 0.0,
                'total' => $realizado,
                'fonte' => $realizado > 0 ? 'fatura' : 'vazio',
            ];
        }

        $projetado = round(
            $this->sumProjecaoCartaoResponsavel($projecoes, $chave, $cartaoId, $responsavelId),
            2
        );

        return [
            'realizado' => $realizado,
            'projetado' => $projetado,
            'total' => round($realizado + $projetado, 2),
            'fonte' => $this->fonteCelula($realizado, $projetado, (bool) $fatura),
        ];
    }

    private function fonteCelula(float $realizado, float $projetado, bool $temFatura): string
    {
        if ($realizado <= 0 && $projetado <= 0) {
            return 'vazio';
        }

        if ($projetado > 0 && $realizado > 0) {
            return 'misto';
        }

        if ($projetado > 0) {
            return 'projecao';
        }

        return $temFatura ? 'parcial' : 'misto';
    }

    private function mergeFonte(string $atual, string $novo): string
    {
        if ($atual === 'vazio') {
            return $novo;
        }

        if ($novo === 'vazio' || $atual === $novo) {
            return $atual;
        }

        return 'misto';
    }

    /**
     * @return array{realizado: float, projetado: float, total: float, fonte: string}
     */
    private function resolveCelulaCartao(
        int $cartaoId,
        string $chave,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $fatura = $faturas->get($chave)?->get($cartaoId);

        if ($fatura && $fatura->status === 'processada') {
            $realizado = round((float) $fatura->valor_total, 2);

            return [
                'realizado' => $realizado,
                'projetado' => 0.0,
                'total' => $realizado,
                'fonte' => 'fatura',
            ];
        }

        $realizado = round($this->sumTransacoesCartao($transacoes, $cartaoId, $chave), 2);
        $projetado = round($this->sumProjecaoCartao($projecoes, $chave, $cartaoId), 2);

        return [
            'realizado' => $realizado,
            'projetado' => $projetado,
            'total' => round($realizado + $projetado, 2),
            'fonte' => $fatura ? 'parcial' : ($projetado > 0 ? 'projecao' : 'vazio'),
        ];
    }

    /**
     * @return array{realizado: float, projetado: float, total: float, fonte: string}
     */
    private function resolveCelulaResponsavel(
        int $responsavelId,
        string $chave,
        Collection $faturas,
        Collection $transacoes,
        array $projecoes
    ): array {
        $faturasProcessadas = $faturas->get($chave)?->filter(fn ($f) => $f->status === 'processada') ?? collect();

        if ($faturasProcessadas->isNotEmpty()) {
            $realizado = round(
                $this->sumComprasResponsavel($transacoes, $responsavelId, $chave),
                2
            );

            return [
                'realizado' => $realizado,
                'projetado' => 0.0,
                'total' => $realizado,
                'fonte' => 'fatura',
            ];
        }

        $realizado = round($this->sumComprasResponsavel($transacoes, $responsavelId, $chave), 2);
        $projetado = round($this->sumProjecaoResponsavel($projecoes, $chave, $responsavelId), 2);

        return [
            'realizado' => $realizado,
            'projetado' => $projetado,
            'total' => round($realizado + $projetado, 2),
            'fonte' => $projetado > 0 || $realizado > 0 ? 'misto' : 'vazio',
        ];
    }

    private function sumTransacoesCartao(Collection $transacoes, int $cartaoId, string $chave): float
    {
        $total = 0.0;

        foreach ($transacoes as $t) {
            if ((int) $t->cartao_id !== $cartaoId) {
                continue;
            }

            if ($this->periodoChave((int) $t->fatura_mes, (int) $t->fatura_ano) !== $chave) {
                continue;
            }

            $total += $this->valorLiquidoTransacao($t);
        }

        return $total;
    }

    private function sumComprasResponsavel(Collection $transacoes, int $responsavelId, string $chave): float
    {
        $total = 0.0;

        foreach ($transacoes as $t) {
            if ((int) $t->responsavel_id !== $responsavelId) {
                continue;
            }

            if ($this->periodoChave((int) $t->fatura_mes, (int) $t->fatura_ano) !== $chave) {
                continue;
            }

            if ($t->tipo !== Transacao::TIPO_PURCHASE) {
                continue;
            }

            $total += (float) $t->valor;
        }

        return $total;
    }

    private function sumComprasCartaoResponsavel(
        Collection $transacoes,
        int $cartaoId,
        int $responsavelId,
        string $chave
    ): float {
        $total = 0.0;

        foreach ($transacoes as $t) {
            if ((int) $t->cartao_id !== $cartaoId) {
                continue;
            }

            if ((int) $t->responsavel_id !== $responsavelId) {
                continue;
            }

            if ($this->periodoChave((int) $t->fatura_mes, (int) $t->fatura_ano) !== $chave) {
                continue;
            }

            if ($t->tipo !== Transacao::TIPO_PURCHASE) {
                continue;
            }

            $total += (float) $t->valor;
        }

        return $total;
    }

    private function sumProjecaoCartao(array $projecoes, string $chave, int $cartaoId): float
    {
        if (empty($projecoes[$chave][$cartaoId])) {
            return 0.0;
        }

        return array_sum($projecoes[$chave][$cartaoId]);
    }

    private function sumProjecaoResponsavel(array $projecoes, string $chave, int $responsavelId): float
    {
        if (empty($projecoes[$chave])) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($projecoes[$chave] as $porResponsavel) {
            $total += (float) ($porResponsavel[$responsavelId] ?? 0);
        }

        return $total;
    }

    private function sumProjecaoCartaoResponsavel(
        array $projecoes,
        string $chave,
        int $cartaoId,
        int $responsavelId
    ): float {
        return (float) ($projecoes[$chave][$cartaoId][$responsavelId] ?? 0);
    }

    private function valorLiquidoTransacao(object $t): float
    {
        $valor = (float) $t->valor;

        return match ($t->tipo) {
            Transacao::TIPO_PURCHASE, Transacao::TIPO_ADVANCE => $valor,
            Transacao::TIPO_REFUND => -$valor,
            default => 0.0,
        };
    }

    private function buildTotaisPorColuna(array $colunas, array $porCartao, array $porResponsavel): array
    {
        $totais = [];

        foreach ($colunas as $index => $coluna) {
            $realizadoCartao = 0.0;
            $projetadoCartao = 0.0;

            foreach ($porCartao as $linha) {
                $realizadoCartao += (float) ($linha['valores'][$index]['realizado'] ?? 0);
                $projetadoCartao += (float) ($linha['valores'][$index]['projetado'] ?? 0);
            }

            $realizadoResp = 0.0;
            $projetadoResp = 0.0;

            foreach ($porResponsavel as $linha) {
                $realizadoResp += (float) ($linha['valores'][$index]['realizado'] ?? 0);
                $projetadoResp += (float) ($linha['valores'][$index]['projetado'] ?? 0);
            }

            $totais[] = [
                'mes' => $coluna['mes'],
                'ano' => $coluna['ano'],
                'chave' => $coluna['chave'],
                'cartoes' => [
                    'realizado' => round($realizadoCartao, 2),
                    'projetado' => round($projetadoCartao, 2),
                    'total' => round($realizadoCartao + $projetadoCartao, 2),
                ],
                'responsaveis' => [
                    'realizado' => round($realizadoResp, 2),
                    'projetado' => round($projetadoResp, 2),
                    'total' => round($realizadoResp + $projetadoResp, 2),
                ],
            ];
        }

        return $totais;
    }

    private function periodoChave(int $mes, int $ano): string
    {
        return sprintf('%04d-%02d', $ano, $mes);
    }

    private function parcelaChave(
        int $cartaoId,
        int $estabelecimentoId,
        int $parcelasTotal,
        int $parcelaAtual
    ): string {
        return implode(':', [
            $cartaoId,
            $estabelecimentoId,
            $parcelasTotal,
            $parcelaAtual,
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function addMeses(int $mes, int $ano, int $offset): array
    {
        $date = Carbon::create($ano, $mes, 1)->addMonths($offset);

        return [(int) $date->month, (int) $date->year];
    }
}
