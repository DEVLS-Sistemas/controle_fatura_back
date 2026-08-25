<?php

namespace App\Services\Assinatura;

use App\Models\AssinaturaIgnorada;
use App\Models\Estabelecimento;
use App\Models\Transacao;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssinaturaService
{
    private AssinaturaDetectorService $detector;

    public function __construct(?AssinaturaDetectorService $detector = null)
    {
        $this->detector = $detector ?? new AssinaturaDetectorService();
    }

    public function handleLookupsAssinatura(): array
    {
        $map = function (array $values, array $labels): array {
            return array_map(
                fn (string $value) => [
                    'value' => $value,
                    'label' => $labels[$value],
                ],
                $values
            );
        };

        return [
            'status' => $map(AssinaturaDetectorService::STATUS, AssinaturaDetectorService::STATUS_LABELS),
            'periodicidades' => $map(AssinaturaDetectorService::PERIODICIDADES, AssinaturaDetectorService::PERIODICIDADE_LABELS),
            'confiancas' => $map(AssinaturaDetectorService::CONFIANCAS, AssinaturaDetectorService::CONFIANCA_LABELS),
            'acoes' => $map(AssinaturaDetectorService::ACOES, AssinaturaDetectorService::ACOES_LABELS),
            'ordenar' => [
                ['value' => AssinaturaDetectorService::ORDENAR_ANUAL_DESC, 'label' => 'Maior gasto anual'],
                ['value' => AssinaturaDetectorService::ORDENAR_MENSAL_DESC, 'label' => 'Maior gasto mensal'],
                ['value' => AssinaturaDetectorService::ORDENAR_ULTIMA_DESC, 'label' => 'Cobrança mais recente'],
                ['value' => AssinaturaDetectorService::ORDENAR_COBRANCAS_DESC, 'label' => 'Mais cobranças'],
                ['value' => AssinaturaDetectorService::ORDENAR_TITULO, 'label' => 'Nome A–Z'],
            ],
            'origem_confirmacao' => [
                'value' => Transacao::ORIGEM_PAGAMENTO_SERVICOS,
                'label' => Transacao::ORIGENS_COMPRA_LABELS[Transacao::ORIGEM_PAGAMENTO_SERVICOS],
            ],
        ];
    }

    public function handleListarAssinatura(object $atributes): object
    {
        try {
            $detectado = $this->detectar($atributes);
            $itens = $detectado['itens'];
            $totais = $this->detector->montarTotais($itens);

            $statusFiltro = $this->statusFiltro($atributes);
            $itens = $this->filtrarItens($itens, $atributes, $statusFiltro);
            $ordenar = $this->ordenarFiltro($atributes);
            $itens = $this->detector->ordenarItens($itens, $ordenar);

            return (object) [
                'data' => [
                    'referencia' => [
                        'hoje' => $detectado['hoje'],
                    ],
                    'ordenar_aplicada' => $ordenar,
                    'status_aplicado' => $statusFiltro,
                    'totais' => $totais,
                    'itens' => $itens,
                ],
                'status' => true,
                'message' => 'Assinaturas carregadas com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getAssinaturaId(int|string $id, object $atributes): object
    {
        try {
            $identificador = is_string($id) ? $id : (string) $id;
            $this->detector->parseIdentificador($identificador);

            $detectado = $this->detectar($atributes, true);
            $item = $this->encontrarItem($detectado['itens'], $identificador);

            if ($item === null) {
                throw new Exception('Assinatura não encontrada', 404);
            }

            $grupos = $detectado['grupos'];
            $eventos = $grupos[$identificador] ?? [];
            $item['cobrancas_recentes'] = $this->detector->cobrancasRecentes($eventos);

            return (object) [
                'data' => $item,
                'status' => true,
                'message' => 'Assinatura carregada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getAssinaturaAsync(object $params): array
    {
        $result = $this->handleListarAssinatura($params);
        $itens = $result->data['itens'] ?? [];
        $lista = array_map(fn (array $item) => [
            'id' => $item['identificador'],
            'identificador' => $item['identificador'],
            'nome' => $item['titulo'],
            'status' => $item['status'],
            'status_label' => $item['status_label'],
            'valor_medio' => $item['valor_medio'],
            'estimativa_anual' => $item['estimativa_anual'],
        ], $itens);

        if (!empty($params->palavra_chave)) {
            $lista = array_slice($lista, 0, 10);
        }

        return array_values($lista);
    }

    public function handleAddAssinatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->assinatura = $this->confirmarAssinatura($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditAssinatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $acao = (string) ($atributes->acao ?? AssinaturaDetectorService::ACAO_CONFIRMAR);
            if (!in_array($acao, AssinaturaDetectorService::ACOES, true)) {
                throw new Exception('Ação inválida', 422);
            }

            $result = (object) [];
            $result->assinatura = match ($acao) {
                AssinaturaDetectorService::ACAO_CONFIRMAR => $this->confirmarAssinatura($atributes),
                AssinaturaDetectorService::ACAO_IGNORAR => $this->ignorarAssinatura($atributes),
                AssinaturaDetectorService::ACAO_RESTAURAR => $this->restaurarAssinatura($atributes),
                AssinaturaDetectorService::ACAO_DESFAZER_CONFIRMACAO => $this->desfazerConfirmacao($atributes),
            };

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteAssinatura(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->assinatura = $this->ignorarAssinatura((object) [
                'identificador' => (string) $id,
            ]);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function confirmarAssinatura(object $atributes): object
    {
        $userId = (int) Auth::id();
        $parsed = $this->resolverIdentificador($atributes);
        $ids = $this->estabelecimentoIdsDoGrupo($userId, $parsed);

        if ($ids === []) {
            throw new Exception('Assinatura não encontrada', 404);
        }

        $afetadas = Transacao::query()
            ->where('user_id', $userId)
            ->whereIn('estabelecimento_id', $ids)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where(function ($q) {
                $q->whereNull('parcelas_total')->orWhere('parcelas_total', '<=', 1);
            })
            ->whereNull('compra_grupo_id')
            ->where(function ($q) {
                $q->whereNull('origem_compra')
                    ->orWhere('origem_compra', '!=', Transacao::ORIGEM_PAGAMENTO_SERVICOS);
            })
            ->update(['origem_compra' => Transacao::ORIGEM_PAGAMENTO_SERVICOS]);

        $this->removerIgnorada($userId, $parsed);

        $item = $this->recarregarItem($parsed['identificador']);

        return (object) [
            'data' => $item ?? ['identificador' => $parsed['identificador']],
            'status' => true,
            'transacoes_afetadas' => (int) $afetadas,
            'message' => $afetadas > 0
                ? 'Assinatura confirmada. Cobranças marcadas como pagamento de serviços.'
                : 'Assinatura já estava confirmada como pagamento de serviços.',
        ];
    }

    public function ignorarAssinatura(object $atributes): object
    {
        $userId = (int) Auth::id();
        $parsed = $this->resolverIdentificador($atributes);

        $record = AssinaturaIgnorada::withTrashed()
            ->where('user_id', $userId)
            ->where('tipo_chave', $parsed['tipo_chave'])
            ->where('referencia_id', $parsed['referencia_id'])
            ->first();

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
            }
        } else {
            $record = AssinaturaIgnorada::create([
                'user_id' => $userId,
                'tipo_chave' => $parsed['tipo_chave'],
                'referencia_id' => $parsed['referencia_id'],
            ]);
        }

        return (object) [
            'data' => [
                'identificador' => $parsed['identificador'],
                'tipo_chave' => $parsed['tipo_chave'],
                'referencia_id' => $parsed['referencia_id'],
                'ignorada' => true,
                'id' => (int) $record->id,
            ],
            'status' => true,
            'message' => 'Assinatura ignorada. Ela deixa de aparecer nas candidatas.',
        ];
    }

    public function restaurarAssinatura(object $atributes): object
    {
        $userId = (int) Auth::id();
        $parsed = $this->resolverIdentificador($atributes);
        $this->removerIgnorada($userId, $parsed);

        $item = $this->recarregarItem($parsed['identificador']);

        return (object) [
            'data' => $item ?? ['identificador' => $parsed['identificador']],
            'status' => true,
            'message' => 'Assinatura voltou a ser exibida.',
        ];
    }

    public function desfazerConfirmacao(object $atributes): object
    {
        $userId = (int) Auth::id();
        $parsed = $this->resolverIdentificador($atributes);
        $ids = $this->estabelecimentoIdsDoGrupo($userId, $parsed);

        if ($ids === []) {
            throw new Exception('Assinatura não encontrada', 404);
        }

        $afetadas = Transacao::query()
            ->where('user_id', $userId)
            ->whereIn('estabelecimento_id', $ids)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where(function ($q) {
                $q->whereNull('parcelas_total')->orWhere('parcelas_total', '<=', 1);
            })
            ->whereNull('compra_grupo_id')
            ->where('origem_compra', Transacao::ORIGEM_PAGAMENTO_SERVICOS)
            ->update(['origem_compra' => null]);

        $item = $this->recarregarItem($parsed['identificador']);

        return (object) [
            'data' => $item ?? ['identificador' => $parsed['identificador']],
            'status' => true,
            'transacoes_afetadas' => (int) $afetadas,
            'message' => $afetadas > 0
                ? 'Confirmação desfeita. Origem das cobranças foi limpa.'
                : 'Nenhuma cobrança estava marcada como pagamento de serviços.',
        ];
    }

    /**
     * @return array{hoje: string, itens: array<int, array<string, mixed>>, grupos: array<string, array<int, array<string, mixed>>>}
     */
    private function detectar(object $atributes, bool $incluirIgnoradas = false): array
    {
        $userId = (int) Auth::id();
        $hoje = !empty($atributes->hoje)
            ? Carbon::parse((string) $atributes->hoje)->toDateString()
            : Carbon::today()->toDateString();

        if ($incluirIgnoradas === false) {
            $incluirIgnoradas = $this->statusFiltro($atributes) === AssinaturaDetectorService::STATUS_IGNORADA
                || filter_var($atributes->incluir_ignoradas ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $eventos = $this->carregarEventos($userId, $atributes);
        $grupos = $this->detector->agruparEventos($eventos);
        $ignoradas = $this->mapaIgnoradas($userId);

        $itens = [];
        foreach ($grupos as $identificador => $grupoEventos) {
            $item = $this->detector->classificarGrupo($grupoEventos, $hoje, $identificador);
            if ($item === null) {
                continue;
            }

            $chaveIgnore = $item['tipo_chave'] . ':' . $item['referencia_id'];
            $foiIgnorada = isset($ignoradas[$chaveIgnore]);
            $item['ignorada'] = $foiIgnorada;
            if ($foiIgnorada) {
                $item['status'] = AssinaturaDetectorService::STATUS_IGNORADA;
                $item['status_label'] = AssinaturaDetectorService::STATUS_LABELS[AssinaturaDetectorService::STATUS_IGNORADA];
            }

            if ($foiIgnorada && !$incluirIgnoradas) {
                continue;
            }

            $itens[] = $item;
        }

        return [
            'hoje' => $hoje,
            'itens' => $itens,
            'grupos' => $grupos,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function carregarEventos(int $userId, object $atributes): array
    {
        $query = DB::table('transacoes as t')
            ->join('estabelecimentos as e', 'e.id', '=', 't.estabelecimento_id')
            ->leftJoin('lojas as l', function ($join) {
                $join->on('l.id', '=', 'e.loja_id')->whereNull('l.deleted_at');
            })
            ->leftJoin('categorias as c', function ($join) {
                $join->on('c.id', '=', 't.categoria_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('subcategorias as s', function ($join) {
                $join->on('s.id', '=', 't.subcategoria_id')->whereNull('s.deleted_at');
            })
            ->leftJoin('responsaveis as r', function ($join) {
                $join->on('r.id', '=', 't.responsavel_id')->whereNull('r.deleted_at');
            })
            ->leftJoin('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->where('t.user_id', $userId)
            ->whereNull('t.deleted_at')
            ->whereNull('e.deleted_at')
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->whereNotNull('t.data')
            ->whereNotNull('t.estabelecimento_id')
            ->whereNull('t.compra_grupo_id')
            ->where(function ($q) {
                $q->whereNull('t.parcelas_total')->orWhere('t.parcelas_total', '<=', 1);
            })
            ->where(function ($q) {
                $q->whereNull('t.origem_compra')
                    ->orWhere('t.origem_compra', '!=', Transacao::ORIGEM_PAGAMENTO_FATURA);
            });

        if (!empty($atributes->cartao_id)) {
            $query->where('f.cartao_id', (int) $atributes->cartao_id);
        }
        if (!empty($atributes->responsavel_id)) {
            $query->where('t.responsavel_id', (int) $atributes->responsavel_id);
        }
        if (!empty($atributes->categoria_id)) {
            $query->where('t.categoria_id', (int) $atributes->categoria_id);
        }

        $rows = $query->select([
            't.id',
            't.data',
            't.valor',
            't.origem_compra',
            't.observacoes',
            't.estabelecimento_id',
            't.categoria_id',
            't.subcategoria_id',
            't.responsavel_id',
            't.fatura_id',
            'e.nome as estabelecimento_nome',
            'e.loja_id',
            'l.nome as loja_nome',
            'c.nome as categoria_nome',
            'c.cor as categoria_cor',
            's.nome as subcategoria_nome',
            'r.nome as responsavel_nome',
            'f.mes as fatura_mes',
            'f.ano as fatura_ano',
        ])->get();

        return $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'data' => Carbon::parse($row->data)->toDateString(),
                'valor' => (float) $row->valor,
                'origem_compra' => $row->origem_compra,
                'observacoes' => $row->observacoes,
                'estabelecimento_id' => (int) $row->estabelecimento_id,
                'estabelecimento_nome' => $row->estabelecimento_nome,
                'loja_id' => $row->loja_id !== null ? (int) $row->loja_id : null,
                'loja_nome' => $row->loja_nome,
                'categoria_id' => $row->categoria_id !== null ? (int) $row->categoria_id : null,
                'categoria_nome' => $row->categoria_nome,
                'categoria_cor' => $row->categoria_cor,
                'subcategoria_id' => $row->subcategoria_id !== null ? (int) $row->subcategoria_id : null,
                'subcategoria_nome' => $row->subcategoria_nome,
                'responsavel_id' => $row->responsavel_id !== null ? (int) $row->responsavel_id : null,
                'responsavel_nome' => $row->responsavel_nome,
                'fatura_id' => $row->fatura_id !== null ? (int) $row->fatura_id : null,
                'fatura_mes' => $row->fatura_mes !== null ? (int) $row->fatura_mes : null,
                'fatura_ano' => $row->fatura_ano !== null ? (int) $row->fatura_ano : null,
            ];
        })->all();
    }

    /**
     * @return array<string, true>
     */
    private function mapaIgnoradas(int $userId): array
    {
        $rows = AssinaturaIgnorada::query()
            ->where('user_id', $userId)
            ->get(['tipo_chave', 'referencia_id']);

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[$row->tipo_chave . ':' . (int) $row->referencia_id] = true;
        }

        return $mapa;
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    private function filtrarItens(array $itens, object $atributes, string $statusFiltro): array
    {
        $palavra = trim((string) ($atributes->palavra_chave ?? ''));
        $periodicidade = (string) ($atributes->periodicidade ?? '');

        return array_values(array_filter($itens, function (array $item) use ($statusFiltro, $palavra, $periodicidade) {
            if ($statusFiltro !== 'todas' && ($item['status'] ?? '') !== $statusFiltro) {
                return false;
            }

            if ($periodicidade !== '' && ($item['periodicidade'] ?? '') !== $periodicidade) {
                return false;
            }

            if ($palavra === '') {
                return true;
            }

            $hay = mb_strtolower(implode(' ', array_filter([
                $item['titulo'] ?? '',
                $item['loja_nome'] ?? '',
                $item['estabelecimento_nome'] ?? '',
            ])), 'UTF-8');

            return str_contains($hay, mb_strtolower($palavra, 'UTF-8'));
        }));
    }

    private function statusFiltro(object $atributes): string
    {
        $status = (string) ($atributes->status ?? 'todas');
        if ($status === '' || $status === 'todas') {
            return 'todas';
        }

        if (!in_array($status, AssinaturaDetectorService::STATUS, true)) {
            throw new Exception('Status inválido', 422);
        }

        return $status;
    }

    private function ordenarFiltro(object $atributes): string
    {
        $ordenar = (string) ($atributes->ordenar ?? AssinaturaDetectorService::ORDENAR_ANUAL_DESC);
        if ($ordenar === '' || !in_array($ordenar, AssinaturaDetectorService::ORDENACOES, true)) {
            return AssinaturaDetectorService::ORDENAR_ANUAL_DESC;
        }

        return $ordenar;
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     */
    private function encontrarItem(array $itens, string $identificador): ?array
    {
        foreach ($itens as $item) {
            if (($item['identificador'] ?? '') === $identificador) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{identificador: string, tipo_chave: string, referencia_id: int}
     */
    private function resolverIdentificador(object $atributes): array
    {
        if (!empty($atributes->identificador)) {
            $parsed = $this->detector->parseIdentificador((string) $atributes->identificador);

            return $parsed + ['identificador' => (string) $atributes->identificador];
        }

        if (!empty($atributes->loja_id)) {
            $id = (int) $atributes->loja_id;
            $identificador = $this->detector->montarIdentificador(AssinaturaDetectorService::TIPO_CHAVE_LOJA, $id);

            return [
                'identificador' => $identificador,
                'tipo_chave' => AssinaturaDetectorService::TIPO_CHAVE_LOJA,
                'referencia_id' => $id,
            ];
        }

        if (!empty($atributes->estabelecimento_id)) {
            $id = (int) $atributes->estabelecimento_id;
            $identificador = $this->detector->montarIdentificador(
                AssinaturaDetectorService::TIPO_CHAVE_ESTABELECIMENTO,
                $id
            );

            return [
                'identificador' => $identificador,
                'tipo_chave' => AssinaturaDetectorService::TIPO_CHAVE_ESTABELECIMENTO,
                'referencia_id' => $id,
            ];
        }

        throw new Exception('Informe identificador, loja_id ou estabelecimento_id', 422);
    }

    /**
     * @param array{tipo_chave: string, referencia_id: int} $parsed
     * @return array<int>
     */
    private function estabelecimentoIdsDoGrupo(int $userId, array $parsed): array
    {
        if ($parsed['tipo_chave'] === AssinaturaDetectorService::TIPO_CHAVE_LOJA) {
            return Estabelecimento::query()
                ->where('user_id', $userId)
                ->where('loja_id', $parsed['referencia_id'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $exists = Estabelecimento::query()
            ->where('user_id', $userId)
            ->where('id', $parsed['referencia_id'])
            ->exists();

        return $exists ? [(int) $parsed['referencia_id']] : [];
    }

    /**
     * @param array{tipo_chave: string, referencia_id: int} $parsed
     */
    private function removerIgnorada(int $userId, array $parsed): void
    {
        AssinaturaIgnorada::query()
            ->where('user_id', $userId)
            ->where('tipo_chave', $parsed['tipo_chave'])
            ->where('referencia_id', $parsed['referencia_id'])
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recarregarItem(string $identificador): ?array
    {
        $detectado = $this->detectar((object) ['incluir_ignoradas' => true], true);

        return $this->encontrarItem($detectado['itens'], $identificador);
    }
}
