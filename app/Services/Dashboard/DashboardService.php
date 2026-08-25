<?php

namespace App\Services\Dashboard;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const MESES_LABEL = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    public function handleResumo(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $periodo = $this->resolverPeriodo($atributes);
            $ano = $periodo['ano'];
            $mesInicio = $periodo['mes_inicio_filtro'];
            $mesFim = $periodo['mes_fim_filtro'];

            return (object) [
                'data' => [
                    'periodo' => [
                        'ano' => $periodo['ano'],
                        'mes' => $periodo['mes'],
                        'mes_inicio' => $periodo['mes_inicio'],
                        'mes_fim' => $periodo['mes_fim'],
                        'tipo' => $periodo['tipo'],
                        'label' => $periodo['label'],
                        'meses' => $periodo['meses'],
                    ],
                    'totais' => $this->getTotaisGerais($userId, $ano, $mesInicio, $mesFim),
                    'por_mes' => $this->getTotaisPorMes($userId, $ano),
                    'por_categoria' => $this->getTotaisPorCategoria($userId, $ano, $mesInicio, $mesFim),
                    'por_responsavel' => $this->getTotaisPorResponsavel($userId, $ano, $mesInicio, $mesFim),
                    'por_cartao' => $this->getTotaisPorCartao($userId, $ano, $mesInicio, $mesFim),
                    'por_tipo' => $this->getTotaisPorTipo($userId, $ano, $mesInicio, $mesFim),
                ],
                'status' => true,
                'message' => 'Dashboard carregado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Recorte do resumo: ano todo, um mês ou intervalo dentro do mesmo ano.
     *
     * @return array{
     *     ano: int,
     *     mes: int|null,
     *     mes_inicio: int|null,
     *     mes_fim: int|null,
     *     mes_inicio_filtro: int|null,
     *     mes_fim_filtro: int|null,
     *     tipo: string,
     *     label: string,
     *     meses: array<int, int>
     * }
     */
    public function resolverPeriodo(object $atributes): array
    {
        $ano = (int) ($atributes->ano ?? date('Y'));
        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }

        $temInicio = $this->temValor($atributes, 'mes_inicio');
        $temFim = $this->temValor($atributes, 'mes_fim');

        if ($temInicio || $temFim) {
            $mesInicio = $temInicio ? (int) $atributes->mes_inicio : 1;
            $mesFim = $temFim ? (int) $atributes->mes_fim : 12;
            $this->assertMesValido($mesInicio, 'mes_inicio');
            $this->assertMesValido($mesFim, 'mes_fim');

            if ($mesFim < $mesInicio) {
                throw new Exception('mes_fim deve ser maior ou igual a mes_inicio', 422);
            }

            return $this->montarPeriodo($ano, $mesInicio, $mesFim);
        }

        if ($this->temValor($atributes, 'mes')) {
            $mes = (int) $atributes->mes;
            $this->assertMesValido($mes, 'mes');

            return $this->montarPeriodo($ano, $mes, $mes);
        }

        return $this->montarPeriodo($ano, 1, 12);
    }

    /**
     * @return array{
     *     ano: int,
     *     mes: int|null,
     *     mes_inicio: int|null,
     *     mes_fim: int|null,
     *     mes_inicio_filtro: int|null,
     *     mes_fim_filtro: int|null,
     *     tipo: string,
     *     label: string,
     *     meses: array<int, int>
     * }
     */
    private function montarPeriodo(int $ano, int $mesInicio, int $mesFim): array
    {
        $tipo = ($mesInicio === 1 && $mesFim === 12)
            ? 'ano'
            : ($mesInicio === $mesFim ? 'mes' : 'intervalo');

        $meses = range($mesInicio, $mesFim);

        return [
            'ano' => $ano,
            'mes' => $tipo === 'mes' ? $mesInicio : null,
            'mes_inicio' => $tipo === 'ano' ? null : $mesInicio,
            'mes_fim' => $tipo === 'ano' ? null : $mesFim,
            'mes_inicio_filtro' => $tipo === 'ano' ? null : $mesInicio,
            'mes_fim_filtro' => $tipo === 'ano' ? null : $mesFim,
            'tipo' => $tipo,
            'label' => $this->labelPeriodo($tipo, $ano, $mesInicio, $mesFim),
            'meses' => $meses,
        ];
    }

    public function labelPeriodo(string $tipo, int $ano, int $mesInicio, int $mesFim): string
    {
        if ($tipo === 'ano') {
            return (string) $ano;
        }

        if ($tipo === 'mes') {
            return self::MESES_LABEL[$mesInicio] . ' ' . $ano;
        }

        return self::MESES_LABEL[$mesInicio] . ' – ' . self::MESES_LABEL[$mesFim] . ' ' . $ano;
    }

    private function temValor(object $atributes, string $campo): bool
    {
        if (!isset($atributes->{$campo})) {
            return false;
        }

        $valor = $atributes->{$campo};

        return $valor !== '' && $valor !== null;
    }

    private function assertMesValido(int $mes, string $campo): void
    {
        if ($mes < 1 || $mes > 12) {
            throw new Exception($campo . ' deve ser um mês entre 1 e 12', 422);
        }
    }

    private function baseQuery(int $userId, int $ano, ?int $mesInicio, ?int $mesFim)
    {
        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('f.ano', $ano);

        $this->aplicarFiltroMes($query, $mesInicio, $mesFim);

        return $query;
    }

    private function baseFaturasQuery(int $userId, int $ano, ?int $mesInicio, ?int $mesFim)
    {
        $query = DB::table('faturas as f')
            ->whereNull('f.deleted_at')
            ->where('f.user_id', $userId)
            ->where('f.ano', $ano);

        $this->aplicarFiltroMes($query, $mesInicio, $mesFim);

        return $query;
    }

    private function aplicarFiltroMes($query, ?int $mesInicio, ?int $mesFim): void
    {
        if ($mesInicio === null || $mesFim === null) {
            return;
        }

        if ($mesInicio === $mesFim) {
            $query->where('f.mes', $mesInicio);

            return;
        }

        $query->whereBetween('f.mes', [$mesInicio, $mesFim]);
    }

    private function getTotaisGerais(int $userId, int $ano, ?int $mesInicio, ?int $mesFim): array
    {
        $row = $this->baseQuery($userId, $ano, $mesInicio, $mesFim)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN t.tipo = 'purchase' THEN t.valor ELSE 0 END), 0) as total_compras,
                COALESCE(SUM(CASE WHEN t.tipo = 'payment' THEN t.valor ELSE 0 END), 0) as total_pagamentos,
                COALESCE(SUM(CASE WHEN t.tipo = 'refund' THEN t.valor ELSE 0 END), 0) as total_estornos,
                COALESCE(SUM(CASE WHEN t.tipo = 'advance' THEN t.valor ELSE 0 END), 0) as total_antecipacoes,
                COALESCE(SUM(CASE WHEN t.tipo = 'fee' THEN t.valor ELSE 0 END), 0) as total_encargos,
                COUNT(*) as total_transacoes
            ")
            ->first();

        $compras = (float) ($row->total_compras ?? 0);
        $pagamentos = (float) ($row->total_pagamentos ?? 0);
        $estornos = (float) ($row->total_estornos ?? 0);
        $antecipacoes = (float) ($row->total_antecipacoes ?? 0);
        $encargos = (float) ($row->total_encargos ?? 0);

        $totalLiquido = (float) ($this->baseFaturasQuery($userId, $ano, $mesInicio, $mesFim)
            ->selectRaw('COALESCE(SUM(f.valor_total), 0) as total')
            ->value('total') ?? 0);

        return [
            'total_compras' => round($compras, 2),
            'total_pagamentos' => round($pagamentos, 2),
            'total_estornos' => round($estornos, 2),
            'total_antecipacoes' => round($antecipacoes, 2),
            'total_encargos' => round($encargos, 2),
            'total_liquido' => round($totalLiquido, 2),
            'total_transacoes' => (int) ($row->total_transacoes ?? 0),
        ];
    }

    private function getTotaisPorMes(int $userId, int $ano): array
    {
        return $this->baseFaturasQuery($userId, $ano, null, null)
            ->selectRaw('f.mes, COALESCE(SUM(f.valor_total), 0) as total')
            ->groupBy('f.mes')
            ->orderBy('f.mes')
            ->get()
            ->map(fn ($item) => [
                'mes' => (int) $item->mes,
                'total' => round((float) $item->total, 2),
            ])
            ->toArray();
    }

    private function getTotaisPorCategoria(int $userId, int $ano, ?int $mesInicio, ?int $mesFim): array
    {
        return $this->baseQuery($userId, $ano, $mesInicio, $mesFim)
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 't.categoria_id')->whereNull('cat.deleted_at');
            })
            ->where('t.tipo', 'purchase')
            ->selectRaw("
                t.categoria_id,
                COALESCE(cat.nome, 'Sem categoria') as nome,
                cat.cor,
                COALESCE(SUM(t.valor), 0) as total,
                COUNT(*) as quantidade
            ")
            ->groupBy('t.categoria_id', 'cat.nome', 'cat.cor')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($item) => [
                'categoria_id' => $item->categoria_id,
                'nome' => $item->nome,
                'cor' => $item->cor,
                'total' => round((float) $item->total, 2),
                'quantidade' => (int) $item->quantidade,
            ])
            ->toArray();
    }

    private function getTotaisPorResponsavel(int $userId, int $ano, ?int $mesInicio, ?int $mesFim): array
    {
        return $this->baseQuery($userId, $ano, $mesInicio, $mesFim)
            ->leftJoin('responsaveis as resp', function ($join) {
                $join->on('resp.id', '=', 't.responsavel_id')->whereNull('resp.deleted_at');
            })
            ->where('t.tipo', 'purchase')
            ->selectRaw("
                t.responsavel_id,
                COALESCE(resp.nome, 'Sem responsável') as nome,
                resp.tipo,
                COALESCE(SUM(t.valor), 0) as total,
                COUNT(*) as quantidade
            ")
            ->groupBy('t.responsavel_id', 'resp.nome', 'resp.tipo')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($item) => [
                'responsavel_id' => $item->responsavel_id,
                'nome' => $item->nome,
                'tipo' => $item->tipo,
                'total' => round((float) $item->total, 2),
                'quantidade' => (int) $item->quantidade,
            ])
            ->toArray();
    }

    private function getTotaisPorCartao(int $userId, int $ano, ?int $mesInicio, ?int $mesFim): array
    {
        return $this->baseFaturasQuery($userId, $ano, $mesInicio, $mesFim)
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->selectRaw("
                c.id as cartao_id,
                COALESCE(c.nome, 'Sem cartão') as nome,
                c.cor_fundo,
                c.cor_texto,
                COALESCE(SUM(f.valor_total), 0) as total,
                COUNT(*) as quantidade
            ")
            ->groupBy('c.id', 'c.nome', 'c.cor_fundo', 'c.cor_texto')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($item) => [
                'cartao_id' => $item->cartao_id,
                'nome' => $item->nome,
                'cor_fundo' => $item->cor_fundo,
                'cor_texto' => $item->cor_texto,
                'total' => round((float) $item->total, 2),
                'quantidade' => (int) $item->quantidade,
            ])
            ->toArray();
    }

    private function getTotaisPorTipo(int $userId, int $ano, ?int $mesInicio, ?int $mesFim): array
    {
        return $this->baseQuery($userId, $ano, $mesInicio, $mesFim)
            ->selectRaw('t.tipo, COALESCE(SUM(t.valor), 0) as total, COUNT(*) as quantidade')
            ->groupBy('t.tipo')
            ->orderBy('t.tipo')
            ->get()
            ->map(fn ($item) => [
                'tipo' => $item->tipo,
                'total' => round((float) $item->total, 2),
                'quantidade' => (int) $item->quantidade,
            ])
            ->toArray();
    }
}
