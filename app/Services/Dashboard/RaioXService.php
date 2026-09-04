<?php

namespace App\Services\Dashboard;

use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Models\User;
use App\Services\Fatura\FaturaService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RaioXService
{
    public const NIVEL_POSITIVO = 'positivo';
    public const NIVEL_ATENCAO = 'atencao';
    public const NIVEL_ALERTA = 'alerta';
    public const NIVEL_INCOMPLETO = 'incompleto';

    public const TIPO_ATRASO = 'atraso';
    public const TIPO_PARCELADAS = 'parceladas';
    public const TIPO_ASSINATURAS = 'assinaturas';
    public const TIPO_CRESCIMENTO = 'crescimento';
    public const TIPO_CONCENTRACAO = 'concentracao';
    public const TIPO_OK = 'ok';

    public const DIAS_ATENCAO_VENCIMENTO = 5;
    public const CRESCIMENTO_ALERTA_PCT = 20.0;
    public const COMPROMETIMENTO_ATENCAO_PCT = 30.0;
    public const COMPROMETIMENTO_ALERTA_PCT = 50.0;
    public const PARCELADAS_PCT_DO_MES = 20.0;
    public const PARCELADAS_MIN_VALOR = 200.0;
    public const PARCELADAS_MIN_COMPRAS = 2;
    public const ASSINATURAS_PCT_DO_MES = 15.0;
    public const ASSINATURAS_MIN_VALOR = 80.0;
    public const CONCENTRACAO_PCT = 40.0;
    public const CONCENTRACAO_MIN_VALOR = 80.0;
    public const PROJECAO_QUEDA_PP = 10.0;

    private const MESES_EXTENSO = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    private const MESES_LABEL = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];

    private RankingParceladasService $ranking;

    private ProjecaoFaturasService $projecao;

    private FaturaService $faturaService;

    public function __construct(
        ?RankingParceladasService $ranking = null,
        ?ProjecaoFaturasService $projecao = null,
        ?FaturaService $faturaService = null
    ) {
        $this->ranking = $ranking ?? new RankingParceladasService();
        $this->projecao = $projecao ?? new ProjecaoFaturasService();
        $this->faturaService = $faturaService ?? new FaturaService();
    }

    public function handleRaioX(object $atributes): object
    {
        try {
            $userId = (int) Auth::id();
            if ($userId < 1) {
                throw new Exception('Não autenticado', 401);
            }

            /** @var User $user */
            $user = Auth::user();
            $mes = (int) ($atributes->mes ?? now()->month);
            $ano = (int) ($atributes->ano ?? now()->year);
            $responsavelId = !empty($atributes->responsavel_id)
                ? (int) $atributes->responsavel_id
                : null;

            if ($mes < 1 || $mes > 12) {
                throw new Exception('Mês inválido', 422);
            }
            if ($ano < 2000 || $ano > 2100) {
                throw new Exception('Ano inválido', 422);
            }

            $hoje = now()->startOfDay();
            $renda = $user->renda_mensal !== null ? round((float) $user->renda_mensal, 2) : null;
            if ($renda !== null && $renda <= 0) {
                $renda = null;
            }

            $valorMes = $this->valorCompetencia($userId, $mes, $ano, $responsavelId);
            $anterior = Carbon::create($ano, $mes, 1)->subMonthNoOverflow();
            $valorAnterior = $this->valorCompetencia(
                $userId,
                (int) $anterior->month,
                (int) $anterior->year,
                $responsavelId
            );
            $temFaturaAnterior = $this->temFaturaCompetencia(
                $userId,
                (int) $anterior->month,
                (int) $anterior->year
            );

            $pagamentos = $this->classificarPagamentos(
                $this->loadFaturasParaPagamento($userId, $mes, $ano),
                $hoje
            );

            $rankingQuery = (object) [
                'mes' => $mes,
                'ano' => $ano,
                'apenas_abertas' => 1,
            ];
            if ($responsavelId) {
                $rankingQuery->responsavel_id = $responsavelId;
            }
            $ranking = $this->ranking->handleRanking($rankingQuery);
            $rankingData = is_object($ranking) ? (array) ($ranking->data ?? []) : [];
            $totaisParceladas = $rankingData['totais'] ?? [
                'compras' => 0,
                'valor_aberto' => 0.0,
            ];

            $valorAssinaturas = $this->valorAssinaturasMes($userId, $mes, $ano, $responsavelId);
            $concentracao = $this->maiorConcentracaoMes($userId, $mes, $ano, $responsavelId);

            $sinalPagamentos = $this->montarSinalPagamentos($pagamentos, $mes, $ano);
            $sinalCrescimento = $this->montarSinalCrescimento(
                $valorMes,
                $valorAnterior,
                $temFaturaAnterior,
                $mes,
                $ano
            );
            $sinalComprometimento = $this->montarSinalComprometimento($valorMes, $renda, $mes, $ano);

            $tipo = $this->escolherTipoDiagnostico(
                $sinalPagamentos['nivel'],
                (int) ($totaisParceladas['compras'] ?? 0),
                (float) ($totaisParceladas['valor_aberto'] ?? 0),
                $valorMes,
                $valorAssinaturas,
                $sinalCrescimento['nivel'],
                $concentracao
            );

            $serieProjecao = [];
            $horizonte = null;
            $pctAtual = $renda ? $this->percentual($valorMes, $renda) : null;

            if ($renda && in_array($tipo, [self::TIPO_PARCELADAS, self::TIPO_OK, self::TIPO_CRESCIMENTO], true)) {
                $serieProjecao = $this->serieProjecaoTotais($mes, $ano, $responsavelId);
                $horizonte = $this->encontrarHorizonteProjecao($serieProjecao, (float) $pctAtual, $renda);
            }

            $temDadosMes = $valorMes > 0.005
                || (int) ($totaisParceladas['compras'] ?? 0) > 0
                || $pagamentos['nivel'] !== self::NIVEL_POSITIVO;

            $diagnostico = $temDadosMes
                ? $this->montarDiagnostico(
                    $tipo,
                    $pagamentos,
                    $totaisParceladas,
                    $valorAssinaturas,
                    $concentracao,
                    $sinalCrescimento,
                    $horizonte,
                    $renda,
                    $mes,
                    $ano
                )
                : null;

            return (object) [
                'data' => [
                    'referencia' => [
                        'mes' => $mes,
                        'ano' => $ano,
                        'label' => (self::MESES_LABEL[$mes] ?? (string) $mes) . ' ' . $ano,
                        'label_curto' => 'Seu mês',
                    ],
                    'renda' => [
                        'informada' => $renda !== null,
                        'valor' => $renda,
                        'moeda' => 'BRL',
                    ],
                    'sinais' => [
                        $sinalPagamentos,
                        $sinalCrescimento,
                        $sinalComprometimento,
                    ],
                    'diagnostico' => $diagnostico,
                    'acoes' => $this->montarAcoes($tipo, $mes, $ano),
                ],
                'status' => true,
                'message' => 'Raio-X carregado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param array{
     *   nivel: string,
     *   atrasadas: array<int, array>,
     *   a_vencer: array<int, array>,
     *   aguardando_confirmacao: array<int, array>,
     *   em_aberto: int,
     *   valor_restante: float,
     *   valor_atrasado: float,
     *   valor_aguardando: float
     * } $pagamentos
     */
    public function montarSinalPagamentos(array $pagamentos, int $mes, int $ano): array
    {
        $nivel = $pagamentos['nivel'];
        $qtdAtraso = count($pagamentos['atrasadas']);
        $qtdVencer = count($pagamentos['a_vencer']);
        $qtdAguardando = count($pagamentos['aguardando_confirmacao'] ?? []);
        $valorAtrasado = (float) ($pagamentos['valor_atrasado'] ?? $pagamentos['valor_restante'] ?? 0);
        $valorRestante = (float) $pagamentos['valor_restante'];

        if ($nivel === self::NIVEL_ALERTA) {
            $frase = $qtdAtraso === 1
                ? 'Há fatura em atraso'
                : 'Há ' . $qtdAtraso . ' faturas em atraso';
            $contexto = $valorAtrasado > 0
                ? $this->formatBrl($valorAtrasado) . ' em aberto além do vencimento.'
                : 'Há fatura vencida ainda não quitada.';
        } elseif ($qtdVencer > 0) {
            $frase = $qtdVencer === 1 ? 'Fatura vence em breve' : 'Faturas vencem em breve';
            $contexto = $qtdVencer === 1
                ? 'Há uma fatura a vencer nos próximos ' . self::DIAS_ATENCAO_VENCIMENTO . ' dias.'
                : 'Há ' . $qtdVencer . ' faturas a vencer nos próximos ' . self::DIAS_ATENCAO_VENCIMENTO . ' dias.';
        } elseif ($qtdAguardando > 0) {
            $frase = $qtdAguardando === 1
                ? 'Aguardando confirmação de pagamento'
                : 'Aguardando confirmação de pagamentos';
            $contexto = $this->contextoAguardandoConfirmacao($pagamentos['aguardando_confirmacao']);
        } else {
            $frase = 'Pagamentos em dia';
            $contexto = 'Nenhuma fatura vencida em aberto neste mês.';
        }

        return $this->sinal(
            'pagamentos',
            $nivel,
            $frase,
            $contexto,
            [
                'atrasadas' => $qtdAtraso,
                'a_vencer' => $qtdVencer,
                'aguardando_confirmacao' => $qtdAguardando,
                'em_aberto' => (int) $pagamentos['em_aberto'],
                'valor_restante' => round($valorRestante, 2),
                'valor_atrasado' => round($valorAtrasado, 2),
                'valor_aguardando' => round((float) ($pagamentos['valor_aguardando'] ?? 0), 2),
            ],
            $this->atalho('faturas', $mes, $ano)
        );
    }

    public function montarSinalCrescimento(
        float $valorAtual,
        float $valorAnterior,
        bool $temFaturaAnterior,
        int $mes,
        int $ano
    ): array {
        $variacao = $this->variacaoPercentual($valorAtual, $valorAnterior, $temFaturaAnterior);
        $nivel = $this->nivelCrescimento($variacao);
        $frase = $this->fraseCrescimento($variacao, $valorAtual);
        $contexto = $variacao === null
            ? ($valorAtual > 0
                ? 'Ainda não há fatura no mês anterior para comparar.'
                : 'Sem faturas neste recorte.')
            : $this->formatBrl($valorAtual) . ' neste mês vs ' . $this->formatBrl($valorAnterior) . ' no mês anterior.';

        return $this->sinal(
            'crescimento',
            $nivel,
            $frase,
            $contexto,
            [
                'variacao_percentual' => $variacao,
                'valor_atual' => round($valorAtual, 2),
                'valor_anterior' => round($valorAnterior, 2),
            ],
            $this->atalho('faturas', $mes, $ano)
        );
    }

    public function montarSinalComprometimento(float $valorMes, ?float $renda, int $mes, int $ano): array
    {
        if ($renda === null) {
            return $this->sinal(
                'comprometimento',
                self::NIVEL_INCOMPLETO,
                'Informe sua renda para ver o comprometimento',
                'Com a renda mensal, o Raio-X diz quanto da sua entrada já está nas faturas.',
                [
                    'percentual' => null,
                    'valor_comprometido' => round($valorMes, 2),
                    'renda' => null,
                ],
                ['rota' => 'perfil']
            );
        }

        $pct = $this->percentual($valorMes, $renda);
        $nivel = $this->nivelComprometimento($pct);
        $frase = $this->fraseComprometimento($pct);
        $contexto = $this->formatBrl($valorMes) . ' de faturas sobre ' . $this->formatBrl($renda) . ' de renda mensal.';

        return $this->sinal(
            'comprometimento',
            $nivel,
            $frase,
            $contexto,
            [
                'percentual' => $pct,
                'valor_comprometido' => round($valorMes, 2),
                'renda' => $renda,
            ],
            $this->atalho('projecao', $mes, $ano)
        );
    }

    /**
     * @param array<string, mixed> $pagamentos
     * @param array<string, mixed> $totaisParceladas
     * @param array{nome: string, percentual: float, valor: float, tipo: string, id: int|null}|null $concentracao
     * @param array<string, mixed> $sinalCrescimento
     * @param array{percentual: float, mes: int, ano: int, label: string}|null $horizonte
     */
    public function montarDiagnostico(
        string $tipo,
        array $pagamentos,
        array $totaisParceladas,
        float $valorAssinaturas,
        ?array $concentracao,
        array $sinalCrescimento,
        ?array $horizonte,
        ?float $renda,
        int $mes,
        int $ano
    ): array {
        $compras = (int) ($totaisParceladas['compras'] ?? 0);
        $valorAberto = (float) ($totaisParceladas['valor_aberto'] ?? 0);

        return match ($tipo) {
            self::TIPO_ATRASO => $this->diagnosticoAtraso($pagamentos, $mes, $ano),
            self::TIPO_PARCELADAS => $this->diagnosticoParceladas(
                $compras,
                $valorAberto,
                $horizonte,
                $renda,
                $mes,
                $ano
            ),
            self::TIPO_ASSINATURAS => $this->diagnosticoAssinaturas($valorAssinaturas, $mes, $ano),
            self::TIPO_CRESCIMENTO => $this->diagnosticoCrescimento($sinalCrescimento, $mes, $ano),
            self::TIPO_CONCENTRACAO => $this->diagnosticoConcentracao($concentracao, $mes, $ano),
            default => $this->diagnosticoOk($mes, $ano),
        };
    }

    public function escolherTipoDiagnostico(
        string $nivelPagamentos,
        int $comprasParceladas,
        float $valorAbertoParceladas,
        float $valorMes,
        float $valorAssinaturas,
        string $nivelCrescimento,
        ?array $concentracao
    ): string {
        if ($nivelPagamentos === self::NIVEL_ALERTA) {
            return self::TIPO_ATRASO;
        }

        if ($this->parceladasSaoRelevantes($comprasParceladas, $valorAbertoParceladas, $valorMes)) {
            return self::TIPO_PARCELADAS;
        }

        if ($this->assinaturasSaoRelevantes($valorAssinaturas, $valorMes)) {
            return self::TIPO_ASSINATURAS;
        }

        if ($nivelCrescimento === self::NIVEL_ALERTA) {
            return self::TIPO_CRESCIMENTO;
        }

        if ($concentracao !== null && $this->concentracaoERelevante($concentracao)) {
            return self::TIPO_CONCENTRACAO;
        }

        return self::TIPO_OK;
    }

    public function parceladasSaoRelevantes(int $compras, float $valorAberto, float $valorMes): bool
    {
        if ($valorAberto <= 0.005) {
            return false;
        }

        if ($compras >= self::PARCELADAS_MIN_COMPRAS) {
            return true;
        }

        if ($valorAberto >= self::PARCELADAS_MIN_VALOR) {
            return true;
        }

        return $valorMes > 0 && $this->percentual($valorAberto, $valorMes) >= self::PARCELADAS_PCT_DO_MES;
    }

    public function assinaturasSaoRelevantes(float $valorAssinaturas, float $valorMes): bool
    {
        if ($valorAssinaturas < self::ASSINATURAS_MIN_VALOR) {
            return false;
        }

        if ($valorMes <= 0) {
            return $valorAssinaturas >= self::ASSINATURAS_MIN_VALOR;
        }

        return $this->percentual($valorAssinaturas, $valorMes) >= self::ASSINATURAS_PCT_DO_MES;
    }

    /**
     * @param array{percentual: float, valor: float} $concentracao
     */
    public function concentracaoERelevante(array $concentracao): bool
    {
        return (float) $concentracao['valor'] >= self::CONCENTRACAO_MIN_VALOR
            && (float) $concentracao['percentual'] >= self::CONCENTRACAO_PCT;
    }

    /**
     * @param array<int, array{
     *   pago: bool,
     *   data_vencimento: ?string,
     *   valor_restante: float,
     *   valor_total: float,
     *   mes?: int,
     *   ano?: int,
     *   proxima_tem_anexo?: bool
     * }> $faturas
     * @return array{
     *   nivel: string,
     *   atrasadas: array<int, array>,
     *   a_vencer: array<int, array>,
     *   aguardando_confirmacao: array<int, array>,
     *   em_aberto: int,
     *   valor_restante: float,
     *   valor_atrasado: float,
     *   valor_aguardando: float
     * }
     */
    public function classificarPagamentos(array $faturas, Carbon $hoje): array
    {
        $hoje = $hoje->copy()->startOfDay();
        $limiteAtencao = $hoje->copy()->addDays(self::DIAS_ATENCAO_VENCIMENTO);
        $atrasadas = [];
        $aVencer = [];
        $aguardando = [];
        $emAberto = 0;
        $valorRestante = 0.0;
        $valorAtrasado = 0.0;
        $valorAguardando = 0.0;

        foreach ($faturas as $fatura) {
            $total = (float) ($fatura['valor_total'] ?? 0);
            if ($total <= 0.005) {
                continue;
            }
            if (!empty($fatura['pago'])) {
                continue;
            }

            $restante = (float) ($fatura['valor_restante'] ?? $total);
            $emAberto++;
            $valorRestante += $restante;

            $vencimentoRaw = $fatura['data_vencimento'] ?? null;
            if (!$vencimentoRaw) {
                continue;
            }

            $vencimento = Carbon::parse($vencimentoRaw)->startOfDay();
            if ($vencimento->lt($hoje)) {
                if ($this->atrasoConfirmado($fatura, $hoje)) {
                    $atrasadas[] = $fatura;
                    $valorAtrasado += $restante;
                } else {
                    $aguardando[] = $fatura;
                    $valorAguardando += $restante;
                }
            } elseif ($vencimento->lte($limiteAtencao)) {
                $aVencer[] = $fatura;
            }
        }

        $nivel = self::NIVEL_POSITIVO;
        if ($atrasadas !== []) {
            $nivel = self::NIVEL_ALERTA;
        } elseif ($aVencer !== [] || $aguardando !== []) {
            $nivel = self::NIVEL_ATENCAO;
        }

        return [
            'nivel' => $nivel,
            'atrasadas' => $atrasadas,
            'a_vencer' => $aVencer,
            'aguardando_confirmacao' => $aguardando,
            'em_aberto' => $emAberto,
            'valor_restante' => round($valorRestante, 2),
            'valor_atrasado' => round($valorAtrasado, 2),
            'valor_aguardando' => round($valorAguardando, 2),
        ];
    }

    /**
     * Atraso só se confirma com o PDF de F+1 sem pagamento, ou com salto de 2 meses
     * (ex.: última fatura anexada em agosto e hoje já é outubro).
     *
     * @param array{mes?: int, ano?: int, proxima_tem_anexo?: bool} $fatura
     */
    public function atrasoConfirmado(array $fatura, Carbon $hoje): bool
    {
        $proximaTemAnexo = array_key_exists('proxima_tem_anexo', $fatura)
            ? (bool) $fatura['proxima_tem_anexo']
            : true;

        if ($proximaTemAnexo) {
            return true;
        }

        $mes = (int) ($fatura['mes'] ?? 0);
        $ano = (int) ($fatura['ano'] ?? 0);
        if ($mes < 1 || $mes > 12 || $ano < 2000) {
            return true;
        }

        $inicioDoGap = Carbon::create($ano, $mes, 1)->addMonthsNoOverflow(2)->startOfMonth();

        return $hoje->copy()->startOfMonth()->gte($inicioDoGap);
    }

    public function variacaoPercentual(float $atual, float $anterior, bool $temBase): ?float
    {
        if (!$temBase || $anterior <= 0) {
            return null;
        }

        return round((($atual - $anterior) / $anterior) * 100, 1);
    }

    public function nivelCrescimento(?float $variacao): string
    {
        if ($variacao === null || $variacao <= 0) {
            return self::NIVEL_POSITIVO;
        }

        if ($variacao > self::CRESCIMENTO_ALERTA_PCT) {
            return self::NIVEL_ALERTA;
        }

        return self::NIVEL_ATENCAO;
    }

    public function fraseCrescimento(?float $variacao, float $valorAtual): string
    {
        if ($variacao === null) {
            return $valorAtual > 0
                ? 'Primeiro mês com fatura neste recorte.'
                : 'Sem faturas neste recorte.';
        }

        $pct = $this->formatPctInteiro(abs($variacao));

        if ($variacao > 0) {
            return 'Faturas cresceram ' . $pct;
        }
        if ($variacao < 0) {
            return 'Faturas caíram ' . $pct;
        }

        return 'Faturas estáveis em relação ao mês anterior';
    }

    public function nivelComprometimento(float $percentual): string
    {
        if ($percentual > self::COMPROMETIMENTO_ALERTA_PCT) {
            return self::NIVEL_ALERTA;
        }
        if ($percentual >= self::COMPROMETIMENTO_ATENCAO_PCT) {
            return self::NIVEL_ATENCAO;
        }

        return self::NIVEL_POSITIVO;
    }

    public function fraseComprometimento(float $percentual): string
    {
        if ($percentual <= 0) {
            return 'Nenhuma fatura comprometendo a renda neste mês';
        }

        return $this->formatPctInteiro($percentual) . ' da sua renda já está comprometida';
    }

    /**
     * @param array<int, array{mes: int, ano: int, label: string, referencia: bool, total: float}> $serie
     * @return array{percentual: float, mes: int, ano: int, label: string}|null
     */
    public function encontrarHorizonteProjecao(array $serie, float $pctAtual, float $renda): ?array
    {
        if ($renda <= 0 || $pctAtual <= 0) {
            return null;
        }

        $passouReferencia = false;
        foreach ($serie as $coluna) {
            if (!empty($coluna['referencia'])) {
                $passouReferencia = true;
                continue;
            }
            if (!$passouReferencia) {
                continue;
            }

            $pct = $this->percentual((float) $coluna['total'], $renda);
            if ($pct >= $pctAtual) {
                continue;
            }

            $queda = $pctAtual - $pct;
            $atravessou = ($pctAtual > self::COMPROMETIMENTO_ALERTA_PCT && $pct <= self::COMPROMETIMENTO_ALERTA_PCT)
                || ($pctAtual > self::COMPROMETIMENTO_ATENCAO_PCT && $pct <= self::COMPROMETIMENTO_ATENCAO_PCT);

            if ($queda >= self::PROJECAO_QUEDA_PP || $atravessou) {
                $mes = (int) $coluna['mes'];
                $ano = (int) $coluna['ano'];

                return [
                    'percentual' => (float) round($pct),
                    'mes' => $mes,
                    'ano' => $ano,
                    'label' => $this->mesExtenso($mes),
                ];
            }
        }

        return null;
    }

    public function fraseProjecaoParceladas(?array $horizonte, ?float $renda): ?string
    {
        if ($renda === null) {
            return null;
        }

        if ($horizonte === null) {
            return 'Mesmo sem novas parceladas, o comprometimento segue alto nos próximos 12 meses.';
        }

        return 'Se não realizar novas compras parceladas, seu comprometimento deve cair para '
            . $this->formatPctInteiro((float) $horizonte['percentual'])
            . ' em ' . $horizonte['label'] . '.';
    }

    public function fraseParceladas(float $valorAberto, int $compras): string
    {
        $distribuicao = $compras === 1
            ? 'distribuída em 1 compra'
            : 'distribuídas em ' . $compras . ' compras';

        return 'Você possui ' . $this->formatBrl($valorAberto) . ' em parcelas futuras, ' . $distribuicao . '.';
    }

    public function formatBrl(float $valor): string
    {
        if (abs($valor - round($valor)) < 0.005) {
            return 'R$ ' . number_format((int) round($valor), 0, ',', '.');
        }

        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    public function formatPctInteiro(float $valor): string
    {
        return (string) (int) round($valor) . '%';
    }

    public function mesExtenso(int $mes): string
    {
        return self::MESES_EXTENSO[$mes] ?? (string) $mes;
    }

    /**
     * @param  array<int, array{mes?: int, ano?: int}>  $faturas
     */
    public function contextoAguardandoConfirmacao(array $faturas): string
    {
        $meses = [];
        foreach ($faturas as $fatura) {
            $mes = (int) ($fatura['mes'] ?? 0);
            if ($mes >= 1 && $mes <= 12) {
                $meses[$mes] = $this->mesExtenso($mes);
            }
        }
        $meses = array_values($meses);

        $suffixo = 'O pagamento se confirma com o anexo da fatura seguinte ou por operação manual.';

        if ($meses === []) {
            return 'A fatura ainda não tem definição de atraso. '.$suffixo;
        }

        if (count($meses) === 1) {
            if (count($faturas) === 1) {
                return 'A fatura de '.$meses[0].' ainda não tem definição de atraso. '.$suffixo;
            }

            return 'As faturas de '.$meses[0].' ainda não têm definição de atraso. '.$suffixo;
        }

        return 'Há faturas sem confirmação de pagamento. '.$suffixo;
    }

    public function percentual(float $parte, float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($parte / $total) * 100, 1);
    }

    /**
     * @return array<int, array{id: string, label: string, atalho: array}>
     */
    public function montarAcoes(string $tipo, int $mes, int $ano): array
    {
        $acoes = [];

        $push = function (string $id, string $label, array $atalho) use (&$acoes): void {
            foreach ($acoes as $existente) {
                if ($existente['id'] === $id) {
                    return;
                }
            }
            $acoes[] = compact('id', 'label', 'atalho');
        };

        match ($tipo) {
            self::TIPO_ATRASO => $push('faturas', 'Ver faturas em aberto', $this->atalho('faturas', $mes, $ano)),
            self::TIPO_PARCELADAS => $push('parceladas', 'Ver compras parceladas', $this->atalho('parceladas', $mes, $ano)),
            self::TIPO_ASSINATURAS => $push('assinaturas', 'Ver assinaturas', ['rota' => 'assinaturas']),
            self::TIPO_CONCENTRACAO => $push('gastos_criticos', 'Onde estou gastando demais?', ['rota' => 'gastos-criticos']),
            self::TIPO_CRESCIMENTO => $push('faturas', 'Ver faturas', $this->atalho('faturas', $mes, $ano)),
            default => $push('posso_comprar', 'Posso comprar?', ['rota' => 'simulador']),
        };

        $push('parceladas', 'Ver compras parceladas', $this->atalho('parceladas', $mes, $ano));
        $push('posso_comprar', 'Posso comprar?', ['rota' => 'simulador']);
        $push('gastos_criticos', 'Onde estou gastando demais?', ['rota' => 'gastos-criticos']);

        return array_slice($acoes, 0, 3);
    }

    /**
     * @return array<int, array{
     *   pago: bool,
     *   data_vencimento: ?string,
     *   valor_restante: float,
     *   valor_total: float,
     *   id: int,
     *   mes: int,
     *   ano: int,
     *   proxima_tem_anexo: bool
     * }>
     */
    private function loadFaturasParaPagamento(int $userId, int $mes, int $ano): array
    {
        $fim = Carbon::create($ano, $mes, 1);
        $inicio = $fim->copy()->subMonthsNoOverflow(11);

        $faturas = Fatura::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($inicio, $fim) {
                $query->where(function ($inner) use ($inicio) {
                    $inner->where('ano', '>', (int) $inicio->year)
                        ->orWhere(function ($q) use ($inicio) {
                            $q->where('ano', (int) $inicio->year)
                                ->where('mes', '>=', (int) $inicio->month);
                        });
                })->where(function ($inner) use ($fim) {
                    $inner->where('ano', '<', (int) $fim->year)
                        ->orWhere(function ($q) use ($fim) {
                            $q->where('ano', (int) $fim->year)
                                ->where('mes', '<=', (int) $fim->month);
                        });
                });
            })
            ->with(['cartao:id,dia_limite_fatura,dia_vencimento_fatura'])
            ->get([
                'id',
                'cartao_id',
                'cartao_bandeira_id',
                'mes',
                'ano',
                'valor_total',
            ]);

        if ($faturas->isEmpty()) {
            return [];
        }

        $payload = $faturas->map(fn ($f) => [
            'id' => (int) $f->id,
            'cartao_id' => (int) $f->cartao_id,
            'cartao_bandeira_id' => $f->cartao_bandeira_id !== null ? (int) $f->cartao_bandeira_id : null,
            'mes' => (int) $f->mes,
            'ano' => (int) $f->ano,
            'valor_total' => (float) $f->valor_total,
        ])->all();

        $status = $this->faturaService->pagamentoStatusPorFaturas($payload, $userId);
        $proximaTemAnexoByKey = $this->proximaTemAnexoPorFaturas($faturas, $userId);

        return $faturas->map(function (Fatura $fatura) use ($status, $proximaTemAnexoByKey) {
            $id = (int) $fatura->id;
            $pagamento = $status[$id] ?? [
                'pago' => (float) $fatura->valor_total <= 0,
                'valor_pago' => 0.0,
                'valor_restante' => (float) $fatura->valor_total,
            ];
            $intervalo = $fatura->cartao
                ? $fatura->cartao->intervaloPeriodoFatura((int) $fatura->mes, (int) $fatura->ano)
                : ['data_vencimento' => null];

            $scopeKey = $fatura->cartao_bandeira_id !== null
                ? 'b:'.$fatura->cartao_bandeira_id
                : 'c:'.$fatura->cartao_id;
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura->mes,
                (int) $fatura->ano
            );

            return [
                'id' => $id,
                'mes' => (int) $fatura->mes,
                'ano' => (int) $fatura->ano,
                'pago' => (bool) $pagamento['pago'],
                'valor_total' => (float) $fatura->valor_total,
                'valor_restante' => (float) $pagamento['valor_restante'],
                'data_vencimento' => $intervalo['data_vencimento'] ?? null,
                'proxima_tem_anexo' => (bool) ($proximaTemAnexoByKey[$scopeKey.':'.$nextMes.':'.$nextAno] ?? false),
            ];
        })->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Fatura>  $faturas
     * @return array<string, bool>
     */
    private function proximaTemAnexoPorFaturas($faturas, int $userId): array
    {
        $nextKeys = [];
        foreach ($faturas as $fatura) {
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura->mes,
                (int) $fatura->ano
            );
            $scopeKey = $fatura->cartao_bandeira_id !== null
                ? 'b:'.$fatura->cartao_bandeira_id
                : 'c:'.$fatura->cartao_id;
            $nextKeys[$scopeKey.':'.$nextMes.':'.$nextAno] = [
                'cartao_id' => (int) $fatura->cartao_id,
                'cartao_bandeira_id' => $fatura->cartao_bandeira_id !== null
                    ? (int) $fatura->cartao_bandeira_id
                    : null,
                'mes' => $nextMes,
                'ano' => $nextAno,
            ];
        }

        if ($nextKeys === []) {
            return [];
        }

        $nextFaturas = Fatura::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($nextKeys) {
                foreach ($nextKeys as $next) {
                    $query->orWhere(function ($inner) use ($next) {
                        $inner->where('mes', $next['mes'])->where('ano', $next['ano']);
                        if ($next['cartao_bandeira_id'] !== null) {
                            $inner->where('cartao_bandeira_id', $next['cartao_bandeira_id']);
                        } else {
                            $inner->where('cartao_id', $next['cartao_id']);
                        }
                    });
                }
            })
            ->get(['cartao_id', 'cartao_bandeira_id', 'mes', 'ano', 'arquivo_pdf', 'arquivo_csv']);

        $result = [];
        foreach ($nextFaturas as $next) {
            $scopeKey = $next->cartao_bandeira_id !== null
                ? 'b:'.$next->cartao_bandeira_id
                : 'c:'.$next->cartao_id;
            $result[$scopeKey.':'.$next->mes.':'.$next->ano] = filled($next->arquivo_pdf)
                || filled($next->arquivo_csv);
        }

        return $result;
    }

    private function valorCompetencia(int $userId, int $mes, int $ano, ?int $responsavelId): float
    {
        if ($responsavelId) {
            $total = DB::table('transacoes as t')
                ->join('faturas as f', function ($join) {
                    $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
                })
                ->whereNull('t.deleted_at')
                ->where('t.user_id', $userId)
                ->where('t.tipo', Transacao::TIPO_PURCHASE)
                ->where('t.responsavel_id', $responsavelId)
                ->where('f.mes', $mes)
                ->where('f.ano', $ano)
                ->sum('t.valor');

            return round((float) $total, 2);
        }

        $total = DB::table('faturas')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->sum('valor_total');

        return round((float) $total, 2);
    }

    private function temFaturaCompetencia(int $userId, int $mes, int $ano): bool
    {
        return Fatura::query()
            ->where('user_id', $userId)
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->exists();
    }

    private function valorAssinaturasMes(int $userId, int $mes, int $ano, ?int $responsavelId): float
    {
        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->where('t.eh_assinatura', true)
            ->where('f.mes', $mes)
            ->where('f.ano', $ano);

        if ($responsavelId) {
            $query->where('t.responsavel_id', $responsavelId);
        }

        return round((float) $query->sum('t.valor'), 2);
    }

    /**
     * @return array{nome: string, percentual: float, valor: float, tipo: string, id: int|null}|null
     */
    private function maiorConcentracaoMes(int $userId, int $mes, int $ano, ?int $responsavelId): ?array
    {
        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('estabelecimentos as e', function ($join) {
                $join->on('e.id', '=', 't.estabelecimento_id')->whereNull('e.deleted_at');
            })
            ->leftJoin('lojas as l', function ($join) {
                $join->on('l.id', '=', 'e.loja_id')->whereNull('l.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->where('f.mes', $mes)
            ->where('f.ano', $ano);

        if ($responsavelId) {
            $query->where('t.responsavel_id', $responsavelId);
        }

        $total = (float) (clone $query)->sum('t.valor');
        if ($total <= 0) {
            return null;
        }

        $rows = $query
            ->selectRaw('l.id as loja_id, l.nome as loja_nome, e.id as estabelecimento_id, e.nome as estabelecimento_nome, COALESCE(SUM(t.valor), 0) as valor')
            ->groupBy('l.id', 'l.nome', 'e.id', 'e.nome')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $agregado = [];
        foreach ($rows as $row) {
            if ($row->loja_id) {
                $chave = 'loja-' . $row->loja_id;
                $nome = (string) $row->loja_nome;
                $tipo = 'loja';
                $id = (int) $row->loja_id;
            } else {
                $chave = 'estabelecimento-' . ($row->estabelecimento_id ?? '0');
                $nome = (string) ($row->estabelecimento_nome ?: 'Sem estabelecimento');
                $tipo = 'estabelecimento';
                $id = $row->estabelecimento_id !== null ? (int) $row->estabelecimento_id : null;
            }

            if (!isset($agregado[$chave])) {
                $agregado[$chave] = [
                    'id' => $id,
                    'nome' => $nome,
                    'tipo' => $tipo,
                    'valor' => 0.0,
                ];
            }
            $agregado[$chave]['valor'] += (float) $row->valor;
        }

        $agregado = array_values($agregado);
        usort($agregado, fn ($a, $b) => $b['valor'] <=> $a['valor']);
        $top = $agregado[0] ?? null;
        if (!$top) {
            return null;
        }

        $valor = round((float) $top['valor'], 2);

        return [
            'id' => $top['id'],
            'nome' => $top['nome'],
            'tipo' => $top['tipo'],
            'valor' => $valor,
            'percentual' => $this->percentual($valor, $total),
        ];
    }

    /**
     * @return array<int, array{mes: int, ano: int, label: string, referencia: bool, total: float}>
     */
    private function serieProjecaoTotais(int $mes, int $ano, ?int $responsavelId): array
    {
        $result = $this->projecao->handleProjecao((object) [
            'mes' => $mes,
            'ano' => $ano,
        ]);
        $data = is_object($result) ? (array) ($result->data ?? []) : [];
        $colunas = $data['colunas'] ?? [];

        $totais = [];
        if ($responsavelId) {
            foreach ($data['por_responsavel'] ?? [] as $linha) {
                if ((int) ($linha['responsavel_id'] ?? 0) === $responsavelId) {
                    foreach ($colunas as $index => $coluna) {
                        $totais[] = [
                            'mes' => (int) $coluna['mes'],
                            'ano' => (int) $coluna['ano'],
                            'label' => (string) $coluna['label'],
                            'referencia' => (bool) ($coluna['referencia'] ?? false),
                            'total' => (float) ($linha['valores'][$index]['total'] ?? 0),
                        ];
                    }
                    break;
                }
            }
        }

        if ($totais === []) {
            foreach ($data['totais_por_coluna'] ?? [] as $index => $colunaTotal) {
                $coluna = $colunas[$index] ?? $colunaTotal;
                $totais[] = [
                    'mes' => (int) ($coluna['mes'] ?? $colunaTotal['mes']),
                    'ano' => (int) ($coluna['ano'] ?? $colunaTotal['ano']),
                    'label' => (string) ($coluna['label'] ?? ''),
                    'referencia' => (bool) ($coluna['referencia'] ?? false),
                    'total' => (float) ($colunaTotal['cartoes']['total'] ?? 0),
                ];
            }
        }

        return $totais;
    }

    /**
     * @param array<string, mixed> $pagamentos
     */
    private function diagnosticoAtraso(array $pagamentos, int $mes, int $ano): array
    {
        $qtd = count($pagamentos['atrasadas']);
        $valor = (float) ($pagamentos['valor_atrasado'] ?? $pagamentos['valor_restante'] ?? 0);
        $frase = $qtd === 1
            ? 'Você tem 1 fatura vencida em aberto, no total de ' . $this->formatBrl($valor) . '.'
            : 'Você tem ' . $qtd . ' faturas vencidas em aberto, no total de ' . $this->formatBrl($valor) . '.';

        return $this->diagnostico(
            self::TIPO_ATRASO,
            'Principal problema: faturas em atraso.',
            $frase,
            'Quite as faturas vencidas para o Raio-X voltar ao verde.',
            'O atraso pesa mais do que o restante do mês neste recorte.',
            [
                'atrasadas' => $qtd,
                'valor_restante' => round($valor, 2),
            ],
            $this->atalho('faturas', $mes, $ano)
        );
    }

    private function diagnosticoParceladas(
        int $compras,
        float $valorAberto,
        ?array $horizonte,
        ?float $renda,
        int $mes,
        int $ano
    ): array {
        return $this->diagnostico(
            self::TIPO_PARCELADAS,
            'Principal problema: compras parceladas.',
            $this->fraseParceladas($valorAberto, $compras),
            $this->fraseProjecaoParceladas($horizonte, $renda),
            'Sem novas parceladas, a curva cai conforme as compras atuais terminam.',
            [
                'valor_aberto' => round($valorAberto, 2),
                'compras' => $compras,
                'comprometimento_atual_percentual' => null,
                'comprometimento_projetado_percentual' => $horizonte['percentual'] ?? null,
                'horizonte' => $horizonte,
            ],
            $this->atalho('parceladas', $mes, $ano)
        );
    }

    private function diagnosticoAssinaturas(float $valorAssinaturas, int $mes, int $ano): array
    {
        return $this->diagnostico(
            self::TIPO_ASSINATURAS,
            'Principal problema: assinaturas.',
            'Você tem ' . $this->formatBrl($valorAssinaturas) . ' em assinaturas neste mês.',
            'Revisar as cobranças recorrentes é o atalho mais rápido para baixar a fatura.',
            'Assinaturas oficiais (marcadas na compra ou confirmadas na tela de assinaturas).',
            ['valor_mes' => round($valorAssinaturas, 2)],
            ['rota' => 'assinaturas']
        );
    }

    /**
     * @param array<string, mixed> $sinalCrescimento
     */
    private function diagnosticoCrescimento(array $sinalCrescimento, int $mes, int $ano): array
    {
        $pct = $sinalCrescimento['metricas']['variacao_percentual'] ?? null;

        return $this->diagnostico(
            self::TIPO_CRESCIMENTO,
            'Principal problema: as faturas subiram rápido.',
            $sinalCrescimento['frase'] ?? 'Faturas cresceram neste mês.',
            $pct !== null
                ? 'Vale olhar onde o gasto acelerou antes de novas compras.'
                : null,
            $sinalCrescimento['contexto'] ?? '',
            [
                'variacao_percentual' => $pct,
                'valor_atual' => $sinalCrescimento['metricas']['valor_atual'] ?? 0,
            ],
            $this->atalho('gastos-criticos', $mes, $ano)
        );
    }

    /**
     * @param array{nome: string, percentual: float, valor: float, tipo: string, id: int|null}|null $concentracao
     */
    private function diagnosticoConcentracao(?array $concentracao, int $mes, int $ano): array
    {
        $nome = $concentracao['nome'] ?? 'um único lugar';
        $pct = $this->formatPctInteiro((float) ($concentracao['percentual'] ?? 0));
        $valor = $this->formatBrl((float) ($concentracao['valor'] ?? 0));

        return $this->diagnostico(
            self::TIPO_CONCENTRACAO,
            'Principal problema: gasto concentrado em um lugar.',
            $nome . ' concentrou ' . $pct . ' das compras deste mês (' . $valor . ').',
            'Olhar frequência e evolução nesse lugar costuma mostrar o que cortar primeiro.',
            'Não é só categoria: o recorte é o estabelecimento ou a loja.',
            $concentracao ?? [],
            ['rota' => 'gastos-criticos']
        );
    }

    private function diagnosticoOk(int $mes, int $ano): array
    {
        return $this->diagnostico(
            self::TIPO_OK,
            'Nenhum problema dominante este mês.',
            'As faturas estão sob controle neste recorte.',
            'Se for comprar parcelado, o simulador mostra o impacto nas próximas faturas.',
            '',
            [],
            $this->atalho('simulador', $mes, $ano)
        );
    }

    /**
     * @param array<string, mixed> $metricas
     */
    private function diagnostico(
        string $tipo,
        string $titulo,
        string $frase,
        ?string $projecao,
        string $contexto,
        array $metricas,
        array $atalho
    ): array {
        return [
            'tipo' => $tipo,
            'titulo' => $titulo,
            'frase' => $frase,
            'projecao' => $projecao,
            'contexto' => $contexto !== '' ? $contexto : null,
            'metricas' => $metricas,
            'atalho' => $atalho,
        ];
    }

    /**
     * @param array<string, mixed> $metricas
     */
    private function sinal(
        string $id,
        string $nivel,
        string $frase,
        string $contexto,
        array $metricas,
        array $atalho
    ): array {
        return [
            'id' => $id,
            'nivel' => $nivel,
            'titulo' => $frase,
            'frase' => $frase,
            'contexto' => $contexto,
            'metricas' => $metricas,
            'atalho' => $atalho,
        ];
    }

    private function atalho(string $rota, int $mes, int $ano): array
    {
        return [
            'rota' => $rota,
            'query' => [
                'mes' => $mes,
                'ano' => $ano,
            ],
        ];
    }
}
