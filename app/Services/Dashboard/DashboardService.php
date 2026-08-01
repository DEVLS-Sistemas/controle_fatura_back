<?php

namespace App\Services\Dashboard;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function handleResumo(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $ano = (int) ($atributes->ano ?? date('Y'));
            $mes = !empty($atributes->mes) ? (int) $atributes->mes : null;

            return (object) [
                'data' => [
                    'periodo' => [
                        'ano' => $ano,
                        'mes' => $mes,
                    ],
                    'totais' => $this->getTotaisGerais($userId, $ano, $mes),
                    'por_mes' => $this->getTotaisPorMes($userId, $ano),
                    'por_categoria' => $this->getTotaisPorCategoria($userId, $ano, $mes),
                    'por_responsavel' => $this->getTotaisPorResponsavel($userId, $ano, $mes),
                    'por_cartao' => $this->getTotaisPorCartao($userId, $ano, $mes),
                    'por_tipo' => $this->getTotaisPorTipo($userId, $ano, $mes),
                ],
                'status' => true,
                'message' => 'Dashboard carregado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function baseQuery(int $userId, int $ano, ?int $mes)
    {
        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('f.ano', $ano);

        if ($mes) {
            $query->where('f.mes', $mes);
        }

        return $query;
    }

    private function baseFaturasQuery(int $userId, int $ano, ?int $mes)
    {
        $query = DB::table('faturas as f')
            ->whereNull('f.deleted_at')
            ->where('f.user_id', $userId)
            ->where('f.ano', $ano);

        if ($mes) {
            $query->where('f.mes', $mes);
        }

        return $query;
    }

    private function getTotaisGerais(int $userId, int $ano, ?int $mes): array
    {
        $row = $this->baseQuery($userId, $ano, $mes)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN t.tipo = 'purchase' THEN t.valor ELSE 0 END), 0) as total_compras,
                COALESCE(SUM(CASE WHEN t.tipo = 'payment' THEN t.valor ELSE 0 END), 0) as total_pagamentos,
                COALESCE(SUM(CASE WHEN t.tipo = 'refund' THEN t.valor ELSE 0 END), 0) as total_estornos,
                COALESCE(SUM(CASE WHEN t.tipo = 'advance' THEN t.valor ELSE 0 END), 0) as total_antecipacoes,
                COUNT(*) as total_transacoes
            ")
            ->first();

        $compras = (float) ($row->total_compras ?? 0);
        $pagamentos = (float) ($row->total_pagamentos ?? 0);
        $estornos = (float) ($row->total_estornos ?? 0);
        $antecipacoes = (float) ($row->total_antecipacoes ?? 0);

        $totalLiquido = (float) ($this->baseFaturasQuery($userId, $ano, $mes)
            ->selectRaw('COALESCE(SUM(f.valor_total), 0) as total')
            ->value('total') ?? 0);

        return [
            'total_compras' => round($compras, 2),
            'total_pagamentos' => round($pagamentos, 2),
            'total_estornos' => round($estornos, 2),
            'total_antecipacoes' => round($antecipacoes, 2),
            'total_liquido' => round($totalLiquido, 2),
            'total_transacoes' => (int) ($row->total_transacoes ?? 0),
        ];
    }

    private function getTotaisPorMes(int $userId, int $ano): array
    {
        return $this->baseFaturasQuery($userId, $ano, null)
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

    private function getTotaisPorCategoria(int $userId, int $ano, ?int $mes): array
    {
        return $this->baseQuery($userId, $ano, $mes)
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

    private function getTotaisPorResponsavel(int $userId, int $ano, ?int $mes): array
    {
        return $this->baseQuery($userId, $ano, $mes)
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

    private function getTotaisPorCartao(int $userId, int $ano, ?int $mes): array
    {
        return $this->baseFaturasQuery($userId, $ano, $mes)
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->selectRaw("
                c.id as cartao_id,
                COALESCE(c.nome, 'Sem cartão') as nome,
                c.bandeira,
                c.ultimos_digitos,
                c.cor_fundo,
                c.cor_texto,
                COALESCE(SUM(f.valor_total), 0) as total,
                COUNT(*) as quantidade
            ")
            ->groupBy('c.id', 'c.nome', 'c.bandeira', 'c.ultimos_digitos', 'c.cor_fundo', 'c.cor_texto')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($item) => [
                'cartao_id' => $item->cartao_id,
                'nome' => $item->nome,
                'bandeira' => $item->bandeira,
                'ultimos_digitos' => $item->ultimos_digitos,
                'cor_fundo' => $item->cor_fundo,
                'cor_texto' => $item->cor_texto,
                'total' => round((float) $item->total, 2),
                'quantidade' => (int) $item->quantidade,
            ])
            ->toArray();
    }

    private function getTotaisPorTipo(int $userId, int $ano, ?int $mes): array
    {
        return $this->baseQuery($userId, $ano, $mes)
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
