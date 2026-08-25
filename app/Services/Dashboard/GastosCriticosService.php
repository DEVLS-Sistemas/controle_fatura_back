<?php

namespace App\Services\Dashboard;

use App\Models\Transacao;
use App\Services\Estabelecimento\EstabelecimentoEstatisticasService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GastosCriticosService
{
    public const MESES_VALIDOS = [1, 3, 6, 12];
    public const MESES_PADRAO = 3;
    public const TOP_RANKING = 8;
    public const MAX_ALERTAS = 10;

    public const TIPO_ESTABELECIMENTO = 'estabelecimento';
    public const TIPO_LOJA = 'loja';
    public const TIPO_CATEGORIA = 'categoria';
    public const TIPO_SUBCATEGORIA = 'subcategoria';

    public const MOTIVO_FREQUENCIA = 'frequencia';
    public const MOTIVO_GASTO = 'gasto';
    public const MOTIVO_EVOLUCAO = 'evolucao';
    public const MOTIVO_CONCENTRACAO = 'concentracao';

    private const MESES_LABEL = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    private EstabelecimentoEstatisticasService $estatisticas;

    public function __construct(?EstabelecimentoEstatisticasService $estatisticas = null)
    {
        $this->estatisticas = $estatisticas ?? new EstabelecimentoEstatisticasService();
    }

    public function handleGastosCriticos(object $atributes): object
    {
        try {
            $userId = (int) Auth::id();
            $periodos = $this->resolverPeriodos($atributes);
            $periodo = $periodos['atual'];
            $anterior = $periodos['anterior'];

            $linhasAtual = $this->mapLinhas($this->loadCompras($userId, $periodo['inicio'], $periodo['fim'], $atributes));
            $linhasAnterior = $this->mapLinhas($this->loadCompras($userId, $anterior['inicio'], $anterior['fim'], $atributes));

            $totais = $this->montarTotais($linhasAtual, $linhasAnterior, $periodo);
            $rankings = $this->montarRankings($linhasAtual, $linhasAnterior, $periodo, $totais);
            $destaques = $this->montarDestaques($rankings, $periodo);
            $alertas = $this->montarAlertas($rankings, $periodo);

            return (object) [
                'data' => [
                    'periodo' => $periodo,
                    'periodo_anterior' => $anterior,
                    'totais' => $totais,
                    'destaques' => $destaques,
                    'alertas' => $alertas,
                    'maiores_gastos' => [
                        'estabelecimentos' => $rankings['estabelecimento']['por_gasto'],
                        'lojas' => $rankings['loja']['por_gasto'],
                        'categorias' => $rankings['categoria']['por_gasto'],
                        'subcategorias' => $rankings['subcategoria']['por_gasto'],
                    ],
                    'mais_comprados' => [
                        'estabelecimentos' => $rankings['estabelecimento']['por_compras'],
                        'lojas' => $rankings['loja']['por_compras'],
                        'categorias' => $rankings['categoria']['por_compras'],
                        'subcategorias' => $rankings['subcategoria']['por_compras'],
                    ],
                    'evolucao' => [
                        'por_mes' => $this->montarEvolucao($linhasAtual, $linhasAnterior, $periodo),
                    ],
                ],
                'status' => true,
                'message' => 'Gastos críticos carregados com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array{atual: array<string, mixed>, anterior: array<string, mixed>}
     */
    public function resolverPeriodos(object $atributes, ?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? now())->copy()->startOfDay();

        if (!empty($atributes->data_inicio) || !empty($atributes->data_fim)) {
            $fim = !empty($atributes->data_fim)
                ? Carbon::parse((string) $atributes->data_fim)->startOfDay()
                : $hoje->copy();
            $inicio = !empty($atributes->data_inicio)
                ? Carbon::parse((string) $atributes->data_inicio)->startOfDay()
                : $fim->copy()->subMonthsNoOverflow(self::MESES_PADRAO);

            if ($inicio->gt($fim)) {
                throw new Exception('data_inicio deve ser anterior ou igual a data_fim', 422);
            }

            $dias = $inicio->diffInDays($fim) + 1;
            $meses = max(1, (int) round($dias / 30.437));

            return $this->montarParPeriodos($inicio, $fim, $meses, 'filtro');
        }

        if (!empty($atributes->mes) && !empty($atributes->ano) && empty($atributes->meses)) {
            $mes = (int) $atributes->mes;
            $ano = (int) $atributes->ano;
            if ($mes < 1 || $mes > 12 || $ano < 1) {
                throw new Exception('Mês/ano inválidos', 422);
            }
            $inicio = Carbon::create($ano, $mes, 1)->startOfDay();
            $fim = $inicio->copy()->endOfMonth()->startOfDay();

            return $this->montarParPeriodos($inicio, $fim, 1, 'mes');
        }

        $meses = (int) ($atributes->meses ?? self::MESES_PADRAO);
        if (!in_array($meses, self::MESES_VALIDOS, true)) {
            throw new Exception('meses deve ser 1, 3, 6 ou 12', 422);
        }

        $fim = $hoje->copy();
        $inicio = $fim->copy()->subMonthsNoOverflow($meses);

        return $this->montarParPeriodos($inicio, $fim, $meses, 'janela');
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @return array<string, mixed>
     */
    public function montarTotais(array $linhas, array $linhasAnterior, array $periodo): array
    {
        $atual = $this->resumoLinhas($linhas);
        $anterior = $this->resumoLinhas($linhasAnterior);

        return [
            'valor_total' => $atual['valor_total'],
            'compras' => $atual['compras'],
            'ocorrencias' => $atual['ocorrencias'],
            'ticket_medio' => $atual['compras'] > 0
                ? round($atual['valor_total'] / $atual['compras'], 2)
                : 0.0,
            'valor_anterior' => $anterior['valor_total'],
            'compras_anterior' => $anterior['compras'],
            'variacao_valor_percentual' => $this->variacaoPercentual($atual['valor_total'], $anterior['valor_total']),
            'variacao_compras_percentual' => $this->variacaoPercentual((float) $atual['compras'], (float) $anterior['compras']),
            'frequencia' => $this->estatisticas->buildFrequencia($atual['compras'], (int) $periodo['dias']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $linhasAnterior
     * @param array<string, mixed> $periodo
     * @param array<string, mixed> $totais
     * @return array<string, array{todos: array<int, array<string, mixed>>, por_gasto: array<int, array<string, mixed>>, por_compras: array<int, array<string, mixed>>}>
     */
    public function montarRankings(array $linhas, array $linhasAnterior, array $periodo, array $totais): array
    {
        $rankings = [];
        foreach ([self::TIPO_ESTABELECIMENTO, self::TIPO_LOJA, self::TIPO_CATEGORIA, self::TIPO_SUBCATEGORIA] as $tipo) {
            $atual = $this->agregarPor($linhas, $tipo, $periodo, $totais);
            $anteriorMapa = $this->mapaPorChave($this->agregarPor($linhasAnterior, $tipo, $periodo, [
                'valor_total' => 0.0,
                'compras' => 0,
            ]));
            $cruzado = array_map(
                fn (array $item) => $this->cruzarComAnterior($item, $anteriorMapa[$item['chave']] ?? null, $periodo),
                $atual
            );

            $rankings[$tipo] = [
                'todos' => $cruzado,
                'por_gasto' => $this->topPor($cruzado, 'valor_total'),
                'por_compras' => $this->topPor($cruzado, 'compras'),
            ];
        }

        return $rankings;
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<string, mixed> $periodo
     * @param array{valor_total: float, compras: int} $totais
     * @return array<int, array<string, mixed>>
     */
    public function agregarPor(array $linhas, string $tipo, array $periodo, array $totais): array
    {
        $grupos = [];
        foreach ($linhas as $linha) {
            $meta = $this->metaGrupo($linha, $tipo);
            if ($meta === null) {
                continue;
            }

            $chave = $meta['chave'];
            if (!isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'chave' => $chave,
                    'tipo' => $tipo,
                    'id' => $meta['id'],
                    'nome' => $meta['nome'],
                    'nome_exibicao' => $meta['nome_exibicao'],
                    'loja_id' => $meta['loja_id'],
                    'loja_nome' => $meta['loja_nome'],
                    'categoria_id' => $meta['categoria_id'],
                    'categoria_nome' => $meta['categoria_nome'],
                    'categoria_cor' => $meta['categoria_cor'],
                    'subcategoria_id' => $meta['subcategoria_id'],
                    'subcategoria_nome' => $meta['subcategoria_nome'],
                    'compras_chaves' => [],
                    'ocorrencias' => 0,
                    'valor_total' => 0.0,
                    'primeira_compra' => null,
                    'ultima_compra' => null,
                ];
            }

            $grupos[$chave]['ocorrencias']++;
            $grupos[$chave]['valor_total'] += (float) $linha['valor'];
            $grupos[$chave]['compras_chaves'][$linha['compra_chave']] = true;
            $data = (string) $linha['data'];
            if ($grupos[$chave]['primeira_compra'] === null || $data < $grupos[$chave]['primeira_compra']) {
                $grupos[$chave]['primeira_compra'] = $data;
            }
            if ($grupos[$chave]['ultima_compra'] === null || $data > $grupos[$chave]['ultima_compra']) {
                $grupos[$chave]['ultima_compra'] = $data;
            }
        }

        $totalValor = (float) ($totais['valor_total'] ?? 0);
        $totalCompras = (int) ($totais['compras'] ?? 0);
        $itens = [];
        foreach ($grupos as $grupo) {
            $compras = count($grupo['compras_chaves']);
            $valor = round((float) $grupo['valor_total'], 2);
            unset($grupo['compras_chaves']);
            $grupo['compras'] = $compras;
            $grupo['valor_total'] = $valor;
            $grupo['ticket_medio'] = $compras > 0 ? round($valor / $compras, 2) : 0.0;
            $grupo['percentual_gasto'] = $this->percentual($valor, $totalValor);
            $grupo['percentual_compras'] = $this->percentual((float) $compras, (float) $totalCompras);
            $grupo['frequencia'] = $this->estatisticas->buildFrequencia($compras, (int) $periodo['dias']);
            $grupo['atalho'] = $this->montarAtalho($tipo, $grupo['id'], $periodo);
            $itens[] = $grupo;
        }

        usort($itens, function (array $a, array $b) {
            $cmp = $b['valor_total'] <=> $a['valor_total'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['compras'] <=> $a['compras'];
        });

        return array_values($itens);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $anterior
     * @param array<string, mixed> $periodo
     * @return array<string, mixed>
     */
    public function cruzarComAnterior(array $item, ?array $anterior, array $periodo): array
    {
        $valorAnterior = (float) ($anterior['valor_total'] ?? 0);
        $comprasAnterior = (int) ($anterior['compras'] ?? 0);
        $item['valor_anterior'] = $valorAnterior;
        $item['compras_anterior'] = $comprasAnterior;
        $item['variacao_valor_percentual'] = $this->variacaoPercentual((float) $item['valor_total'], $valorAnterior);
        $item['variacao_compras_percentual'] = $this->variacaoPercentual((float) $item['compras'], (float) $comprasAnterior);
        $item['frase_frequencia'] = $this->fraseFrequencia($item, $periodo);
        $item['frase_gasto'] = $this->fraseGasto($item, $periodo);
        $item['frase_evolucao'] = $this->fraseEvolucao($item, $periodo);

        return $item;
    }

    /**
     * @param array<string, array{todos: array<int, array<string, mixed>>, por_gasto: array<int, array<string, mixed>>, por_compras: array<int, array<string, mixed>>}> $rankings
     * @param array<string, mixed> $periodo
     * @return array{maior_gasto: ?array<string, mixed>, mais_comprado: ?array<string, mixed>}
     */
    public function montarDestaques(array $rankings, array $periodo): array
    {
        $maiorGasto = $this->escolherDestaque($rankings, 'por_gasto');
        $maisComprado = $this->escolherDestaque($rankings, 'por_compras');

        return [
            'maior_gasto' => $maiorGasto === null ? null : $this->payloadDestaque(
                $maiorGasto,
                'gasto',
                $this->fraseMaiorGasto($maiorGasto, $periodo)
            ),
            'mais_comprado' => $maisComprado === null ? null : $this->payloadDestaque(
                $maisComprado,
                'frequencia',
                $maisComprado['frase_frequencia']
            ),
        ];
    }

    /**
     * @param array<string, array{todos: array<int, array<string, mixed>>}> $rankings
     * @param array<string, mixed> $periodo
     * @return array<int, array<string, mixed>>
     */
    public function montarAlertas(array $rankings, array $periodo): array
    {
        $candidatos = [];
        foreach ([self::TIPO_LOJA, self::TIPO_ESTABELECIMENTO, self::TIPO_SUBCATEGORIA, self::TIPO_CATEGORIA] as $tipo) {
            foreach ($rankings[$tipo]['todos'] as $item) {
                $avaliacao = $this->avaliarItem($item, $periodo);

                if ($avaliacao === null) {
                    continue;
                }
                $candidatos[] = $avaliacao;
            }
        }

        $candidatos = $this->deduplicarAlertas($candidatos);
        usort($candidatos, function (array $a, array $b) {
            $sev = $this->pesoSeveridade($b['severidade']) <=> $this->pesoSeveridade($a['severidade']);
            if ($sev !== 0) {
                return $sev;
            }

            return $b['score'] <=> $a['score'];
        });

        return array_values(array_slice($candidatos, 0, self::MAX_ALERTAS));
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     * @return array<string, mixed>|null
     */
    public function avaliarItem(array $item, array $periodo): ?array
    {
        $meses = max(1, (int) ($periodo['meses'] ?? 1));
        $minCompras = $this->minComprasFrequencia($meses);
        $motivos = [];
        $score = 0.0;

        $compras = (int) $item['compras'];
        $percentualGasto = (float) $item['percentual_gasto'];
        $percentualCompras = (float) $item['percentual_compras'];
        $variacao = $item['variacao_valor_percentual'];
        $valor = (float) $item['valor_total'];

        if ($compras >= $minCompras) {
            $motivos[] = self::MOTIVO_FREQUENCIA;
            $score += min(40.0, ($compras / ($meses * 2)) * 20) + min(20.0, $percentualCompras);
        }

        if ($percentualGasto >= 12.0 && $valor >= 80.0) {
            $motivos[] = $item['tipo'] === self::TIPO_CATEGORIA || $item['tipo'] === self::TIPO_SUBCATEGORIA
                ? self::MOTIVO_CONCENTRACAO
                : self::MOTIVO_GASTO;
            $score += min(40.0, $percentualGasto * 1.4);
        }

        if (is_numeric($variacao) && (float) $variacao >= 25.0 && ($valor - (float) $item['valor_anterior']) >= 80.0) {
            $motivos[] = self::MOTIVO_EVOLUCAO;
            $score += min(30.0, ((float) $variacao) / 3);
        }

        if ($motivos === []) {
            return null;
        }

        $severidade = $this->classificarSeveridade($compras, $meses, $percentualGasto, $variacao);
        $frase = $this->fraseAlerta($item, $motivos, $periodo);
        $contexto = $this->contextoAlerta($item);

        return [
            'id' => implode(':', $motivos) . ':' . $item['chave'],
            'tipo' => $motivos[0],
            'motivos' => $motivos,
            'severidade' => $severidade,
            'score' => (int) round($score),
            'titulo' => $item['nome_exibicao'],
            'frase' => $frase,
            'contexto' => $contexto,
            'entidade' => $this->payloadEntidade($item),
            'metricas' => [
                'compras' => $compras,
                'ocorrencias' => (int) $item['ocorrencias'],
                'valor_total' => $valor,
                'ticket_medio' => (float) $item['ticket_medio'],
                'percentual_gasto' => $percentualGasto,
                'percentual_compras' => $percentualCompras,
                'variacao_valor_percentual' => $item['variacao_valor_percentual'],
                'variacao_compras_percentual' => $item['variacao_compras_percentual'],
                'frequencia' => $item['frequencia'],
            ],
            'atalho' => $item['atalho'],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseFrequencia(array $item, array $periodo): string
    {
        $n = (int) $item['compras'];
        $onde = $this->ondeFrase($item, true);
        $quando = $periodo['label_frase'];
        $vezes = $n === 1 ? '1 vez' : $n . ' vezes';

        return "Você comprou {$vezes} {$onde} {$quando}.";
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseGasto(array $item, array $periodo): string
    {
        $onde = $this->ondeFrase($item, false);
        $quando = $periodo['label_frase'];
        $brl = $this->formatBrl((float) $item['valor_total']);
        $pct = $this->formatPct((float) $item['percentual_gasto']);

        return "Você gastou {$brl} {$onde} {$quando} — {$pct} do total.";
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseEvolucao(array $item, array $periodo): ?string
    {
        $variacao = $item['variacao_valor_percentual'];
        if ($variacao === null) {
            return 'Não havia gasto ' . $this->ondeFrase($item, false) . ' ' . $periodo['label_anterior_frase'] . '.';
        }

        $pct = $this->formatPct(abs((float) $variacao));
        $onde = $this->ondeFrase($item, false);
        $ref = $periodo['label_anterior_frase'];

        if ((float) $variacao >= 1) {
            return "Seu gasto {$onde} subiu {$pct} em relação {$ref}.";
        }
        if ((float) $variacao <= -1) {
            return "Seu gasto {$onde} caiu {$pct} em relação {$ref}.";
        }

        return "Seu gasto {$onde} ficou estável em relação {$ref}.";
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseMaiorGasto(array $item, array $periodo): string
    {
        $onde = $this->ondeFrase($item, false);
        $quando = $periodo['label_frase'];
        $brl = $this->formatBrl((float) $item['valor_total']);
        $pct = $this->formatPct((float) $item['percentual_gasto']);

        return "O maior gasto {$quando} foi {$onde}: {$brl} ({$pct} do total).";
    }

    public function variacaoPercentual(float $atual, float $anterior): ?float
    {
        if ($anterior <= 0.0) {
            return $atual > 0.0 ? null : 0.0;
        }

        return round((($atual - $anterior) / $anterior) * 100, 1);
    }

    public function formatBrl(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    public function minComprasFrequencia(int $meses): int
    {
        return max(4, $meses * 2);
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $linhasAnterior
     * @param array<string, mixed> $periodo
     * @return array<int, array<string, mixed>>
     */
    public function montarEvolucao(array $linhas, array $linhasAnterior, array $periodo): array
    {
        $atual = $this->totaisPorMes($linhas);
        $anterior = $this->totaisPorMes($linhasAnterior);
        $inicio = Carbon::parse((string) $periodo['inicio'])->startOfMonth();
        $fim = Carbon::parse((string) $periodo['fim'])->startOfMonth();
        $hoje = Carbon::parse((string) $periodo['fim']);
        $colunas = [];
        $cursor = $inicio->copy();
        $valorMesAnterior = null;

        $chaveAntes = $cursor->copy()->subMonthNoOverflow()->format('Y-m');
        if (isset($anterior[$chaveAntes])) {
            $valorMesAnterior = (float) $anterior[$chaveAntes]['valor_total'];
        }

        while ($cursor->lte($fim)) {
            $chave = $cursor->format('Y-m');
            $mes = (int) $cursor->month;
            $ano = (int) $cursor->year;
            $bloco = $atual[$chave] ?? ['valor_total' => 0.0, 'compras' => 0];
            $ultimoDiaMes = $cursor->copy()->endOfMonth()->startOfDay();
            $parcial = $hoje->lt($ultimoDiaMes);

            $colunas[] = [
                'mes' => $mes,
                'ano' => $ano,
                'chave' => $chave,
                'label' => (self::MESES_LABEL[$mes] ?? $cursor->format('M')) . '/' . $ano,
                'valor_total' => round((float) $bloco['valor_total'], 2),
                'compras' => (int) $bloco['compras'],
                'variacao_percentual' => $valorMesAnterior === null
                    ? ($bloco['valor_total'] > 0 ? null : 0.0)
                    : $this->variacaoPercentual((float) $bloco['valor_total'], $valorMesAnterior),
                'parcial' => $parcial,
            ];

            $valorMesAnterior = (float) $bloco['valor_total'];
            $cursor->addMonthNoOverflow();
        }

        return $colunas;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    public function mapLinhas(Collection $rows): array
    {
        return $rows->map(function (object $row) {
            $grupoId = $row->compra_grupo_id ?? null;

            return [
                'id' => (int) $row->id,
                'compra_chave' => !empty($grupoId) ? (string) $grupoId : ('av-' . (int) $row->id),
                'data' => Carbon::parse((string) $row->data)->toDateString(),
                'valor' => (float) $row->valor,
                'estabelecimento_id' => $row->estabelecimento_id !== null ? (int) $row->estabelecimento_id : null,
                'estabelecimento_nome' => $row->estabelecimento_nome !== null ? (string) $row->estabelecimento_nome : null,
                'loja_id' => $row->loja_id !== null ? (int) $row->loja_id : null,
                'loja_nome' => $row->loja_nome !== null ? (string) $row->loja_nome : null,
                'categoria_id' => $row->categoria_id !== null ? (int) $row->categoria_id : null,
                'categoria_nome' => $row->categoria_nome !== null ? (string) $row->categoria_nome : 'Sem categoria',
                'categoria_cor' => $row->categoria_cor !== null ? (string) $row->categoria_cor : null,
                'subcategoria_id' => $row->subcategoria_id !== null ? (int) $row->subcategoria_id : null,
                'subcategoria_nome' => $row->subcategoria_nome !== null ? (string) $row->subcategoria_nome : null,
            ];
        })->values()->all();
    }

    /**
     * @return array{atual: array<string, mixed>, anterior: array<string, mixed>}
     */
    private function montarParPeriodos(Carbon $inicio, Carbon $fim, int $meses, string $origem): array
    {
        $atual = $this->periodoPayload($inicio, $fim, $meses, $origem);
        $fimAnterior = $inicio->copy()->subDay();
        $inicioAnterior = $origem === 'mes'
            ? $inicio->copy()->subMonthNoOverflow()->startOfMonth()
            : $inicio->copy()->subMonthsNoOverflow($meses);

        if ($inicioAnterior->gt($fimAnterior)) {
            $inicioAnterior = $fimAnterior->copy();
        }

        return [
            'atual' => $atual,
            'anterior' => $this->periodoPayload($inicioAnterior, $fimAnterior, $meses, 'anterior'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodoPayload(Carbon $inicio, Carbon $fim, int $meses, string $origem): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fim = $fim->copy()->startOfDay();
        $labels = $this->labelsPeriodo($meses, $origem);

        return [
            'inicio' => $inicio->toDateString(),
            'fim' => $fim->toDateString(),
            'origem' => $origem,
            'meses' => $meses,
            'dias' => $inicio->diffInDays($fim) + 1,
            'label' => $labels['label'],
            'label_frase' => $labels['frase'],
            'label_anterior' => $labels['anterior'],
            'label_anterior_frase' => $labels['anterior_frase'],
        ];
    }

    /**
     * @return array{label: string, frase: string, anterior: string, anterior_frase: string}
     */
    private function labelsPeriodo(int $meses, string $origem): array
    {
        if ($meses === 1 || $origem === 'mes') {
            $atual = [
                'label' => 'Último mês',
                'frase' => 'no último mês',
                'anterior' => 'Mês anterior',
                'anterior_frase' => 'ao mês anterior',
            ];
        } elseif ($meses === 12) {
            $atual = [
                'label' => 'Último ano',
                'frase' => 'no último ano',
                'anterior' => 'Ano anterior',
                'anterior_frase' => 'ao ano anterior',
            ];
        } else {
            $atual = [
                'label' => "Últimos {$meses} meses",
                'frase' => "nos últimos {$meses} meses",
                'anterior' => "{$meses} meses anteriores",
                'anterior_frase' => "aos {$meses} meses anteriores",
            ];
        }

        if ($origem === 'anterior') {
            return [
                'label' => $atual['anterior'],
                'frase' => str_replace('ao ', 'no ', str_replace('aos ', 'nos ', $atual['anterior_frase'])),
                'anterior' => $atual['anterior'],
                'anterior_frase' => $atual['anterior_frase'],
            ];
        }

        return $atual;
    }

    private function loadCompras(int $userId, string $inicio, string $fim, object $atributes): Collection
    {
        $query = DB::table('transacoes as t')
            ->leftJoin('estabelecimentos as e', function ($join) {
                $join->on('e.id', '=', 't.estabelecimento_id')->whereNull('e.deleted_at');
            })
            ->leftJoin('lojas as l', function ($join) {
                $join->on('l.id', '=', 'e.loja_id')->whereNull('l.deleted_at');
            })
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 't.categoria_id')->whereNull('cat.deleted_at');
            })
            ->leftJoin('subcategorias as sub', function ($join) {
                $join->on('sub.id', '=', 't.subcategoria_id')->whereNull('sub.deleted_at');
            })
            ->where('t.user_id', $userId)
            ->whereNull('t.deleted_at')
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->whereNotNull('t.data')
            ->whereDate('t.data', '>=', $inicio)
            ->whereDate('t.data', '<=', $fim);

        if (!empty($atributes->responsavel_id)) {
            $query->where('t.responsavel_id', (int) $atributes->responsavel_id);
        }

        if (!empty($atributes->cartao_id)) {
            $query->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })->where('f.cartao_id', (int) $atributes->cartao_id);
        }

        if (!empty($atributes->categoria_id)) {
            $query->where('t.categoria_id', (int) $atributes->categoria_id);
        }

        return $query->select([
            't.id',
            't.compra_grupo_id',
            't.data',
            't.valor',
            't.estabelecimento_id',
            'e.nome as estabelecimento_nome',
            'e.loja_id',
            'l.nome as loja_nome',
            't.categoria_id',
            'cat.nome as categoria_nome',
            'cat.cor as categoria_cor',
            't.subcategoria_id',
            'sub.nome as subcategoria_nome',
        ])->get();
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @return array{valor_total: float, compras: int, ocorrencias: int}
     */
    private function resumoLinhas(array $linhas): array
    {
        $chaves = [];
        $valor = 0.0;
        foreach ($linhas as $linha) {
            $chaves[$linha['compra_chave']] = true;
            $valor += (float) $linha['valor'];
        }

        return [
            'valor_total' => round($valor, 2),
            'compras' => count($chaves),
            'ocorrencias' => count($linhas),
        ];
    }

    /**
     * @param array<string, mixed> $linha
     * @return array<string, mixed>|null
     */
    private function metaGrupo(array $linha, string $tipo): ?array
    {
        if ($tipo === self::TIPO_ESTABELECIMENTO) {
            if (empty($linha['estabelecimento_id'])) {
                return null;
            }
            $nome = (string) ($linha['estabelecimento_nome'] ?? 'Estabelecimento');
            $lojaNome = $linha['loja_nome'] !== null ? (string) $linha['loja_nome'] : null;

            return [
                'chave' => 'estabelecimento-' . (int) $linha['estabelecimento_id'],
                'id' => (int) $linha['estabelecimento_id'],
                'nome' => $nome,
                'nome_exibicao' => $lojaNome ?: $nome,
                'loja_id' => $linha['loja_id'] !== null ? (int) $linha['loja_id'] : null,
                'loja_nome' => $lojaNome,
                'categoria_id' => $linha['categoria_id'],
                'categoria_nome' => $linha['categoria_nome'],
                'categoria_cor' => $linha['categoria_cor'],
                'subcategoria_id' => $linha['subcategoria_id'],
                'subcategoria_nome' => $linha['subcategoria_nome'],
            ];
        }

        if ($tipo === self::TIPO_LOJA) {
            if (empty($linha['loja_id'])) {
                return null;
            }
            $nome = (string) $linha['loja_nome'];

            return [
                'chave' => 'loja-' . (int) $linha['loja_id'],
                'id' => (int) $linha['loja_id'],
                'nome' => $nome,
                'nome_exibicao' => $nome,
                'loja_id' => (int) $linha['loja_id'],
                'loja_nome' => $nome,
                'categoria_id' => $linha['categoria_id'],
                'categoria_nome' => $linha['categoria_nome'],
                'categoria_cor' => $linha['categoria_cor'],
                'subcategoria_id' => $linha['subcategoria_id'],
                'subcategoria_nome' => $linha['subcategoria_nome'],
            ];
        }

        if ($tipo === self::TIPO_CATEGORIA) {
            $id = $linha['categoria_id'] !== null ? (int) $linha['categoria_id'] : 0;
            $nome = $id > 0 ? (string) $linha['categoria_nome'] : 'Sem categoria';

            return [
                'chave' => $id > 0 ? 'categoria-' . $id : 'categoria-0',
                'id' => $id > 0 ? $id : null,
                'nome' => $nome,
                'nome_exibicao' => $nome,
                'loja_id' => null,
                'loja_nome' => null,
                'categoria_id' => $id > 0 ? $id : null,
                'categoria_nome' => $nome,
                'categoria_cor' => $linha['categoria_cor'],
                'subcategoria_id' => null,
                'subcategoria_nome' => null,
            ];
        }

        if (empty($linha['subcategoria_id'])) {
            return null;
        }

        $nome = (string) $linha['subcategoria_nome'];

        return [
            'chave' => 'subcategoria-' . (int) $linha['subcategoria_id'],
            'id' => (int) $linha['subcategoria_id'],
            'nome' => $nome,
            'nome_exibicao' => $nome,
            'loja_id' => null,
            'loja_nome' => null,
            'categoria_id' => $linha['categoria_id'],
            'categoria_nome' => $linha['categoria_nome'],
            'categoria_cor' => $linha['categoria_cor'],
            'subcategoria_id' => (int) $linha['subcategoria_id'],
            'subcategoria_nome' => $nome,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    private function topPor(array $itens, string $campo): array
    {
        $copia = $itens;
        usort($copia, function (array $a, array $b) use ($campo) {
            $cmp = $b[$campo] <=> $a[$campo];
            if ($cmp !== 0) {
                return $cmp;
            }
            $outro = $campo === 'compras' ? 'valor_total' : 'compras';

            return $b[$outro] <=> $a[$outro];
        });

        $top = array_slice($copia, 0, self::TOP_RANKING);
        foreach ($top as $i => $item) {
            $top[$i]['posicao'] = $i + 1;
        }

        return array_values($top);
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<string, array<string, mixed>>
     */
    private function mapaPorChave(array $itens): array
    {
        $mapa = [];
        foreach ($itens as $item) {
            $mapa[$item['chave']] = $item;
        }

        return $mapa;
    }

    /**
     * @param array<string, array{por_gasto: array<int, array<string, mixed>>, por_compras: array<int, array<string, mixed>>}> $rankings
     * @return array<string, mixed>|null
     */
    private function escolherDestaque(array $rankings, string $lista): ?array
    {
        $campo = $lista === 'por_gasto' ? 'valor_total' : 'compras';
        $candidatos = [];
        foreach ([self::TIPO_LOJA, self::TIPO_ESTABELECIMENTO] as $tipo) {
            $primeiro = $rankings[$tipo][$lista][0] ?? null;
            if ($primeiro && (int) $primeiro['compras'] > 0) {
                $candidatos[] = $primeiro;
            }
        }

        if ($candidatos === []) {
            $cat = $rankings[self::TIPO_CATEGORIA][$lista][0] ?? null;

            return ($cat && (int) $cat['compras'] > 0) ? $cat : null;
        }

        usort($candidatos, function (array $a, array $b) use ($campo) {
            $cmp = $b[$campo] <=> $a[$campo];
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['tipo'] === self::TIPO_LOJA ? 0 : 1) <=> ($b['tipo'] === self::TIPO_LOJA ? 0 : 1);
        });

        return $candidatos[0];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function payloadDestaque(array $item, string $tipo, string $frase): array
    {
        return [
            'tipo' => $tipo,
            'chave' => $item['chave'],
            'entidade_tipo' => $item['tipo'],
            'id' => $item['id'],
            'nome' => $item['nome_exibicao'],
            'frase' => $frase,
            'contexto' => $this->contextoAlerta($item),
            'compras' => (int) $item['compras'],
            'valor_total' => (float) $item['valor_total'],
            'percentual_gasto' => (float) $item['percentual_gasto'],
            'percentual_compras' => (float) $item['percentual_compras'],
            'frequencia' => $item['frequencia'],
            'variacao_valor_percentual' => $item['variacao_valor_percentual'],
            'atalho' => $item['atalho'],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function payloadEntidade(array $item): array
    {
        return [
            'tipo' => $item['tipo'],
            'chave' => $item['chave'],
            'id' => $item['id'],
            'nome' => $item['nome'],
            'nome_exibicao' => $item['nome_exibicao'],
            'loja_id' => $item['loja_id'],
            'loja_nome' => $item['loja_nome'],
            'categoria_id' => $item['categoria_id'],
            'categoria_nome' => $item['categoria_nome'],
            'categoria_cor' => $item['categoria_cor'],
            'subcategoria_id' => $item['subcategoria_id'],
            'subcategoria_nome' => $item['subcategoria_nome'],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $motivos
     * @param array<string, mixed> $periodo
     */
    private function fraseAlerta(array $item, array $motivos, array $periodo): string
    {
        if (in_array(self::MOTIVO_FREQUENCIA, $motivos, true)) {
            return $item['frase_frequencia'];
        }
        if (in_array(self::MOTIVO_EVOLUCAO, $motivos, true) && !empty($item['frase_evolucao'])) {
            return (string) $item['frase_evolucao'];
        }

        return $item['frase_gasto'];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function contextoAlerta(array $item): string
    {
        $partes = [];
        if (!empty($item['frequencia']['label']) && (int) $item['compras'] > 1) {
            $partes[] = 'Isso equivale a ' . $item['frequencia']['label'];
        }
        $partes[] = $this->formatBrl((float) $item['valor_total']) . ' no período';
        if (is_numeric($item['variacao_valor_percentual'] ?? null)) {
            $var = (float) $item['variacao_valor_percentual'];
            if (abs($var) >= 1) {
                $sinal = $var > 0 ? '+' : '-';
                $partes[] = $sinal . $this->formatPct($var) . ' vs período anterior';
            }
        }

        return implode(' · ', $partes) . '.';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function ondeFrase(array $item, bool $frequenciaEstabelecimento): string
    {
        $tipo = $item['tipo'];
        if ($tipo === self::TIPO_ESTABELECIMENTO && $frequenciaEstabelecimento) {
            return 'neste estabelecimento';
        }
        if ($tipo === self::TIPO_ESTABELECIMENTO) {
            return 'em ' . $item['nome_exibicao'];
        }
        if ($tipo === self::TIPO_LOJA) {
            return 'em ' . $item['nome_exibicao'];
        }
        if ($tipo === self::TIPO_SUBCATEGORIA) {
            $cat = $item['categoria_nome'] ? ' (' . $item['categoria_nome'] . ')' : '';

            return 'em ' . $item['nome_exibicao'] . $cat;
        }

        return 'em ' . $item['nome_exibicao'];
    }

    /**
     * @param array<int, array<string, mixed>> $alertas
     * @return array<int, array<string, mixed>>
     */
    private function deduplicarAlertas(array $alertas): array
    {
        $lojasComAlerta = [];
        foreach ($alertas as $alerta) {
            if (($alerta['entidade']['tipo'] ?? null) === self::TIPO_LOJA) {
                $lojasComAlerta[(int) $alerta['entidade']['id']] = true;
            }
        }

        $categoriasComSub = [];
        foreach ($alertas as $alerta) {
            $ent = $alerta['entidade'];
            if (($ent['tipo'] ?? null) === self::TIPO_SUBCATEGORIA && !empty($ent['categoria_id'])) {
                $gastoSub = (float) $alerta['metricas']['percentual_gasto'];
                $categoriasComSub[(int) $ent['categoria_id']] = max(
                    $categoriasComSub[(int) $ent['categoria_id']] ?? 0.0,
                    $gastoSub
                );
            }
        }

        $filtrados = [];
        $vistos = [];
        foreach ($alertas as $alerta) {
            $ent = $alerta['entidade'];
            $chave = (string) $ent['chave'];
            if (isset($vistos[$chave])) {
                continue;
            }

            if ($ent['tipo'] === self::TIPO_ESTABELECIMENTO && !empty($ent['loja_id']) && isset($lojasComAlerta[(int) $ent['loja_id']])) {
                continue;
            }

            if ($ent['tipo'] === self::TIPO_CATEGORIA && !empty($ent['id'])) {
                $subPct = $categoriasComSub[(int) $ent['id']] ?? 0.0;
                $catPct = (float) $alerta['metricas']['percentual_gasto'];
                if ($subPct > 0 && $catPct > 0 && ($subPct / $catPct) >= 0.7) {
                    continue;
                }
            }

            $vistos[$chave] = true;
            $filtrados[] = $alerta;
        }

        return $filtrados;
    }

    private function classificarSeveridade(int $compras, int $meses, float $percentualGasto, mixed $variacao): string
    {
        $porMes = $compras / max($meses, 1);
        $var = is_numeric($variacao) ? (float) $variacao : 0.0;

        if ($porMes >= 3 || $percentualGasto >= 25.0 || $var >= 50.0) {
            return 'alta';
        }
        if ($porMes >= 2 || $percentualGasto >= 15.0 || $var >= 25.0) {
            return 'media';
        }

        return 'baixa';
    }

    private function pesoSeveridade(string $severidade): int
    {
        return match ($severidade) {
            'alta' => 3,
            'media' => 2,
            default => 1,
        };
    }

    /**
     * @param array<string, mixed> $periodo
     * @return array{tipo: string, id: int|null, query: array<string, string>}
     */
    private function montarAtalho(string $tipo, mixed $id, array $periodo): array
    {
        $rota = match ($tipo) {
            self::TIPO_LOJA => 'lojas',
            self::TIPO_CATEGORIA => 'transacoes',
            self::TIPO_SUBCATEGORIA => 'transacoes',
            default => 'estabelecimentos',
        };

        $query = [
            'data_inicio' => (string) $periodo['inicio'],
            'data_fim' => (string) $periodo['fim'],
        ];
        if ($tipo === self::TIPO_CATEGORIA && $id) {
            $query['categoria_id'] = (string) $id;
        }
        if ($tipo === self::TIPO_SUBCATEGORIA && $id) {
            $query['subcategoria_id'] = (string) $id;
        }

        return [
            'rota' => $rota,
            'id' => $id,
            'query' => $query,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @return array<string, array{valor_total: float, compras: int}>
     */
    private function totaisPorMes(array $linhas): array
    {
        $mapa = [];
        foreach ($linhas as $linha) {
            $chave = Carbon::parse((string) $linha['data'])->format('Y-m');
            if (!isset($mapa[$chave])) {
                $mapa[$chave] = [
                    'valor_total' => 0.0,
                    'compras_chaves' => [],
                ];
            }
            $mapa[$chave]['valor_total'] += (float) $linha['valor'];
            $mapa[$chave]['compras_chaves'][$linha['compra_chave']] = true;
        }

        $out = [];
        foreach ($mapa as $chave => $bloco) {
            $out[$chave] = [
                'valor_total' => $bloco['valor_total'],
                'compras' => count($bloco['compras_chaves']),
            ];
        }

        return $out;
    }

    private function percentual(float $parte, float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($parte / $total) * 100, 1);
    }

    private function formatPct(float $valor): string
    {
        $n = abs($valor);
        $txt = $n == (int) $n
            ? (string) (int) $n
            : number_format($n, 1, ',', '.');

        return $txt . '%';
    }
}
