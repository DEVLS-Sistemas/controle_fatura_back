<?php

namespace App\Services\Transacao;

use App\Models\Repasse;
use App\Models\Transacao;
use App\Services\Cartao\BandeiraCoresPreset;
use App\Services\Categoria\CategoriaCoresTema;
use App\Services\Categoria\CategoriaCorVariacao;
use App\Services\Dashboard\RankingParceladasService;
use App\Services\Repasse\RepasseService;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompraVisualizacaoService
{
    public const STATUS_PARCELA_PAGA = 'paga';
    public const STATUS_PARCELA_ATUAL = 'atual';
    public const STATUS_PARCELA_ABERTA = 'aberta';

    public const STATUS_PARCELA_LABELS = [
        self::STATUS_PARCELA_PAGA => 'Paga',
        self::STATUS_PARCELA_ATUAL => 'Competência atual',
        self::STATUS_PARCELA_ABERTA => 'Em aberto',
    ];

    private const FATURA_STATUS_LABELS = [
        'pendente' => 'Pendente',
        'processando' => 'Processando',
        'processada' => 'Processada',
        'erro' => 'Erro',
    ];

    private const CARTAO_NUMERO_TIPO_LABELS = [
        'fisico' => 'Físico',
        'virtual' => 'Virtual',
        'adicional' => 'Adicional',
    ];

    private RankingParceladasService $rankingService;
    private RepasseService $repasseService;

    public function __construct(
        ?RankingParceladasService $rankingService = null,
        ?RepasseService $repasseService = null
    ) {
        $this->rankingService = $rankingService ?? new RankingParceladasService();
        $this->repasseService = $repasseService ?? new RepasseService();
    }

    public function handleVisualizar(string $identificador, object $atributes): object
    {
        try {
            $userId = (int) Auth::id();
            $mes = (int) ($atributes->mes ?? now()->month);
            $ano = (int) ($atributes->ano ?? now()->year);

            $parcelas = $this->loadParcelas($userId, $identificador);
            if ($parcelas->isEmpty()) {
                throw new Exception('Compra não encontrada', 404);
            }

            $repasses = $this->loadRepassePagosMap(
                $userId,
                $parcelas->pluck('id')->map(fn ($id) => (int) $id)->all()
            );

            $detalhe = $this->buildDetalheFromGrupo($parcelas, $mes, $ano, $repasses);
            $this->anexarConciliacaoEAnexos($detalhe, $userId);

            return (object) [
                'data' => $detalhe,
                'status' => true,
                'message' => 'Compra carregada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param Collection<int, object> $grupo
     * @param array<int, array{valor_pago: float, data_ultimo: ?string}> $repasses
     * @return array<string, mixed>
     */
    public function buildDetalheFromGrupo(
        Collection $grupo,
        int $mesRef,
        int $anoRef,
        array $repasses = []
    ): array {
        $refKey = $this->rankingService->competenciaKey($mesRef, $anoRef);
        $resumo = $this->rankingService->buildItemFromGrupo($grupo, $refKey);
        $resumo['quitada'] = $this->rankingService->estaQuitada($resumo);
        $resumo['estimativa_termino'] = $this->rankingService->formatCompetenciaLabel(
            (int) ($resumo['ultima_parcela']['mes'] ?? 0),
            (int) ($resumo['ultima_parcela']['ano'] ?? 0)
        );

        $ordenado = $grupo
            ->sortBy(fn ($p) => $this->rankingService->competenciaKey((int) $p->fatura_mes, (int) $p->fatura_ano))
            ->values();
        $meta = $ordenado->sortBy('parcela_atual')->first();

        $parcelas = $ordenado
            ->map(fn ($p) => $this->mapParcelaDetalhe($p, $refKey, $repasses))
            ->values()
            ->all();

        $origem = $meta->origem_compra ?? null;
        $tipo = $meta->tipo ?? Transacao::TIPO_PURCHASE;
        $tipoCartaoNumero = $meta->cartao_numero_tipo ?? null;
        $compraGrupoId = $meta->compra_grupo_id ?? null;
        if ($compraGrupoId === '') {
            $compraGrupoId = null;
        }

        return array_merge($resumo, [
            'referencia' => [
                'mes' => $mesRef,
                'ano' => $anoRef,
            ],
            'compra_grupo_id' => $compraGrupoId,
            'transacao_id' => $meta->id !== null ? (int) $meta->id : null,
            'avista' => $compraGrupoId === null || (int) ($meta->parcelas_total ?? 1) <= 1,
            'tipo' => $tipo,
            'tipo_label' => Transacao::TIPOS_LABELS[$tipo] ?? $tipo,
            'origem_compra_label' => $origem !== null
                ? (Transacao::ORIGENS_COMPRA_LABELS[$origem] ?? $origem)
                : null,
            'eh_assinatura' => (bool) ($meta->eh_assinatura ?? false),
            'importada_pdf' => (bool) ($meta->importada_pdf ?? false),
            'compra_manual' => Transacao::isCompraManualRow([
                'tipo' => $tipo,
                'compra_manual' => $meta->compra_manual ?? null,
                'importada_pdf' => $meta->importada_pdf ?? false,
            ]),
            'categoria_cor' => CategoriaCoresTema::corParaGrafico(
                $meta->categoria_cor ?? null,
                $meta->categoria_id ?? null
            ),
            'loja_id' => $meta->loja_id !== null && $meta->loja_id !== '' ? (int) $meta->loja_id : null,
            'loja_nome' => $meta->loja_nome ?? null,
            'responsavel_tipo' => $meta->responsavel_tipo ?? null,
            'cartao_banco' => $meta->cartao_banco ?? null,
            'cartao_numero_id' => $meta->cartao_numero_id !== null && $meta->cartao_numero_id !== ''
                ? (int) $meta->cartao_numero_id
                : null,
            'ultimos_digitos' => $meta->ultimos_digitos ?? null,
            'cartao_numero_tipo' => $tipoCartaoNumero,
            'cartao_numero_tipo_label' => $tipoCartaoNumero !== null
                ? (self::CARTAO_NUMERO_TIPO_LABELS[$tipoCartaoNumero] ?? $tipoCartaoNumero)
                : null,
            'cartao_numero_apelido' => $meta->cartao_numero_apelido ?? null,
            'cartao_numero_nome_no_cartao' => $meta->cartao_numero_nome_no_cartao ?? null,
            'estabelecimento' => $this->mapEstabelecimento($meta),
            'categoria' => $this->mapCategoria($meta),
            'subcategoria' => $this->mapSubcategoria($meta),
            'plataforma' => $this->mapPlataforma($meta),
            'responsavel' => $this->mapResponsavel($meta),
            'cartao' => $this->mapCartao($meta),
            'bandeira' => $this->mapBandeira($meta),
            'cartao_numero' => $this->mapCartaoNumero($meta),
            'parcelas' => $parcelas,
        ]);
    }

    /**
     * @param array<int, array{valor_pago: float, data_ultimo: ?string}> $repasses
     * @return array<string, mixed>
     */
    public function mapParcelaDetalhe(object $parcela, int $refKey, array $repasses = []): array
    {
        $parcelaKey = $this->rankingService->competenciaKey(
            (int) $parcela->fatura_mes,
            (int) $parcela->fatura_ano
        );
        $statusParcela = $this->resolveStatusParcela($parcelaKey, $refKey);
        $tid = (int) $parcela->id;
        $valor = round((float) $parcela->valor, 2);
        $pagoRepasse = (float) ($repasses[$tid]['valor_pago'] ?? 0);
        $repasse = $this->repasseService->computeStatusParcela($valor, $pagoRepasse);
        $faturaStatus = $parcela->fatura_status ?? null;

        return [
            'id' => $tid,
            'parcela_atual' => (int) $parcela->parcela_atual,
            'parcelas_total' => (int) $parcela->parcelas_total,
            'data' => $parcela->data,
            'valor' => $valor,
            'valor_parcela' => $parcela->valor_parcela !== null
                ? round((float) $parcela->valor_parcela, 2)
                : $valor,
            'fatura_id' => $parcela->fatura_id !== null ? (int) $parcela->fatura_id : null,
            'fatura_mes' => (int) $parcela->fatura_mes,
            'fatura_ano' => (int) $parcela->fatura_ano,
            'fatura_label' => $this->rankingService->formatCompetenciaLabel(
                (int) $parcela->fatura_mes,
                (int) $parcela->fatura_ano
            ),
            'fatura_status' => $faturaStatus,
            'fatura_status_label' => $faturaStatus !== null
                ? (self::FATURA_STATUS_LABELS[$faturaStatus] ?? $faturaStatus)
                : null,
            'paga' => $statusParcela === self::STATUS_PARCELA_PAGA
                || $statusParcela === self::STATUS_PARCELA_ATUAL,
            'status_parcela' => $statusParcela,
            'status_parcela_label' => self::STATUS_PARCELA_LABELS[$statusParcela],
            'importada_pdf' => (bool) ($parcela->importada_pdf ?? false),
            'repasse' => [
                'status_repasse' => $repasse['status_repasse'],
                'status_repasse_label' => Repasse::STATUS_LABELS[$repasse['status_repasse']] ?? $repasse['status_repasse'],
                'valor_pago' => $repasse['valor_pago'],
                'valor_aberto' => $repasse['valor_aberto'],
                'data_ultimo' => $repasses[$tid]['data_ultimo'] ?? null,
            ],
        ];
    }

    public function resolveStatusParcela(int $parcelaKey, int $refKey): string
    {
        if ($parcelaKey < $refKey) {
            return self::STATUS_PARCELA_PAGA;
        }

        if ($parcelaKey === $refKey) {
            return self::STATUS_PARCELA_ATUAL;
        }

        return self::STATUS_PARCELA_ABERTA;
    }

    /**
     * @param array<string, mixed> $detalhe
     */
    private function anexarConciliacaoEAnexos(array &$detalhe, int $userId): void
    {
        $transacaoId = $detalhe['transacao_id'] ?? null;
        if (!$transacaoId) {
            $detalhe['conciliacao'] = null;
            $detalhe['anexos'] = [];

            return;
        }

        $ancora = Transacao::where('id', $transacaoId)->where('user_id', $userId)->first();
        if (!$ancora) {
            $detalhe['conciliacao'] = null;
            $detalhe['anexos'] = [];

            return;
        }

        $detalhe['descricao'] = $ancora->descricao;
        $detalhe['descricao_fatura'] = $ancora->descricao_fatura;
        $detalhe['observacoes'] = $ancora->observacoes;
        $detalhe['texto_compra'] = Transacao::textoCompraFromRow([
            'observacoes' => $ancora->observacoes,
            'descricao' => $ancora->descricao,
        ]);
        $detalhe['compra_manual'] = Transacao::isCompraManualRow([
            'tipo' => $ancora->tipo,
            'compra_manual' => $ancora->compra_manual,
            'importada_pdf' => $ancora->importada_pdf,
        ]);
        $detalhe['precisa_conciliar'] = Transacao::precisaConciliarRow([
            'tipo' => $ancora->tipo,
            'compra_manual' => $ancora->compra_manual,
            'importada_pdf' => $ancora->importada_pdf,
            'status_conciliacao' => $ancora->status_conciliacao,
        ]);
        $detalhe['precisa_conciliar_label'] = $detalhe['precisa_conciliar']
            ? Transacao::PRECISA_CONCILIAR_LABEL
            : null;

        $conciliacaoService = new ConciliacaoService();
        $vinculo = !$detalhe['compra_manual']
            ? $conciliacaoService->localizarVinculoDoLancamento($ancora)
            : null;
        $compraConciliacao = $vinculo ?: $ancora;
        $detalhe['compra_manual_vinculada'] = $vinculo
            ? $conciliacaoService->payloadVinculo($vinculo)
            : null;
        $detalhe['conciliada_com_manual'] = $vinculo
            && $vinculo->status_conciliacao === Transacao::CONCILIACAO_CONCILIADA;
        $detalhe['tem_sugestao_conciliacao'] = $vinculo
            && $vinculo->status_conciliacao === Transacao::CONCILIACAO_PENDENTE;
        $detalhe['conciliada_com_manual_label'] = $detalhe['conciliada_com_manual']
            ? Transacao::CONCILIADA_COM_MANUAL_LABEL
            : null;
        $detalhe['sugestao_conciliacao_label'] = $detalhe['tem_sugestao_conciliacao']
            ? Transacao::SUGESTAO_CONCILIACAO_LABEL . ' «' . (
                Transacao::textoCompraFromRow([
                    'observacoes' => $vinculo->observacoes,
                    'descricao' => $vinculo->descricao,
                ]) ?: 'esta compra'
            ) . '»'
            : null;
        $detalhe['conciliacao'] = $conciliacaoService->blocoVisualizacao($compraConciliacao);
        $detalhe['anexos'] = (new CompraAnexoService())->listarDaCompra($compraConciliacao);
    }

    /**
     * @return Collection<int, object>
     */
    private function loadParcelas(int $userId, string $identificador): Collection
    {
        $filtro = $this->resolveFiltro($userId, $identificador);

        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->leftJoin('lojas as loja', function ($join) {
                $join->on('loja.id', '=', 'est.loja_id')->whereNull('loja.deleted_at');
            })
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 't.categoria_id')->whereNull('cat.deleted_at');
            })
            ->leftJoin('subcategorias as sub', function ($join) {
                $join->on('sub.id', '=', 't.subcategoria_id')->whereNull('sub.deleted_at');
            })
            ->leftJoin('categoria_subcategoria as cs', function ($join) {
                $join->on('cs.categoria_id', '=', 't.categoria_id')
                    ->on('cs.subcategoria_id', '=', 't.subcategoria_id');
            })
            ->leftJoin('plataformas as plat', function ($join) {
                $join->on('plat.id', '=', 't.plataforma_id')->whereNull('plat.deleted_at');
            })
            ->leftJoin('responsaveis as resp', function ($join) {
                $join->on('resp.id', '=', 't.responsavel_id')->whereNull('resp.deleted_at');
            })
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_bandeiras as cb', function ($join) {
                $join->on('cb.id', '=', 'f.cartao_bandeira_id')->whereNull('cb.deleted_at');
            })
            ->leftJoin('cartao_numeros as cn', function ($join) {
                $join->on('cn.id', '=', 't.cartao_numero_id')->whereNull('cn.deleted_at');
            })
            ->where('t.user_id', $userId)
            ->whereNull('t.deleted_at')
            ->where('t.tipo', Transacao::TIPO_PURCHASE);

        if (isset($filtro['compra_grupo_id'])) {
            $query->where('t.compra_grupo_id', $filtro['compra_grupo_id']);
        } else {
            $query->where('t.id', $filtro['id']);
        }

        return $query
            ->select([
                't.id',
                't.compra_grupo_id',
                't.data',
                't.valor',
                't.valor_parcela',
                't.parcela_atual',
                't.parcelas_total',
                't.observacoes',
                't.descricao',
                't.descricao_fatura',
                't.status_conciliacao',
                't.lancamento_id',
                't.origem_compra',
                't.eh_assinatura',
                't.tipo',
                't.importada_pdf',
                't.compra_manual',
                't.categoria_id',
                't.subcategoria_id',
                't.plataforma_id',
                't.responsavel_id',
                't.estabelecimento_id',
                't.fatura_id',
                't.cartao_numero_id',
                'f.mes as fatura_mes',
                'f.ano as fatura_ano',
                'f.status as fatura_status',
                'f.cartao_id',
                'f.cartao_bandeira_id',
                'est.nome as estabelecimento_nome',
                'est.loja_id',
                'loja.nome as loja_nome',
                'cat.nome as categoria_nome',
                'cat.cor as categoria_cor',
                'sub.nome as subcategoria_nome',
                'cs.cor as subcategoria_cor',
                'plat.nome as plataforma_nome',
                'plat.cor as plataforma_cor',
                'resp.nome as responsavel_nome',
                'resp.tipo as responsavel_tipo',
                'c.nome as cartao_nome',
                'c.banco as cartao_banco',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'cb.bandeira as bandeira_nome',
                'cn.ultimos_digitos',
                'cn.tipo as cartao_numero_tipo',
                'cn.apelido as cartao_numero_apelido',
                'cn.nome_no_cartao as cartao_numero_nome_no_cartao',
            ])
            ->orderBy('t.parcela_atual')
            ->get();
    }

    /**
     * @return array{compra_grupo_id: string}|array{id: int}
     */
    private function resolveFiltro(int $userId, string $identificador): array
    {
        $identificador = trim($identificador);

        if (Str::isUuid($identificador)) {
            return ['compra_grupo_id' => $identificador];
        }

        if (!ctype_digit($identificador)) {
            throw new Exception('Compra não encontrada', 404);
        }

        $row = DB::table('transacoes')
            ->where('id', (int) $identificador)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->first(['id', 'compra_grupo_id']);

        if (!$row) {
            throw new Exception('Compra não encontrada', 404);
        }

        if (!empty($row->compra_grupo_id)) {
            return ['compra_grupo_id' => (string) $row->compra_grupo_id];
        }

        return ['id' => (int) $row->id];
    }

    /**
     * @param array<int> $transacaoIds
     * @return array<int, array{valor_pago: float, data_ultimo: ?string}>
     */
    private function loadRepassePagosMap(int $userId, array $transacaoIds): array
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
                DB::raw('SUM(valor) as valor_pago'),
                DB::raw('MAX(data_pagamento) as data_ultimo')
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->transacao_id] = [
                'valor_pago' => round((float) $row->valor_pago, 2),
                'data_ultimo' => $row->data_ultimo,
            ];
        }

        return $map;
    }

    /**
     * @return array{id: int, nome: string, loja_id: ?int, loja_nome: ?string}|null
     */
    private function mapEstabelecimento(object $meta): ?array
    {
        if ($meta->estabelecimento_id === null || $meta->estabelecimento_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->estabelecimento_id,
            'nome' => $meta->estabelecimento_nome,
            'loja_id' => $meta->loja_id !== null && $meta->loja_id !== '' ? (int) $meta->loja_id : null,
            'loja_nome' => $meta->loja_nome ?? null,
        ];
    }

    /**
     * @return array{id: int, nome: ?string, cor: string}|null
     */
    private function mapCategoria(object $meta): ?array
    {
        if ($meta->categoria_id === null || $meta->categoria_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->categoria_id,
            'nome' => $meta->categoria_nome ?? null,
            'cor' => CategoriaCoresTema::corParaGrafico(
                $meta->categoria_cor ?? null,
                $meta->categoria_id
            ),
        ];
    }

    /**
     * @return array{id: int, nome: ?string, cor: string}|null
     */
    private function mapSubcategoria(object $meta): ?array
    {
        if ($meta->subcategoria_id === null || $meta->subcategoria_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->subcategoria_id,
            'nome' => $meta->subcategoria_nome ?? null,
            'cor' => CategoriaCorVariacao::corLeitura(
                $meta->subcategoria_cor ?? null,
                CategoriaCoresTema::normalizar($meta->categoria_cor ?? null)
            ),
        ];
    }

    /**
     * @return array{id: int, nome: ?string, cor: string}|null
     */
    private function mapPlataforma(object $meta): ?array
    {
        if (($meta->plataforma_id ?? null) === null || $meta->plataforma_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->plataforma_id,
            'nome' => $meta->plataforma_nome ?? null,
            'cor' => CategoriaCoresTema::normalizar($meta->plataforma_cor ?? null),
        ];
    }

    /**
     * @return array{id: int, nome: ?string, tipo: ?string}|null
     */
    private function mapResponsavel(object $meta): ?array
    {
        if ($meta->responsavel_id === null || $meta->responsavel_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->responsavel_id,
            'nome' => $meta->responsavel_nome ?? null,
            'tipo' => $meta->responsavel_tipo ?? null,
        ];
    }

    /**
     * @return array{id: int, nome: ?string, banco: ?string, cor_fundo: ?string, cor_texto: ?string}|null
     */
    private function mapCartao(object $meta): ?array
    {
        if ($meta->cartao_id === null || $meta->cartao_id === '') {
            return null;
        }

        return [
            'id' => (int) $meta->cartao_id,
            'nome' => $meta->cartao_nome ?? null,
            'banco' => $meta->cartao_banco ?? null,
            'cor_fundo' => $meta->cartao_cor_fundo ?? null,
            'cor_texto' => $meta->cartao_cor_texto ?? null,
        ];
    }

    /**
     * @return array{id: int, nome: ?string, cor_principal: string, cor_secundaria: string}|null
     */
    private function mapBandeira(object $meta): ?array
    {
        if ($meta->cartao_bandeira_id === null || $meta->cartao_bandeira_id === '') {
            return null;
        }

        $cores = BandeiraCoresPreset::anexar($meta->bandeira_nome ?? null);

        return [
            'id' => (int) $meta->cartao_bandeira_id,
            'nome' => $meta->bandeira_nome ?? null,
            'cor_principal' => $cores['cor_principal'],
            'cor_secundaria' => $cores['cor_secundaria'],
        ];
    }

    /**
     * @return array{id: int, ultimos_digitos: ?string, tipo: ?string, tipo_label: ?string, apelido: ?string, nome_no_cartao: ?string}|null
     */
    private function mapCartaoNumero(object $meta): ?array
    {
        if (!isset($meta->cartao_numero_id) || $meta->cartao_numero_id === null || $meta->cartao_numero_id === '') {
            return null;
        }

        $tipo = $meta->cartao_numero_tipo ?? null;

        return [
            'id' => (int) $meta->cartao_numero_id,
            'ultimos_digitos' => $meta->ultimos_digitos ?? null,
            'tipo' => $tipo,
            'tipo_label' => $tipo !== null ? (self::CARTAO_NUMERO_TIPO_LABELS[$tipo] ?? $tipo) : null,
            'apelido' => $meta->cartao_numero_apelido ?? null,
            'nome_no_cartao' => $meta->cartao_numero_nome_no_cartao ?? null,
        ];
    }
}
