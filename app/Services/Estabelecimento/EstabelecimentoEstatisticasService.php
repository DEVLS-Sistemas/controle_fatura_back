<?php

namespace App\Services\Estabelecimento;

use App\Models\Estabelecimento;
use App\Models\Loja;
use App\Models\Transacao;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EstabelecimentoEstatisticasService
{
    public function handleEstabelecimento(int|string $id, object $atributes): object
    {
        $userId = (int) Auth::id();
        $estabelecimento = $this->assertEstabelecimento($userId, $id);
        $periodo = $this->resolvePeriodo($atributes, $userId, [(int) $estabelecimento->id]);
        $stats = $this->estatisticasDeEstabelecimentos(
            $userId,
            [(int) $estabelecimento->id],
            $periodo
        )[(int) $estabelecimento->id] ?? $this->vazio($periodo);

        return (object) [
            'data' => [
                'estabelecimento_id' => (int) $estabelecimento->id,
                'nome' => $estabelecimento->nome,
                'loja_id' => $estabelecimento->loja_id !== null ? (int) $estabelecimento->loja_id : null,
                'periodo' => $periodo,
                ...$stats,
            ],
            'status' => true,
            'message' => 'Estatísticas do estabelecimento carregadas com sucesso!',
        ];
    }

    public function handleLoja(int|string $id, object $atributes): object
    {
        $userId = (int) Auth::id();
        $loja = $this->assertLoja($userId, $id);
        $estabelecimentos = Estabelecimento::query()
            ->where('user_id', $userId)
            ->where('loja_id', $loja->id)
            ->whereNull('deleted_at')
            ->orderBy('nome')
            ->get(['id', 'nome', 'ativo']);

        $ids = $estabelecimentos->pluck('id')->map(fn ($id) => (int) $id)->all();
        $periodo = $this->resolvePeriodo($atributes, $userId, $ids);
        $porEstab = $this->estatisticasDeEstabelecimentos($userId, $ids, $periodo);
        $totais = $this->somarEstatisticas(array_values($porEstab), $periodo);

        $lista = $estabelecimentos->map(function (Estabelecimento $est) use ($porEstab, $periodo) {
            $stats = $porEstab[(int) $est->id] ?? $this->vazio($periodo);

            return [
                'id' => (int) $est->id,
                'nome' => $est->nome,
                'ativo' => (bool) $est->ativo,
                ...$stats,
            ];
        })->values()->all();

        return (object) [
            'data' => [
                'loja_id' => (int) $loja->id,
                'nome' => $loja->nome,
                'periodo' => $periodo,
                'estabelecimentos_count' => count($lista),
                ...$totais,
                'estabelecimentos' => $lista,
            ],
            'status' => true,
            'message' => 'Estatísticas da loja carregadas com sucesso!',
        ];
    }

    public function temFiltroPeriodo(object $atributes): bool
    {
        return (!empty($atributes->mes) && !empty($atributes->ano))
            || !empty($atributes->data_inicio)
            || !empty($atributes->data_fim);
    }

    /**
     * Estatísticas para listagem: período compartilhado se houver filtro; senão histórico próprio de cada id.
     *
     * @param array<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function mapaParaListagem(int $userId, array $ids, object $atributes, string $escopo = 'estabelecimento'): array
    {
        if ($this->temFiltroPeriodo($atributes)) {
            $periodo = $this->resolvePeriodo($atributes, $userId, $ids);

            return $escopo === 'loja'
                ? $this->estatisticasDeLojas($userId, $ids, $periodo)
                : $this->estatisticasDeEstabelecimentos($userId, $ids, $periodo);
        }

        return $escopo === 'loja'
            ? $this->estatisticasHistoricoDeLojas($userId, $ids)
            : $this->estatisticasHistoricoDeEstabelecimentos($userId, $ids);
    }

    /**
     * @param array<int> $estabelecimentoIds
     * @return array<int, array<string, mixed>>
     */
    public function estatisticasDeEstabelecimentos(int $userId, array $estabelecimentoIds, array $periodo): array
    {
        $ids = array_values(array_filter(array_map('intval', $estabelecimentoIds), fn (int $id) => $id > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->baseComprasQuery($userId)
            ->whereIn('t.estabelecimento_id', $ids)
            ->whereDate('t.data', '>=', $periodo['inicio'])
            ->whereDate('t.data', '<=', $periodo['fim'])
            ->groupBy('t.estabelecimento_id')
            ->select([
                't.estabelecimento_id',
                DB::raw('COUNT(*) as ocorrencias'),
                DB::raw("COUNT(DISTINCT CASE WHEN t.compra_grupo_id IS NOT NULL THEN t.compra_grupo_id ELSE CONCAT('av-', t.id) END) as compras"),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total'),
                DB::raw('MIN(t.data) as primeira_compra'),
                DB::raw('MAX(t.data) as ultima_compra'),
            ])
            ->get();

        $mapa = [];
        foreach ($ids as $id) {
            $mapa[$id] = $this->vazio($periodo);
        }

        foreach ($rows as $row) {
            $id = (int) $row->estabelecimento_id;
            $mapa[$id] = $this->montarEstatistica(
                (int) $row->compras,
                (int) $row->ocorrencias,
                (float) $row->valor_total,
                $row->primeira_compra,
                $row->ultima_compra,
                $periodo
            );
        }

        return $mapa;
    }

    /**
     * Totais da loja (todas as compras dos estabelecimentos vinculados).
     *
     * @param array<int> $lojaIds
     * @return array<int, array<string, mixed>>
     */
    public function estatisticasDeLojas(int $userId, array $lojaIds, array $periodo): array
    {
        $ids = array_values(array_filter(array_map('intval', $lojaIds), fn (int $id) => $id > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->baseComprasQuery($userId)
            ->join('estabelecimentos as e', function ($join) {
                $join->on('e.id', '=', 't.estabelecimento_id')->whereNull('e.deleted_at');
            })
            ->whereIn('e.loja_id', $ids)
            ->whereDate('t.data', '>=', $periodo['inicio'])
            ->whereDate('t.data', '<=', $periodo['fim'])
            ->groupBy('e.loja_id')
            ->select([
                'e.loja_id',
                DB::raw('COUNT(*) as ocorrencias'),
                DB::raw("COUNT(DISTINCT CASE WHEN t.compra_grupo_id IS NOT NULL THEN t.compra_grupo_id ELSE CONCAT('av-', t.id) END) as compras"),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total'),
                DB::raw('MIN(t.data) as primeira_compra'),
                DB::raw('MAX(t.data) as ultima_compra'),
            ])
            ->get();

        $mapa = [];
        foreach ($ids as $id) {
            $mapa[$id] = $this->vazio($periodo);
        }

        foreach ($rows as $row) {
            $id = (int) $row->loja_id;
            $mapa[$id] = $this->montarEstatistica(
                (int) $row->compras,
                (int) $row->ocorrencias,
                (float) $row->valor_total,
                $row->primeira_compra,
                $row->ultima_compra,
                $periodo
            );
        }

        return $mapa;
    }

    /**
     * @param array<int> $estabelecimentoIds
     * @return array<int, array<string, mixed>>
     */
    public function estatisticasHistoricoDeEstabelecimentos(int $userId, array $estabelecimentoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $estabelecimentoIds), fn (int $id) => $id > 0));
        $hoje = $this->periodoPayload(now()->startOfDay(), now()->startOfDay(), 'historico');
        if ($ids === []) {
            return [];
        }

        $rows = $this->baseComprasQuery($userId)
            ->whereIn('t.estabelecimento_id', $ids)
            ->groupBy('t.estabelecimento_id')
            ->select([
                't.estabelecimento_id',
                DB::raw('COUNT(*) as ocorrencias'),
                DB::raw("COUNT(DISTINCT CASE WHEN t.compra_grupo_id IS NOT NULL THEN t.compra_grupo_id ELSE CONCAT('av-', t.id) END) as compras"),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total'),
                DB::raw('MIN(t.data) as primeira_compra'),
                DB::raw('MAX(t.data) as ultima_compra'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->estabelecimento_id);

        $mapa = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if (!$row) {
                $mapa[$id] = $this->vazio($hoje);
                continue;
            }

            $inicio = Carbon::parse((string) $row->primeira_compra)->startOfDay();
            $periodo = $this->periodoPayload($inicio, now()->startOfDay(), 'historico');
            $mapa[$id] = $this->montarEstatistica(
                (int) $row->compras,
                (int) $row->ocorrencias,
                (float) $row->valor_total,
                $row->primeira_compra,
                $row->ultima_compra,
                $periodo
            );
        }

        return $mapa;
    }

    /**
     * @param array<int> $lojaIds
     * @return array<int, array<string, mixed>>
     */
    public function estatisticasHistoricoDeLojas(int $userId, array $lojaIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $lojaIds), fn (int $id) => $id > 0));
        $hoje = $this->periodoPayload(now()->startOfDay(), now()->startOfDay(), 'historico');
        if ($ids === []) {
            return [];
        }

        $rows = $this->baseComprasQuery($userId)
            ->join('estabelecimentos as e', function ($join) {
                $join->on('e.id', '=', 't.estabelecimento_id')->whereNull('e.deleted_at');
            })
            ->whereIn('e.loja_id', $ids)
            ->groupBy('e.loja_id')
            ->select([
                'e.loja_id',
                DB::raw('COUNT(*) as ocorrencias'),
                DB::raw("COUNT(DISTINCT CASE WHEN t.compra_grupo_id IS NOT NULL THEN t.compra_grupo_id ELSE CONCAT('av-', t.id) END) as compras"),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total'),
                DB::raw('MIN(t.data) as primeira_compra'),
                DB::raw('MAX(t.data) as ultima_compra'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->loja_id);

        $mapa = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if (!$row) {
                $mapa[$id] = $this->vazio($hoje);
                continue;
            }

            $inicio = Carbon::parse((string) $row->primeira_compra)->startOfDay();
            $periodo = $this->periodoPayload($inicio, now()->startOfDay(), 'historico');
            $mapa[$id] = $this->montarEstatistica(
                (int) $row->compras,
                (int) $row->ocorrencias,
                (float) $row->valor_total,
                $row->primeira_compra,
                $row->ultima_compra,
                $periodo
            );
        }

        return $mapa;
    }

    /**
     * @param array<int> $estabelecimentoIds
     * @return array{inicio: string, fim: string, origem: string, dias: int}
     */
    public function resolvePeriodo(object $atributes, int $userId, array $estabelecimentoIds = []): array
    {
        if (!empty($atributes->mes) && !empty($atributes->ano)) {
            $mes = (int) $atributes->mes;
            $ano = (int) $atributes->ano;
            if ($mes < 1 || $mes > 12 || $ano < 1) {
                throw new Exception('Mês/ano inválidos', 422);
            }
            $inicio = Carbon::create($ano, $mes, 1)->startOfDay();
            $fim = $inicio->copy()->endOfMonth()->startOfDay();

            return $this->periodoPayload($inicio, $fim, 'mes');
        }

        if (!empty($atributes->data_inicio) || !empty($atributes->data_fim)) {
            $fim = !empty($atributes->data_fim)
                ? Carbon::parse((string) $atributes->data_fim)->startOfDay()
                : now()->startOfDay();
            $inicio = !empty($atributes->data_inicio)
                ? Carbon::parse((string) $atributes->data_inicio)->startOfDay()
                : $this->primeiraDataCompras($userId, $estabelecimentoIds, $fim);

            if ($inicio->gt($fim)) {
                throw new Exception('data_inicio deve ser anterior ou igual a data_fim', 422);
            }

            return $this->periodoPayload($inicio, $fim, 'filtro');
        }

        $limites = $this->limitesHistorico($userId, $estabelecimentoIds);
        $hoje = now()->startOfDay();
        if ($limites['primeira'] === null) {
            return $this->periodoPayload($hoje, $hoje, 'historico');
        }

        $inicio = Carbon::parse($limites['primeira'])->startOfDay();

        return $this->periodoPayload($inicio, $hoje, 'historico');
    }

    /**
     * @return array{
     *   compras: int,
     *   ocorrencias: int,
     *   valor_total: float,
     *   ticket_medio: float,
     *   primeira_compra: ?string,
     *   ultima_compra: ?string,
     *   dias_desde_ultima: ?int,
     *   frequencia: array<string, mixed>
     * }
     */
    public function montarEstatistica(
        int $compras,
        int $ocorrencias,
        float $valorTotal,
        mixed $primeiraCompra,
        mixed $ultimaCompra,
        array $periodo
    ): array {
        $valor = round($valorTotal, 2);
        $primeira = $this->asDateString($primeiraCompra);
        $ultima = $this->asDateString($ultimaCompra);
        $diasDesdeUltima = null;
        if ($ultima !== null) {
            $diasDesdeUltima = Carbon::parse($ultima)->startOfDay()->diffInDays(now()->startOfDay());
        }

        return [
            'compras' => $compras,
            'ocorrencias' => $ocorrencias,
            'valor_total' => $valor,
            'ticket_medio' => $compras > 0 ? round($valor / $compras, 2) : 0.0,
            'primeira_compra' => $primeira,
            'ultima_compra' => $ultima,
            'dias_desde_ultima' => $diasDesdeUltima,
            'frequencia' => $this->buildFrequencia($compras, (int) $periodo['dias']),
        ];
    }

    /**
     * @return array{
     *   periodo_dias: int,
     *   compras: int,
     *   intervalo_medio_dias: ?float,
     *   label: string,
     *   por_dia: float,
     *   por_semana: float,
     *   por_mes: float,
     *   por_ano: float
     * }
     */
    public function buildFrequencia(int $compras, int $periodoDias): array
    {
        $dias = max($periodoDias, 1);
        $intervalo = $compras > 0 ? round($dias / $compras, 2) : null;

        return [
            'periodo_dias' => $dias,
            'compras' => $compras,
            'intervalo_medio_dias' => $intervalo,
            'label' => $this->labelFrequencia($compras, $intervalo),
            'por_dia' => round($compras / $dias, 4),
            'por_semana' => round($compras / ($dias / 7), 2),
            'por_mes' => round($compras / ($dias / 30.437), 2),
            'por_ano' => round($compras / ($dias / 365.25), 2),
        ];
    }

    public function labelFrequencia(int $compras, ?float $intervaloMedioDias): string
    {
        if ($compras <= 0) {
            return 'Nenhuma compra no período';
        }

        if ($compras === 1) {
            return '1 compra no período';
        }

        $dias = (float) $intervaloMedioDias;
        if ($dias < 1.15) {
            return '1 vez por dia';
        }

        if ($dias >= 6.5 && $dias < 8.2) {
            return '1 vez por semana';
        }
        if ($dias >= 13 && $dias < 16) {
            return '1 vez a cada 2 semanas';
        }
        if ($dias >= 27 && $dias < 34) {
            return '1 vez por mês';
        }
        if ($dias >= 55 && $dias < 70) {
            return '1 vez a cada 2 meses';
        }

        $n = (int) max(1, (int) round($dias));

        return '1 vez a cada ' . $n . ' ' . ($n === 1 ? 'dia' : 'dias');
    }

    /**
     * @return array<string, mixed>
     */
    public function vazio(array $periodo): array
    {
        return $this->montarEstatistica(0, 0, 0.0, null, null, $periodo);
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<string, mixed>
     */
    public function somarEstatisticas(array $itens, array $periodo): array
    {
        $compras = 0;
        $ocorrencias = 0;
        $valor = 0.0;
        $primeiras = [];
        $ultimas = [];

        foreach ($itens as $item) {
            $compras += (int) ($item['compras'] ?? 0);
            $ocorrencias += (int) ($item['ocorrencias'] ?? 0);
            $valor += (float) ($item['valor_total'] ?? 0);
            if (!empty($item['primeira_compra'])) {
                $primeiras[] = (string) $item['primeira_compra'];
            }
            if (!empty($item['ultima_compra'])) {
                $ultimas[] = (string) $item['ultima_compra'];
            }
        }

        sort($primeiras);
        rsort($ultimas);

        return $this->montarEstatistica(
            $compras,
            $ocorrencias,
            $valor,
            $primeiras[0] ?? null,
            $ultimas[0] ?? null,
            $periodo
        );
    }

    private function baseComprasQuery(int $userId)
    {
        return DB::table('transacoes as t')
            ->where('t.user_id', $userId)
            ->whereNull('t.deleted_at')
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->whereNotNull('t.estabelecimento_id')
            ->whereNotNull('t.data');
    }

    /**
     * @param array<int> $estabelecimentoIds
     */
    private function limitesHistorico(int $userId, array $estabelecimentoIds): array
    {
        $query = $this->baseComprasQuery($userId);
        if ($estabelecimentoIds !== []) {
            $query->whereIn('t.estabelecimento_id', $estabelecimentoIds);
        }

        $row = $query->selectRaw('MIN(t.data) as primeira, MAX(t.data) as ultima')->first();

        return [
            'primeira' => $this->asDateString($row->primeira ?? null),
            'ultima' => $this->asDateString($row->ultima ?? null),
        ];
    }

    /**
     * @param array<int> $estabelecimentoIds
     */
    private function primeiraDataCompras(int $userId, array $estabelecimentoIds, Carbon $fallback): Carbon
    {
        $limites = $this->limitesHistorico($userId, $estabelecimentoIds);
        if ($limites['primeira'] === null) {
            return $fallback->copy();
        }

        return Carbon::parse($limites['primeira'])->startOfDay();
    }

    /**
     * @return array{inicio: string, fim: string, origem: string, dias: int}
     */
    private function periodoPayload(Carbon $inicio, Carbon $fim, string $origem): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fim = $fim->copy()->startOfDay();

        return [
            'inicio' => $inicio->toDateString(),
            'fim' => $fim->toDateString(),
            'origem' => $origem,
            'dias' => $inicio->diffInDays($fim) + 1,
        ];
    }

    private function asDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function assertEstabelecimento(int $userId, int|string $id): Estabelecimento
    {
        $record = Estabelecimento::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$record) {
            throw new Exception('Estabelecimento não encontrado', 404);
        }

        return $record;
    }

    private function assertLoja(int $userId, int|string $id): Loja
    {
        $record = Loja::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$record) {
            throw new Exception('Loja não encontrada', 404);
        }

        return $record;
    }
}
