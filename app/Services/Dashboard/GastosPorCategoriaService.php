<?php

namespace App\Services\Dashboard;

use App\Models\Transacao;
use App\Services\Categoria\CategoriaCoresTema;
use App\Services\Estabelecimento\EstabelecimentoEstatisticasService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GastosPorCategoriaService
{
    public const TOP_SUBCATEGORIAS = 2;
    public const TOP_DASHBOARD = 10;
    public const TOP_CATEGORIAS_EVOLUCAO = 5;

    private GastosCriticosService $gastosCriticos;

    private EstabelecimentoEstatisticasService $estatisticas;

    public function __construct(
        ?GastosCriticosService $gastosCriticos = null,
        ?EstabelecimentoEstatisticasService $estatisticas = null
    ) {
        $this->gastosCriticos = $gastosCriticos ?? new GastosCriticosService();
        $this->estatisticas = $estatisticas ?? new EstabelecimentoEstatisticasService();
    }

    public function handleGastosPorCategoria(object $atributes): object
    {
        try {
            $userId = (int) Auth::id();
            $periodos = $this->gastosCriticos->resolverPeriodos($atributes);
            $periodo = $periodos['atual'];
            $anterior = $periodos['anterior'];

            $linhasAtual = $this->mapLinhas($this->loadCompras($userId, $periodo['inicio'], $periodo['fim'], $atributes));
            $linhasAnterior = $this->mapLinhas($this->loadCompras($userId, $anterior['inicio'], $anterior['fim'], $atributes));

            $totais = $this->montarTotais($linhasAtual, $linhasAnterior, $periodo);
            $categorias = $this->montarCategorias($linhasAtual, $linhasAnterior, $periodo, $totais);
            $subcategorias = $this->montarSubcategorias($categorias);
            $porOrigem = $this->montarPorOrigem($linhasAtual, $linhasAnterior, $periodo, $totais);
            $destaque = $this->montarDestaque($categorias, $periodo);

            return (object) [
                'data' => [
                    'periodo' => $periodo,
                    'periodo_anterior' => $anterior,
                    'totais' => $totais,
                    'destaque' => $destaque,
                    'dashboards' => $this->montarDashboards($categorias, $subcategorias),
                    'categorias' => $categorias,
                    'subcategorias' => $subcategorias,
                    'por_origem' => $porOrigem,
                    'evolucao' => [
                        'por_mes' => $this->gastosCriticos->montarEvolucao($linhasAtual, $linhasAnterior, $periodo),
                        'por_categoria' => $this->montarEvolucaoPorCategoria($linhasAtual, $categorias, $periodo),
                    ],
                ],
                'status' => true,
                'message' => 'Gastos por categoria carregados com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $linhasAnterior
     * @param array<string, mixed> $periodo
     * @return array<string, mixed>
     */
    public function montarTotais(array $linhas, array $linhasAnterior, array $periodo): array
    {
        $atual = $this->resumoLinhas($linhas);
        $anterior = $this->resumoLinhas($linhasAnterior);
        $semCategoria = $this->resumoLinhas(array_values(array_filter(
            $linhas,
            fn (array $linha) => empty($linha['categoria_id'])
        )));

        $categoriasComGasto = [];
        foreach ($linhas as $linha) {
            $id = $linha['categoria_id'] !== null ? (int) $linha['categoria_id'] : 0;
            $categoriasComGasto[$id] = true;
        }

        return [
            'valor_total' => $atual['valor_total'],
            'compras' => $atual['compras'],
            'ocorrencias' => $atual['ocorrencias'],
            'ticket_medio' => $atual['compras'] > 0
                ? round($atual['valor_total'] / $atual['compras'], 2)
                : 0.0,
            'categorias_com_gasto' => count($categoriasComGasto),
            'valor_anterior' => $anterior['valor_total'],
            'compras_anterior' => $anterior['compras'],
            'variacao_valor_percentual' => $this->gastosCriticos->variacaoPercentual(
                $atual['valor_total'],
                $anterior['valor_total']
            ),
            'variacao_compras_percentual' => $this->gastosCriticos->variacaoPercentual(
                (float) $atual['compras'],
                (float) $anterior['compras']
            ),
            'frequencia' => $this->estatisticas->buildFrequencia($atual['compras'], (int) $periodo['dias']),
            'sem_categoria' => [
                'valor_total' => $semCategoria['valor_total'],
                'compras' => $semCategoria['compras'],
                'ocorrencias' => $semCategoria['ocorrencias'],
                'percentual_gasto' => $this->percentual($semCategoria['valor_total'], $atual['valor_total']),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $linhasAnterior
     * @param array<string, mixed> $periodo
     * @param array<string, mixed> $totais
     * @return array<int, array<string, mixed>>
     */
    public function montarCategorias(array $linhas, array $linhasAnterior, array $periodo, array $totais): array
    {
        $grupos = $this->agregarCategorias($linhas, $periodo, $totais);
        $anteriorMapa = [];
        foreach ($this->agregarCategorias($linhasAnterior, $periodo, [
            'valor_total' => 0.0,
            'compras' => 0,
        ]) as $item) {
            $anteriorMapa[$item['chave']] = $item;
        }

        $itens = [];
        foreach ($grupos as $item) {
            $anterior = $anteriorMapa[$item['chave']] ?? null;
            $item['valor_anterior'] = (float) ($anterior['valor_total'] ?? 0);
            $item['compras_anterior'] = (int) ($anterior['compras'] ?? 0);
            $item['variacao_valor_percentual'] = $this->gastosCriticos->variacaoPercentual(
                (float) $item['valor_total'],
                $item['valor_anterior']
            );
            $item['variacao_compras_percentual'] = $this->gastosCriticos->variacaoPercentual(
                (float) $item['compras'],
                (float) $item['compras_anterior']
            );
            $item['frase'] = $this->fraseCategoria($item, $periodo);
            $itens[] = $item;
        }

        return $itens;
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<string, mixed> $periodo
     * @param array<string, mixed> $totais
     * @return array<int, array<string, mixed>>
     */
    public function agregarCategorias(array $linhas, array $periodo, array $totais): array
    {
        $grupos = [];
        foreach ($linhas as $linha) {
            $categoriaId = $linha['categoria_id'] !== null ? (int) $linha['categoria_id'] : 0;
            $chave = $categoriaId > 0 ? 'categoria-' . $categoriaId : 'categoria-0';

            if (!isset($grupos[$chave])) {
                $nome = $categoriaId > 0 ? (string) $linha['categoria_nome'] : 'Sem categoria';
                $grupos[$chave] = [
                    'chave' => $chave,
                    'categoria_id' => $categoriaId > 0 ? $categoriaId : null,
                    'nome' => $nome,
                    'cor' => CategoriaCoresTema::corParaGrafico(
                        $linha['categoria_cor'] ?? null,
                        $categoriaId > 0 ? $categoriaId : null
                    ),
                    'compras_chaves' => [],
                    'ocorrencias' => 0,
                    'valor_total' => 0.0,
                    'subcategorias' => [],
                    'origens' => [],
                ];
            }

            $grupos[$chave]['ocorrencias']++;
            $grupos[$chave]['valor_total'] += (float) $linha['valor'];
            $grupos[$chave]['compras_chaves'][$linha['compra_chave']] = true;
            $this->acumularSubcategoria($grupos[$chave]['subcategorias'], $linha);
            $this->acumularOrigem($grupos[$chave]['origens'], $linha);
        }

        $totalValor = (float) ($totais['valor_total'] ?? 0);
        $totalCompras = (int) ($totais['compras'] ?? 0);
        $itens = [];
        foreach ($grupos as $grupo) {
            $compras = count($grupo['compras_chaves']);
            $valor = round((float) $grupo['valor_total'], 2);
            $subcategorias = $this->finalizarSubcategorias($grupo['subcategorias'], $valor, $compras, $periodo);
            $todasSubs = $this->anexarCategoriaNasSubs($subcategorias['com_nome'], $grupo, $totalValor);
            $top = array_slice($todasSubs, 0, self::TOP_SUBCATEGORIAS);
            $resto = array_slice($todasSubs, self::TOP_SUBCATEGORIAS);
            $origens = $grupo['origens'];

            unset($grupo['compras_chaves'], $grupo['subcategorias'], $grupo['origens']);

            $grupo['compras'] = $compras;
            $grupo['valor_total'] = $valor;
            $grupo['ticket_medio'] = $compras > 0 ? round($valor / $compras, 2) : 0.0;
            $grupo['percentual_gasto'] = $this->percentual($valor, $totalValor);
            $grupo['percentual_compras'] = $this->percentual((float) $compras, (float) $totalCompras);
            $grupo['frequencia'] = $this->estatisticas->buildFrequencia($compras, (int) $periodo['dias']);
            $grupo['subcategorias_total'] = count($todasSubs);
            $grupo['subcategorias'] = $todasSubs;
            $grupo['top_subcategorias'] = $top;
            $grupo['outras_subcategorias'] = $this->resumoResto($resto, $valor);
            $grupo['sem_subcategoria'] = $subcategorias['sem_nome'];
            $grupo['por_origem'] = $this->finalizarOrigens($origens, $valor, $compras, $periodo);
            $grupo['atalho'] = $this->montarAtalho('categoria', $grupo['categoria_id'], $periodo);
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
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $linhasAnterior
     * @param array<string, mixed> $periodo
     * @param array<string, mixed> $totais
     * @return array<int, array<string, mixed>>
     */
    public function montarPorOrigem(array $linhas, array $linhasAnterior, array $periodo, array $totais): array
    {
        $atual = [];
        $this->acumularOrigensDasLinhas($atual, $linhas);
        $anterior = [];
        $this->acumularOrigensDasLinhas($anterior, $linhasAnterior);

        $itens = $this->finalizarOrigens(
            $atual,
            (float) $totais['valor_total'],
            (int) $totais['compras'],
            $periodo
        );

        foreach ($itens as &$item) {
            $prev = $anterior[$item['chave']] ?? null;
            $item['valor_anterior'] = round((float) ($prev['valor_total'] ?? 0), 2);
            $item['compras_anterior'] = isset($prev['compras_chaves']) ? count($prev['compras_chaves']) : 0;
            $item['variacao_valor_percentual'] = $this->gastosCriticos->variacaoPercentual(
                (float) $item['valor_total'],
                $item['valor_anterior']
            );
            $item['frase'] = $this->fraseOrigem($item, $periodo);
        }
        unset($item);

        return $itens;
    }

    /**
     * @param array<int, array<string, mixed>> $categorias
     * @param array<string, mixed> $periodo
     * @return array<string, mixed>|null
     */
    public function montarDestaque(array $categorias, array $periodo): ?array
    {
        $principal = null;
        foreach ($categorias as $categoria) {
            if ($categoria['categoria_id'] !== null) {
                $principal = $categoria;
                break;
            }
        }

        if ($principal === null) {
            $principal = $categorias[0] ?? null;
        }

        if ($principal === null) {
            return null;
        }

        $subs = $principal['top_subcategorias'];
        $nomesSubs = array_map(fn (array $sub) => $sub['nome'], $subs);
        $brl = $this->gastosCriticos->formatBrl((float) $principal['valor_total']);
        $pct = $this->formatPct((float) $principal['percentual_gasto']);
        $quando = $periodo['label_frase'];

        $frase = "Você mais gastou em {$principal['nome']} {$quando}: {$brl} ({$pct} do total).";
        if (count($nomesSubs) === 2) {
            $frase .= " As duas maiores fatias são {$nomesSubs[0]} e {$nomesSubs[1]}.";
        } elseif (count($nomesSubs) === 1) {
            $frase .= " A maior fatia é {$nomesSubs[0]}.";
        }

        return [
            'categoria' => [
                'categoria_id' => $principal['categoria_id'],
                'nome' => $principal['nome'],
                'cor' => $principal['cor'],
                'valor_total' => $principal['valor_total'],
                'compras' => $principal['compras'],
                'percentual_gasto' => $principal['percentual_gasto'],
                'variacao_valor_percentual' => $principal['variacao_valor_percentual'],
                'atalho' => $principal['atalho'],
            ],
            'subcategorias' => $subs,
            'frase' => $frase,
        ];
    }

    /**
     * Lista plana de subcategorias nomeadas, com a categoria pai, para o gráfico escravo.
     *
     * @param array<int, array<string, mixed>> $categorias
     * @return array<int, array<string, mixed>>
     */
    public function montarSubcategorias(array $categorias): array
    {
        $itens = [];
        foreach ($categorias as $categoria) {
            foreach ($categoria['subcategorias'] ?? [] as $sub) {
                $itens[] = $sub;
            }
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
     * Snapshots das duas pizzas (top 10). O clique no front filtra `subcategorias` no cliente.
     *
     * @param array<int, array<string, mixed>> $categorias
     * @param array<int, array<string, mixed>> $subcategorias
     * @return array<string, mixed>
     */
    public function montarDashboards(array $categorias, array $subcategorias): array
    {
        return [
            'limite' => self::TOP_DASHBOARD,
            'categorias' => array_slice($this->barrasCategoria($categorias), 0, self::TOP_DASHBOARD),
            'subcategorias' => array_slice($this->barrasSubcategoria($subcategorias), 0, self::TOP_DASHBOARD),
        ];
    }

    /**
     * Top N subcategorias de uma categoria (filtro cruzado no cliente; espelha a regra do gráfico escravo).
     *
     * @param array<int, array<string, mixed>> $subcategorias
     * @return array<int, array<string, mixed>>
     */
    public function filtrarSubcategoriasPorCategoria(array $subcategorias, ?int $categoriaId): array
    {
        $filtradas = $subcategorias;
        if ($categoriaId !== null) {
            $filtradas = array_values(array_filter(
                $subcategorias,
                fn (array $sub) => ($sub['categoria_id'] ?? null) === $categoriaId
            ));
        }

        return array_slice($this->barrasSubcategoria($filtradas), 0, self::TOP_DASHBOARD);
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>> $categorias
     * @param array<string, mixed> $periodo
     * @return array<int, array<string, mixed>>
     */
    public function montarEvolucaoPorCategoria(array $linhas, array $categorias, array $periodo): array
    {
        $top = array_slice($categorias, 0, self::TOP_CATEGORIAS_EVOLUCAO);
        if ($top === []) {
            return [];
        }

        $inicio = Carbon::parse((string) $periodo['inicio'])->startOfMonth();
        $fim = Carbon::parse((string) $periodo['fim'])->startOfMonth();
        $chavesMes = [];
        $cursor = $inicio->copy();
        while ($cursor->lte($fim)) {
            $chavesMes[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        $series = [];
        foreach ($top as $categoria) {
            $mapa = [];
            foreach ($linhas as $linha) {
                $id = $linha['categoria_id'] !== null ? (int) $linha['categoria_id'] : 0;
                $esperado = $categoria['categoria_id'] !== null ? (int) $categoria['categoria_id'] : 0;
                if ($id !== $esperado) {
                    continue;
                }
                $chave = Carbon::parse((string) $linha['data'])->format('Y-m');
                if (!isset($mapa[$chave])) {
                    $mapa[$chave] = ['valor_total' => 0.0, 'compras_chaves' => []];
                }
                $mapa[$chave]['valor_total'] += (float) $linha['valor'];
                $mapa[$chave]['compras_chaves'][$linha['compra_chave']] = true;
            }

            $serie = [];
            foreach ($chavesMes as $chave) {
                $bloco = $mapa[$chave] ?? ['valor_total' => 0.0, 'compras_chaves' => []];
                $serie[] = [
                    'chave' => $chave,
                    'valor_total' => round((float) $bloco['valor_total'], 2),
                    'compras' => count($bloco['compras_chaves']),
                ];
            }

            $series[] = [
                'categoria_id' => $categoria['categoria_id'],
                'nome' => $categoria['nome'],
                'cor' => $categoria['cor'],
                'serie' => $serie,
            ];
        }

        return $series;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    public function mapLinhas(Collection $rows): array
    {
        return $rows->map(function (object $row) {
            $grupoId = $row->compra_grupo_id ?? null;
            $origem = $row->origem_compra ?? null;

            return [
                'id' => (int) $row->id,
                'compra_chave' => !empty($grupoId) ? (string) $grupoId : ('av-' . (int) $row->id),
                'data' => Carbon::parse((string) $row->data)->toDateString(),
                'valor' => (float) $row->valor,
                'categoria_id' => $row->categoria_id !== null ? (int) $row->categoria_id : null,
                'categoria_nome' => $row->categoria_nome !== null ? (string) $row->categoria_nome : 'Sem categoria',
                'categoria_cor' => CategoriaCoresTema::corParaGrafico(
                    $row->categoria_cor,
                    $row->categoria_id
                ),
                'subcategoria_id' => $row->subcategoria_id !== null ? (int) $row->subcategoria_id : null,
                'subcategoria_nome' => $row->subcategoria_nome !== null ? (string) $row->subcategoria_nome : null,
                'origem_compra' => $origem !== null && $origem !== '' ? (string) $origem : null,
            ];
        })->values()->all();
    }

    /**
     * @param array<string, array<string, mixed>> $grupos
     * @param array<string, mixed> $linha
     */
    private function acumularSubcategoria(array &$grupos, array $linha): void
    {
        $id = $linha['subcategoria_id'] !== null ? (int) $linha['subcategoria_id'] : 0;
        $chave = $id > 0 ? 'subcategoria-' . $id : 'subcategoria-0';

        if (!isset($grupos[$chave])) {
            $grupos[$chave] = [
                'chave' => $chave,
                'subcategoria_id' => $id > 0 ? $id : null,
                'nome' => $id > 0 ? (string) $linha['subcategoria_nome'] : 'Sem subcategoria',
                'compras_chaves' => [],
                'ocorrencias' => 0,
                'valor_total' => 0.0,
            ];
        }

        $grupos[$chave]['ocorrencias']++;
        $grupos[$chave]['valor_total'] += (float) $linha['valor'];
        $grupos[$chave]['compras_chaves'][$linha['compra_chave']] = true;
    }

    /**
     * @param array<string, array<string, mixed>> $grupos
     * @param array<string, mixed> $linha
     */
    private function acumularOrigem(array &$grupos, array $linha): void
    {
        $origem = $linha['origem_compra'] ?? null;
        $chave = $origem ?: 'sem-origem';

        if (!isset($grupos[$chave])) {
            $grupos[$chave] = [
                'chave' => $chave,
                'origem_compra' => $origem,
                'label' => $origem && isset(Transacao::ORIGENS_COMPRA_LABELS[$origem])
                    ? Transacao::ORIGENS_COMPRA_LABELS[$origem]
                    : 'Sem origem',
                'compras_chaves' => [],
                'ocorrencias' => 0,
                'valor_total' => 0.0,
            ];
        }

        $grupos[$chave]['ocorrencias']++;
        $grupos[$chave]['valor_total'] += (float) $linha['valor'];
        $grupos[$chave]['compras_chaves'][$linha['compra_chave']] = true;
    }

    /**
     * @param array<string, array<string, mixed>> $grupos
     * @param array<int, array<string, mixed>> $linhas
     */
    private function acumularOrigensDasLinhas(array &$grupos, array $linhas): void
    {
        foreach ($linhas as $linha) {
            $this->acumularOrigem($grupos, $linha);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $grupos
     * @param array<string, mixed> $periodo
     * @return array{com_nome: array<int, array<string, mixed>>, sem_nome: array<string, mixed>}
     */
    private function finalizarSubcategorias(array $grupos, float $valorCategoria, int $comprasCategoria, array $periodo): array
    {
        $comNome = [];
        $semNome = [
            'valor_total' => 0.0,
            'compras' => 0,
            'ocorrencias' => 0,
            'percentual_da_categoria' => 0.0,
        ];

        foreach ($grupos as $grupo) {
            $compras = count($grupo['compras_chaves']);
            $valor = round((float) $grupo['valor_total'], 2);
            $item = [
                'chave' => $grupo['chave'],
                'subcategoria_id' => $grupo['subcategoria_id'],
                'nome' => $grupo['nome'],
                'compras' => $compras,
                'ocorrencias' => (int) $grupo['ocorrencias'],
                'valor_total' => $valor,
                'ticket_medio' => $compras > 0 ? round($valor / $compras, 2) : 0.0,
                'percentual_da_categoria' => $this->percentual($valor, $valorCategoria),
                'percentual_compras_da_categoria' => $this->percentual((float) $compras, (float) $comprasCategoria),
                'frequencia' => $this->estatisticas->buildFrequencia($compras, (int) $periodo['dias']),
                'atalho' => $this->montarAtalho('subcategoria', $grupo['subcategoria_id'], $periodo),
            ];

            if ($grupo['subcategoria_id'] === null) {
                $semNome = [
                    'valor_total' => $valor,
                    'compras' => $compras,
                    'ocorrencias' => (int) $grupo['ocorrencias'],
                    'percentual_da_categoria' => $item['percentual_da_categoria'],
                ];
                continue;
            }

            $comNome[] = $item;
        }

        usort($comNome, function (array $a, array $b) {
            $cmp = $b['valor_total'] <=> $a['valor_total'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['compras'] <=> $a['compras'];
        });

        return [
            'com_nome' => array_values($comNome),
            'sem_nome' => $semNome,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $subs
     * @param array<string, mixed> $categoria
     * @return array<int, array<string, mixed>>
     */
    private function anexarCategoriaNasSubs(array $subs, array $categoria, float $totalValor): array
    {
        return array_map(function (array $sub) use ($categoria, $totalValor) {
            $sub['categoria_id'] = $categoria['categoria_id'];
            $sub['categoria_nome'] = $categoria['nome'];
            $sub['categoria_cor'] = $categoria['cor'] ?? null;
            $sub['percentual_gasto'] = $this->percentual((float) $sub['valor_total'], $totalValor);

            return $sub;
        }, $subs);
    }

    /**
     * @param array<int, array<string, mixed>> $categorias
     * @return array<int, array<string, mixed>>
     */
    private function barrasCategoria(array $categorias): array
    {
        return array_map(fn (array $item) => [
            'chave' => $item['chave'],
            'categoria_id' => $item['categoria_id'],
            'nome' => $item['nome'],
            'cor' => $item['cor'],
            'valor_total' => $item['valor_total'],
            'compras' => $item['compras'],
            'percentual_gasto' => $item['percentual_gasto'],
            'atalho' => $item['atalho'],
        ], $categorias);
    }

    /**
     * @param array<int, array<string, mixed>> $subcategorias
     * @return array<int, array<string, mixed>>
     */
    private function barrasSubcategoria(array $subcategorias): array
    {
        return array_map(fn (array $item) => [
            'chave' => $item['chave'],
            'subcategoria_id' => $item['subcategoria_id'],
            'nome' => $item['nome'],
            'categoria_id' => $item['categoria_id'] ?? null,
            'categoria_nome' => $item['categoria_nome'] ?? null,
            'categoria_cor' => $item['categoria_cor'] ?? null,
            'valor_total' => $item['valor_total'],
            'compras' => $item['compras'],
            'percentual_gasto' => $item['percentual_gasto'] ?? 0.0,
            'percentual_da_categoria' => $item['percentual_da_categoria'] ?? 0.0,
            'atalho' => $item['atalho'],
        ], $subcategorias);
    }

    /**
     * @param array<int, array<string, mixed>> $resto
     * @return array<string, mixed>
     */
    private function resumoResto(array $resto, float $valorCategoria): array
    {
        $valor = 0.0;
        $compras = 0;
        foreach ($resto as $item) {
            $valor += (float) $item['valor_total'];
            $compras += (int) $item['compras'];
        }

        return [
            'quantidade' => count($resto),
            'valor_total' => round($valor, 2),
            'compras' => $compras,
            'percentual_da_categoria' => $this->percentual($valor, $valorCategoria),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $grupos
     * @param array<string, mixed> $periodo
     * @return array<int, array<string, mixed>>
     */
    private function finalizarOrigens(array $grupos, float $totalValor, int $totalCompras, array $periodo): array
    {
        $itens = [];
        foreach ($grupos as $grupo) {
            $compras = count($grupo['compras_chaves']);
            $valor = round((float) $grupo['valor_total'], 2);
            $itens[] = [
                'chave' => $grupo['chave'],
                'origem_compra' => $grupo['origem_compra'],
                'label' => $grupo['label'],
                'compras' => $compras,
                'ocorrencias' => (int) $grupo['ocorrencias'],
                'valor_total' => $valor,
                'ticket_medio' => $compras > 0 ? round($valor / $compras, 2) : 0.0,
                'percentual_gasto' => $this->percentual($valor, $totalValor),
                'percentual_compras' => $this->percentual((float) $compras, (float) $totalCompras),
                'percentual_da_categoria' => $this->percentual($valor, $totalValor),
                'frequencia' => $this->estatisticas->buildFrequencia($compras, (int) $periodo['dias']),
                'atalho' => $this->montarAtalhoOrigem($grupo['origem_compra'], $periodo),
            ];
        }

        usort($itens, function (array $a, array $b) {
            return $b['valor_total'] <=> $a['valor_total'];
        });

        return array_values($itens);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseCategoria(array $item, array $periodo): string
    {
        $brl = $this->gastosCriticos->formatBrl((float) $item['valor_total']);
        $pct = $this->formatPct((float) $item['percentual_gasto']);
        $quando = $periodo['label_frase'];
        $subs = $item['top_subcategorias'] ?? [];

        $frase = "Você gastou {$brl} em {$item['nome']} {$quando} — {$pct} do total.";
        if (count($subs) >= 2) {
            $frase .= " Destaques: {$subs[0]['nome']} e {$subs[1]['nome']}.";
        } elseif (count($subs) === 1) {
            $frase .= " Destaque: {$subs[0]['nome']}.";
        }

        return $frase;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $periodo
     */
    public function fraseOrigem(array $item, array $periodo): string
    {
        $brl = $this->gastosCriticos->formatBrl((float) $item['valor_total']);
        $pct = $this->formatPct((float) $item['percentual_gasto']);
        $quando = $periodo['label_frase'];
        $onde = mb_strtolower((string) $item['label']);

        return "Você gastou {$brl} em {$onde} {$quando} — {$pct} do total.";
    }

    public function formatPct(float $valor): string
    {
        $n = abs($valor);
        $txt = $n == (int) $n
            ? (string) (int) $n
            : number_format($n, 1, ',', '.');

        return $txt . '%';
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

    private function percentual(float $parte, float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($parte / $total) * 100, 1);
    }

    /**
     * @param array<string, mixed> $periodo
     * @return array{rota: string, id: mixed, query: array<string, string>}
     */
    private function montarAtalho(string $tipo, mixed $id, array $periodo): array
    {
        $query = [
            'data_inicio' => (string) $periodo['inicio'],
            'data_fim' => (string) $periodo['fim'],
        ];
        if ($tipo === 'categoria' && $id) {
            $query['categoria_id'] = (string) $id;
        }
        if ($tipo === 'subcategoria' && $id) {
            $query['subcategoria_id'] = (string) $id;
        }

        return [
            'rota' => 'transacoes',
            'id' => $id,
            'query' => $query,
        ];
    }

    /**
     * @param array<string, mixed> $periodo
     * @return array{rota: string, id: mixed, query: array<string, string>}
     */
    private function montarAtalhoOrigem(?string $origem, array $periodo): array
    {
        $query = [
            'data_inicio' => (string) $periodo['inicio'],
            'data_fim' => (string) $periodo['fim'],
        ];
        if ($origem) {
            $query['origem_compra'] = $origem;
        }

        return [
            'rota' => 'transacoes',
            'id' => null,
            'query' => $query,
        ];
    }

    private function loadCompras(int $userId, string $inicio, string $fim, object $atributes): Collection
    {
        $query = DB::table('transacoes as t')
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

        if (!empty($atributes->origem_compra)) {
            if (!in_array($atributes->origem_compra, Transacao::ORIGENS_COMPRA, true)) {
                throw new Exception('Origem da compra inválida', 422);
            }
            $query->where('t.origem_compra', $atributes->origem_compra);
        }

        return $query->select([
            't.id',
            't.compra_grupo_id',
            't.data',
            't.valor',
            't.origem_compra',
            't.categoria_id',
            'cat.nome as categoria_nome',
            'cat.cor as categoria_cor',
            't.subcategoria_id',
            'sub.nome as subcategoria_nome',
        ])->get();
    }
}
