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

            $responsavelEuId = $this->findResponsavelEuId($responsaveis);

            $porCartao = $this->buildMatrizPorCartao($cartoes, $colunas, $faturas, $transacoes, $projecoes);
            $porResponsavel = $this->buildMatrizPorResponsavel(
                $responsaveis,
                $colunas,
                $faturas,
                $transacoes,
                $projecoes,
                $responsavelEuId
            );
            $porCartaoResponsavel = $this->buildMatrizPorCartaoResponsavel(
                $cartoes,
                $responsaveis,
                $colunas,
                $faturas,
                $transacoes,
                $projecoes,
                $responsavelEuId
            );

            $porCartao = $this->enrichPorCartaoComConsumoEuOutros($porCartao, $porCartaoResponsavel, $colunas);

            return (object) [
                'data' => [
                    'referencia' => [
                        'mes' => (int) $referencia->month,
                        'ano' => (int) $referencia->year,
                    ],
                    'colunas' => $colunas,
                    'responsavel_eu_id' => $responsavelEuId,
                    'por_cartao' => $porCartao,
                    'por_responsavel' => $porResponsavel,
                    'por_cartao_responsavel' => $porCartaoResponsavel,
                    'resumo_eu_outros' => $this->buildResumoEuOutrosFromLinhas(
                        $porResponsavel,
                        $responsavelEuId,
                        count($colunas)
                    ),
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

            $linha = array_merge(
                $this->metaCartao($cartao, $limiteCredito),
                [
                    'valores' => $valores,
                    'total' => round($totalLinha, 2),
                ]
            );
            $linhas[] = $this->attachUsoLimite($linha, $colunas);
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
        array $projecoes,
        ?int $responsavelEuId
    ): array {
        $linhas = [];
        $totaisPorColuna = array_fill(0, count($colunas), 0.0);

        foreach ($responsaveis as $responsavel) {
            $valores = [];
            $totalLinha = 0.0;

            foreach ($colunas as $index => $coluna) {
                $celula = $this->resolveCelulaResponsavel(
                    (int) $responsavel->id,
                    $coluna['chave'],
                    $faturas,
                    $transacoes,
                    $projecoes
                );
                $valores[] = $celula;
                $totalLinha += $celula['total'];
                $totaisPorColuna[$index] = round($totaisPorColuna[$index] + $celula['total'], 2);
            }

            $linhas[] = [
                'responsavel_id' => (int) $responsavel->id,
                'nome' => $responsavel->nome,
                'tipo' => $responsavel->tipo,
                'eh_eu' => $responsavelEuId !== null && (int) $responsavel->id === $responsavelEuId,
                'valores' => $valores,
                'total' => round($totalLinha, 2),
            ];
        }

        foreach ($linhas as &$linha) {
            foreach ($linha['valores'] as $index => &$celula) {
                $celula['percentual_participacao'] = $this->percentualDe(
                    (float) $celula['total'],
                    (float) $totaisPorColuna[$index]
                );
            }
            unset($celula);
        }
        unset($linha);

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
        array $projecoes,
        ?int $responsavelEuId
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
                    'eh_eu' => $responsavelEuId !== null && (int) $responsavel->id === $responsavelEuId,
                    'valores' => $valores,
                    'total' => round($totalLinha, 2),
                ];
                $totalCartao += $totalLinha;
            }

            foreach ($porResponsavel as &$linhaResp) {
                foreach ($linhaResp['valores'] as $index => &$celula) {
                    $celula['percentual_participacao'] = $this->percentualDe(
                        (float) $celula['total'],
                        (float) $valoresCartao[$index]['total']
                    );
                }
                unset($celula);
            }
            unset($linhaResp);

            $valoresCartao = array_map(
                fn (array $celula) => $this->enrichCelulaComLimite($celula, $limiteCredito),
                array_values($valoresCartao)
            );

            $resumoEuOutros = $this->buildResumoEuOutrosFromLinhas(
                $porResponsavel,
                $responsavelEuId,
                count($colunas),
                $limiteCredito
            );

            $valoresCartao = $this->attachConsumoEuOutrosNasCelulas($valoresCartao, $resumoEuOutros, $limiteCredito);

            $linha = array_merge(
                $this->metaCartao($cartao, $limiteCredito),
                [
                    'valores' => $valoresCartao,
                    'total' => round($totalCartao, 2),
                    'por_responsavel' => $porResponsavel,
                    'resumo_eu_outros' => $resumoEuOutros,
                ]
            );
            $linhas[] = $this->attachUsoLimite($linha, $colunas);
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
     *   em_uso: float,
     *   livre: float|null,
     *   percentual_utilizado: float|null,
     *   percentual_livre: float|null,
     *   disponivel: float|null
     * }
     */
    private function enrichCelulaComLimite(array $celula, ?float $limiteCredito): array
    {
        $emUso = round((float) $celula['total'], 2);
        $celula['em_uso'] = $emUso;

        if ($limiteCredito === null || $limiteCredito <= 0) {
            $celula['percentual_utilizado'] = null;
            $celula['percentual_livre'] = null;
            $celula['livre'] = null;
            $celula['disponivel'] = null;

            return $celula;
        }

        $livre = round($limiteCredito - $emUso, 2);
        $celula['percentual_utilizado'] = round(($emUso / $limiteCredito) * 100, 1);
        $celula['percentual_livre'] = round(($livre / $limiteCredito) * 100, 1);
        $celula['livre'] = $livre;
        $celula['disponivel'] = $livre;

        return $celula;
    }

    /**
     * @param Collection<int, Responsavel> $responsaveis
     */
    private function findResponsavelEuId(Collection $responsaveis): ?int
    {
        $eu = $responsaveis->first(
            fn (Responsavel $r) => mb_strtolower(trim((string) $r->nome)) === 'eu'
        );

        return $eu ? (int) $eu->id : null;
    }

    /**
     * Snapshot do mês de referência: limite, em uso e livre (valor + %).
     *
     * @param array<int, array{referencia: bool}> $colunas
     */
    private function attachUsoLimite(array $linha, array $colunas): array
    {
        $refIndex = null;

        foreach ($colunas as $index => $coluna) {
            if (!empty($coluna['referencia'])) {
                $refIndex = $index;
                break;
            }
        }

        $celula = $refIndex !== null ? ($linha['valores'][$refIndex] ?? null) : null;
        $limite = $linha['limite_credito'] ?? null;

        $linha['uso_limite'] = [
            'limite' => $limite,
            'em_uso' => $celula['em_uso'] ?? ($celula['total'] ?? null),
            'percentual_em_uso' => $celula['percentual_utilizado'] ?? null,
            'livre' => $celula['livre'] ?? ($celula['disponivel'] ?? null),
            'percentual_livre' => $celula['percentual_livre'] ?? null,
            'meu' => $celula['meu'] ?? null,
            'outros' => $celula['outros'] ?? null,
        ];

        return $linha;
    }

    /**
     * @param array<int, array<string, mixed>> $linhasResponsavel
     * @return array<int, array{
     *   meu: array{realizado: float, projetado: float, total: float, percentual: float|null, percentual_do_limite: float|null},
     *   outros: array{realizado: float, projetado: float, total: float, percentual: float|null, percentual_do_limite: float|null},
     *   total: float
     * }>
     */
    private function buildResumoEuOutrosFromLinhas(
        array $linhasResponsavel,
        ?int $responsavelEuId,
        int $colunasCount,
        ?float $limiteCredito = null
    ): array {
        $resumo = [];

        for ($index = 0; $index < $colunasCount; $index++) {
            $meu = ['realizado' => 0.0, 'projetado' => 0.0, 'total' => 0.0];
            $outros = ['realizado' => 0.0, 'projetado' => 0.0, 'total' => 0.0];

            foreach ($linhasResponsavel as $linha) {
                $celula = $linha['valores'][$index] ?? [
                    'realizado' => 0.0,
                    'projetado' => 0.0,
                    'total' => 0.0,
                ];
                $ehEu = $responsavelEuId !== null && (int) $linha['responsavel_id'] === $responsavelEuId;
                $bloco = $ehEu ? $meu : $outros;

                $bloco['realizado'] = round($bloco['realizado'] + (float) ($celula['realizado'] ?? 0), 2);
                $bloco['projetado'] = round($bloco['projetado'] + (float) ($celula['projetado'] ?? 0), 2);
                $bloco['total'] = round($bloco['realizado'] + $bloco['projetado'], 2);

                if ($ehEu) {
                    $meu = $bloco;
                } else {
                    $outros = $bloco;
                }
            }

            $total = round($meu['total'] + $outros['total'], 2);
            $meu['percentual'] = $this->percentualDe($meu['total'], $total);
            $outros['percentual'] = $this->percentualDe($outros['total'], $total);
            $meu['percentual_do_limite'] = $this->percentualDe($meu['total'], $limiteCredito);
            $outros['percentual_do_limite'] = $this->percentualDe($outros['total'], $limiteCredito);

            $resumo[] = [
                'meu' => $meu,
                'outros' => $outros,
                'total' => $total,
            ];
        }

        return $resumo;
    }

    /**
     * Propaga meu/outros (valores + % do uso e do limite) para as células do cartão.
     *
     * @param array<int, array<string, mixed>> $valores
     * @param array<int, array{meu: array, outros: array, total: float}> $resumoEuOutros
     * @return array<int, array<string, mixed>>
     */
    private function attachConsumoEuOutrosNasCelulas(
        array $valores,
        array $resumoEuOutros,
        ?float $limiteCredito
    ): array {
        foreach ($valores as $index => &$celula) {
            $resumo = $resumoEuOutros[$index] ?? null;
            if ($resumo === null) {
                $celula['meu'] = null;
                $celula['outros'] = null;
                continue;
            }

            $celula['meu'] = $this->formatBlocoConsumo($resumo['meu'], $limiteCredito);
            $celula['outros'] = $this->formatBlocoConsumo($resumo['outros'], $limiteCredito);
        }
        unset($celula);

        return $valores;
    }

    /**
     * Copia meu/outros das células de por_cartao_responsavel para por_cartao (mesmo cartão/mês).
     * O total da célula em por_cartao pode diferir (fatura processada usa valor_total);
     * o split Eu/Outros segue as compras por responsável.
     *
     * @param array<int, array<string, mixed>> $porCartao
     * @param array<int, array<string, mixed>> $porCartaoResponsavel
     * @param array<int, array{referencia: bool}> $colunas
     * @return array<int, array<string, mixed>>
     */
    private function enrichPorCartaoComConsumoEuOutros(
        array $porCartao,
        array $porCartaoResponsavel,
        array $colunas
    ): array {
        $porCartaoId = [];
        foreach ($porCartaoResponsavel as $linha) {
            $porCartaoId[(int) $linha['cartao_id']] = $linha;
        }

        foreach ($porCartao as &$linha) {
            $cruzamento = $porCartaoId[(int) $linha['cartao_id']] ?? null;
            if ($cruzamento === null) {
                continue;
            }

            $limite = $linha['limite_credito'] ?? null;
            $resumo = $cruzamento['resumo_eu_outros'] ?? [];
            $linha['valores'] = $this->attachConsumoEuOutrosNasCelulas($linha['valores'], $resumo, $limite);
            $linha['resumo_eu_outros'] = $resumo;
            $linha = $this->attachUsoLimite($linha, $colunas);
        }
        unset($linha);

        return $porCartao;
    }

    /**
     * @param array{realizado?: float, projetado?: float, total?: float, percentual?: float|null, percentual_do_limite?: float|null} $bloco
     * @return array{realizado: float, projetado: float, total: float, percentual: float|null, percentual_do_limite: float|null}
     */
    private function formatBlocoConsumo(array $bloco, ?float $limiteCredito): array
    {
        $total = round((float) ($bloco['total'] ?? 0), 2);

        return [
            'realizado' => round((float) ($bloco['realizado'] ?? 0), 2),
            'projetado' => round((float) ($bloco['projetado'] ?? 0), 2),
            'total' => $total,
            'percentual' => $bloco['percentual'] ?? null,
            'percentual_do_limite' => array_key_exists('percentual_do_limite', $bloco)
                ? $bloco['percentual_do_limite']
                : $this->percentualDe($total, $limiteCredito),
        ];
    }

    private function percentualDe(float $parte, ?float $todo): ?float
    {
        if ($todo === null || $todo <= 0) {
            return null;
        }

        return round(($parte / $todo) * 100, 1);
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
            Transacao::TIPO_PURCHASE, Transacao::TIPO_ADVANCE, Transacao::TIPO_FEE, Transacao::TIPO_CARRYOVER => $valor,
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
