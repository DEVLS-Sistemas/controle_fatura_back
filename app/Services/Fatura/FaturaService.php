<?php

namespace App\Services\Fatura;

use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\PaginateService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FaturaService
{
    public function handleLookupsFatura(): array
    {
        $userId = Auth::id();

        return [
            'status' => [
                ['value' => 'pendente', 'label' => 'Pendente'],
                ['value' => 'processando', 'label' => 'Processando'],
                ['value' => 'processada', 'label' => 'Processada'],
                ['value' => 'erro', 'label' => 'Erro'],
            ],
            'cartoes' => Cartao::where('user_id', $userId)
                ->where('ativo', true)
                ->with(['bandeiras' => function ($q) {
                    $q->whereNull('deleted_at')
                        ->where('ativo', true)
                        ->orderBy('bandeira')
                        ->select('id', 'cartao_id', 'bandeira', 'limite_credito', 'ativo');
                }])
                ->orderBy('nome')
                ->get([
                    'id',
                    'nome',
                    'banco',
                    'dia_limite_fatura',
                    'dia_vencimento_fatura',
                    'cor_fundo',
                    'cor_texto',
                ]),
            'meses' => collect(range(1, 12))->map(fn ($m) => [
                'value' => $m,
                'label' => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
            ]),
        ];
    }

    public function handleAddFatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->createFatura($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditFatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->updateFatura($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteFatura(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->deleteFatura($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleUploadPdf(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->uploadPdf($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleProcessarPdf(int|string $id): object
    {
        try {
            $fatura = Fatura::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$fatura) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if (!$fatura->arquivo_pdf) {
                throw new Exception('Fatura sem arquivo para processar', 422);
            }

            $fatura->update([
                'status' => 'pendente',
                'erro_mensagem' => null,
            ]);

            $this->dispatchProcessamento($fatura->id);

            return (object) [
                'data' => $fatura->fresh(),
                'status' => true,
                'message' => 'Processamento da fatura iniciado!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function createFatura(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $this->validatePeriodo($atributes);
            $this->assertCartaoDoUsuario($atributes->cartao_id, $userId);
            $bandeiraId = $this->resolveCartaoBandeiraId(
                (int) $atributes->cartao_id,
                $userId,
                $atributes->cartao_bandeira_id ?? null
            );

            $existing = Fatura::where('user_id', $userId)
                ->where('cartao_bandeira_id', $bandeiraId)
                ->where('mes', (int) $atributes->mes)
                ->where('ano', (int) $atributes->ano)
                ->first();

            // Fatura já criada (ex.: parcela futura): com arquivo no request, anexa/substitui e processa.
            if ($existing) {
                $temArquivo = !empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile;

                if (!$temArquivo) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                $message = empty($existing->arquivo_pdf)
                    ? 'PDF anexado à fatura existente com sucesso!'
                    : 'PDF atualizado na fatura existente com sucesso!';

                return $this->attachPdfToFatura($existing, $atributes, $userId, $message);
            }

            $arquivoPath = null;
            $processar = false;

            if (!empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile) {
                $arquivoPath = $this->storePdf($atributes->arquivo_pdf, $userId);
                $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
            }

            $newData = new Fatura([
                'user_id' => $userId,
                'cartao_id' => $atributes->cartao_id,
                'cartao_bandeira_id' => $bandeiraId,
                'mes' => (int) $atributes->mes,
                'ano' => (int) $atributes->ano,
                'valor_total' => $atributes->valor_total ?? 0,
                'arquivo_pdf' => $arquivoPath,
                'status' => 'pendente',
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Fatura', 500);
            }

            if ($processar && $arquivoPath) {
                $this->dispatchProcessamento($newData->id);
            }

            return (object) [
                'data' => $newData->load('cartao'),
                'status' => true,
                'message' => 'Fatura cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateFatura(object $atributes): object
    {
        try {
            if (empty($atributes->id) && !empty($atributes->fatura_id)) {
                $atributes->id = $atributes->fatura_id;
            }

            if (empty($atributes->id)) {
                throw new Exception('ID da fatura é obrigatório', 422);
            }

            $record = Fatura::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if (!empty($atributes->cartao_id)) {
                $this->assertCartaoDoUsuario($atributes->cartao_id, Auth::id());
            }

            if (!empty($atributes->mes) || !empty($atributes->ano)
                || !empty($atributes->cartao_id) || !empty($atributes->cartao_bandeira_id)
            ) {
                $mes = (int) ($atributes->mes ?? $record->mes);
                $ano = (int) ($atributes->ano ?? $record->ano);
                $cartaoId = (int) ($atributes->cartao_id ?? $record->cartao_id);
                $bandeiraId = $this->resolveCartaoBandeiraId(
                    $cartaoId,
                    (int) Auth::id(),
                    $atributes->cartao_bandeira_id ?? $record->cartao_bandeira_id
                );

                if ($mes < 1 || $mes > 12) {
                    throw new Exception('Mês inválido', 422);
                }

                $exists = Fatura::where('user_id', Auth::id())
                    ->where('cartao_bandeira_id', $bandeiraId)
                    ->where('mes', $mes)
                    ->where('ano', $ano)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                $atributes->cartao_bandeira_id = $bandeiraId;
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id'], $data['fatura_id'], $data['arquivo_pdf'], $data['processar_automatico']);

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Fatura', 500);
            }

            return (object) [
                'data' => $record->fresh()->load('cartao'),
                'status' => true,
                'message' => 'Fatura alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteFatura(int|string $id): object
    {
        try {
            $record = Fatura::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if ($record->arquivo_pdf && Storage::disk('local')->exists($record->arquivo_pdf)) {
                Storage::disk('local')->delete($record->arquivo_pdf);
            }

            Transacao::where('fatura_id', $record->id)->delete();

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Fatura', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Fatura excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function uploadPdf(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da fatura é obrigatório', 422);
            }

            if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
                throw new Exception('Arquivo da fatura é obrigatório (PDF, CSV ou XML)', 422);
            }

            $record = Fatura::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            return $this->attachPdfToFatura(
                $record,
                $atributes,
                Auth::id(),
                'PDF enviado com sucesso!'
            );
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Lista faturas agrupadas por cartão (sem itens de transação).
     * Ordenação: competência (ano/mês desc) → cartão (nome) → status.
     * Paginação é por fatura; a página é reagrupada por cartão na resposta.
     */
    public function getFaturaPaginate(object $atributes): array
    {
        $userId = Auth::id();
        $page = max(1, (int) ($atributes->page ?? 1));
        $perPage = max(1, (int) ($atributes->perPage ?? 5));

        $faturasQuery = DB::table('faturas as ent')
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_bandeiras as cb', function ($join) {
                $join->on('cb.id', '=', 'ent.cartao_bandeira_id')->whereNull('cb.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', $userId)
            ->select(
                'ent.id',
                'ent.cartao_id',
                'ent.cartao_bandeira_id',
                'c.nome as cartao_nome',
                'c.banco as cartao_banco',
                'cb.bandeira as cartao_bandeira',
                'c.dia_limite_fatura as cartao_dia_limite_fatura',
                'c.dia_vencimento_fatura as cartao_dia_vencimento_fatura',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'c.ativo as cartao_ativo',
                'ent.mes',
                'ent.ano',
                'ent.valor_total',
                'ent.arquivo_pdf',
                'ent.status',
                'ent.erro_mensagem',
                'ent.processado_em',
                'ent.created_at',
                'ent.updated_at',
                DB::raw('(SELECT COUNT(*) FROM transacoes t WHERE t.fatura_id = ent.id AND t.deleted_at IS NULL) as total_transacoes'),
                DB::raw('(SELECT COUNT(*) FROM transacoes t
                    WHERE t.fatura_id = ent.id
                        AND t.deleted_at IS NULL
                        AND t.categoria_id IS NOT NULL) as transacoes_com_categoria'),
            )
            ->orderByDesc('ent.ano')
            ->orderByDesc('ent.mes')
            ->orderBy('c.nome')
            ->orderBy('ent.status');

        $this->applyFaturaListFilters($faturasQuery, $atributes);

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $faturasQuery,
            $page,
            $perPage,
            ['path' => $atributes->url ?? null, 'query' => $atributes->query ?? []]
        );
        $resultado->appends((array) $atributes);

        $faturas = collect($resultado->items());
        if ($faturas->isEmpty()) {
            return collect($resultado)->toArray();
        }

        $cartaoIds = $faturas->pluck('cartao_id')->unique()->values()->all();
        $cartaoModels = Cartao::whereIn('id', $cartaoIds)->get()->keyBy('id');

        $grupos = [];
        foreach ($faturas as $fatura) {
            $cartaoId = (int) $fatura->cartao_id;

            if (!isset($grupos[$cartaoId])) {
                $grupos[$cartaoId] = [
                    'cartao_id' => $cartaoId,
                    'nome' => $fatura->cartao_nome,
                    'banco' => $fatura->cartao_banco,
                    'dia_limite_fatura' => $fatura->cartao_dia_limite_fatura !== null
                        ? (int) $fatura->cartao_dia_limite_fatura
                        : null,
                    'dia_vencimento_fatura' => $fatura->cartao_dia_vencimento_fatura !== null
                        ? (int) $fatura->cartao_dia_vencimento_fatura
                        : null,
                    'cor_fundo' => $fatura->cartao_cor_fundo,
                    'cor_texto' => $fatura->cartao_cor_texto,
                    'ativo' => (bool) $fatura->cartao_ativo,
                    'total_faturas' => 0,
                    'valor_total' => 0.0,
                    'faturas' => [],
                ];
            }

            $model = $cartaoModels->get($cartaoId);
            $intervalo = $model
                ? $model->intervaloPeriodoFatura((int) $fatura->mes, (int) $fatura->ano)
                : [
                    'periodo_inicio' => null,
                    'periodo_fim' => null,
                    'data_vencimento' => null,
                ];

            $item = [
                'id' => (int) $fatura->id,
                'cartao_bandeira_id' => $fatura->cartao_bandeira_id !== null
                    ? (int) $fatura->cartao_bandeira_id
                    : null,
                'bandeira' => $fatura->cartao_bandeira,
                'mes' => (int) $fatura->mes,
                'ano' => (int) $fatura->ano,
                'competencia' => sprintf('%02d/%d', (int) $fatura->mes, (int) $fatura->ano),
                'periodo_inicio' => $intervalo['periodo_inicio'],
                'periodo_fim' => $intervalo['periodo_fim'],
                'data_vencimento' => $intervalo['data_vencimento'],
                'valor_total' => $fatura->valor_total,
                'arquivo_pdf' => $fatura->arquivo_pdf,
                'tem_pdf' => !empty($fatura->arquivo_pdf),
                'status' => $fatura->status,
                'erro_mensagem' => $fatura->erro_mensagem,
                'processado_em' => $fatura->processado_em,
                'total_transacoes' => (int) $fatura->total_transacoes,
                'transacoes_com_categoria' => (int) $fatura->transacoes_com_categoria,
                'created_at' => $fatura->created_at,
                'updated_at' => $fatura->updated_at,
            ];

            $grupos[$cartaoId]['faturas'][] = $item;
            $grupos[$cartaoId]['total_faturas']++;
            $grupos[$cartaoId]['valor_total'] = round(
                $grupos[$cartaoId]['valor_total'] + (float) $fatura->valor_total,
                2
            );
        }

        $resultado->setCollection(collect(array_values($grupos)));

        return collect($resultado)->toArray();
    }

    private function applyFaturaListFilters($query, object $atributes): void
    {
        if (!empty($atributes->cartao_id)) {
            $query->where('ent.cartao_id', $atributes->cartao_id);
        }

        if (!empty($atributes->cartao_bandeira_id)) {
            $query->where('ent.cartao_bandeira_id', $atributes->cartao_bandeira_id);
        }

        if (!empty($atributes->mes)) {
            $query->where('ent.mes', (int) $atributes->mes);
        }

        if (!empty($atributes->ano)) {
            $query->where('ent.ano', (int) $atributes->ano);
        }

        if (!empty($atributes->status)) {
            $query->where('ent.status', $atributes->status);
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('c.nome', 'like', '%' . $chave . '%')
                    ->orWhere('c.banco', 'like', '%' . $chave . '%')
                    ->orWhere('ent.status', 'like', '%' . $chave . '%');
            });
        }
    }

    public function getFaturaId(int|string $id): array
    {
        try {
            $query = DB::table('faturas as ent')
                ->leftJoin('cartoes as c', function ($join) {
                    $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
                })
                ->leftJoin('cartao_bandeiras as cb', function ($join) {
                    $join->on('cb.id', '=', 'ent.cartao_bandeira_id')->whereNull('cb.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.cartao_id',
                    'ent.cartao_bandeira_id',
                    'c.nome as cartao_nome',
                    'cb.bandeira as cartao_bandeira',
                    'c.cor_fundo as cartao_cor_fundo',
                    'c.cor_texto as cartao_cor_texto',
                    'c.dia_limite_fatura as cartao_dia_limite_fatura',
                    'c.dia_vencimento_fatura as cartao_dia_vencimento_fatura',
                    'ent.mes',
                    'ent.ano',
                    'ent.valor_total',
                    'ent.arquivo_pdf',
                    'ent.status',
                    'ent.erro_mensagem',
                    'ent.processado_em',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Fatura não encontrada', 404);
            }

            $result = collect($data)->toArray();
            $result['mes'] = (int) $result['mes'];
            $result['ano'] = (int) $result['ano'];
            $result['competencia'] = sprintf('%02d/%d', $result['mes'], $result['ano']);

            $cartao = Cartao::find($result['cartao_id']);
            $intervalo = $cartao
                ? $cartao->intervaloPeriodoFatura($result['mes'], $result['ano'])
                : [
                    'periodo_inicio' => null,
                    'periodo_fim' => null,
                    'data_vencimento' => null,
                ];

            $result['periodo_inicio'] = $intervalo['periodo_inicio'];
            $result['periodo_fim'] = $intervalo['periodo_fim'];
            $result['data_vencimento'] = $intervalo['data_vencimento'];
            $result['total_transacoes'] = DB::table('transacoes')
                ->where('fatura_id', $id)
                ->whereNull('deleted_at')
                ->count();
            $result['transacoes_com_categoria'] = DB::table('transacoes as t')
                ->where('t.fatura_id', $id)
                ->whereNull('t.deleted_at')
                ->whereNotNull('t.categoria_id')
                ->count();
            $result['tem_pdf'] = !empty($result['arquivo_pdf']);
            $result['pdf_url'] = !empty($result['arquivo_pdf'])
                ? url('/api/v1/faturas/pdf/' . $id)
                : null;

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function downloadPdf(int|string $id)
    {
        $fatura = Fatura::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$fatura) {
            throw new Exception('Fatura não encontrada', 404);
        }

        if (!$fatura->arquivo_pdf || !Storage::disk('local')->exists($fatura->arquivo_pdf)) {
            throw new Exception('Arquivo PDF não encontrado', 404);
        }

        return Storage::disk('local')->path($fatura->arquivo_pdf);
    }

    public function getFaturaAsync(object $params): array
    {
        $query = DB::table('faturas as ent')
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select(
                'ent.id',
                DB::raw("CONCAT(c.nome, ' - ', LPAD(ent.mes, 2, '0'), '/', ent.ano) as nome"),
                'ent.mes',
                'ent.ano',
                'ent.status'
            );

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('c.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.ano', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderByDesc('ent.ano')->orderByDesc('ent.mes')->get()->toArray();
    }

    /**
     * Recalcula valor_total a partir das transações da fatura (compras manuais e PDF).
     * Usa a mesma regra de saldo do ProcessInvoicePdfJob (pagamentos, estornos, residual).
     */
    public function recalculateValorTotal(int $faturaId): float
    {
        $fatura = Fatura::find($faturaId);
        if (!$fatura) {
            return 0.0;
        }

        $transactions = Transacao::where('fatura_id', $faturaId)
            ->get(['valor', 'tipo'])
            ->map(fn (Transacao $t) => [
                'valor' => (float) $t->valor,
                'tipo' => $t->tipo,
            ])
            ->all();

        // Residual da fatura anterior só no fechamento do extrato (PDF/processada).
        // Faturas pendentes (compras manuais) refletem só o saldo do ciclo.
        $previousTotal = $fatura->status === 'processada'
            ? ProcessInvoicePdfJob::resolvePreviousFaturaTotal($fatura)
            : null;

        $valorTotal = ProcessInvoicePdfJob::calculateValorTotal($transactions, $previousTotal);

        $fatura->update(['valor_total' => $valorTotal]);

        return $valorTotal;
    }

    /**
     * @param array<int, int|string|null> $faturaIds
     */
    public function recalculateValorTotalMany(array $faturaIds): void
    {
        foreach (array_unique(array_filter($faturaIds)) as $faturaId) {
            $this->recalculateValorTotal((int) $faturaId);
        }
    }

    /**
     * Localiza fatura da bandeira no período ou cria (status pendente).
     * Usado no cadastro de compra via cartao_id + data.
     */
    public function findOrCreateByCartaoPeriodo(
        int $userId,
        int $cartaoId,
        int $mes,
        int $ano,
        ?int $cartaoBandeiraId = null
    ): Fatura {
        $this->assertCartaoDoUsuario($cartaoId, $userId);
        $bandeiraId = $this->resolveCartaoBandeiraId($cartaoId, $userId, $cartaoBandeiraId);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mês inválido', 422);
        }

        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }

        $fatura = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->where('cartao_bandeira_id', $bandeiraId)
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->first();

        if ($fatura) {
            if ($fatura->trashed()) {
                $fatura->restore();
            }

            return $fatura;
        }

        return Fatura::create([
            'user_id' => $userId,
            'cartao_id' => $cartaoId,
            'cartao_bandeira_id' => $bandeiraId,
            'mes' => $mes,
            'ano' => $ano,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
    }

    /**
     * Resolve a bandeira da fatura.
     * Se não informada e o cartão tiver exatamente uma bandeira ativa, usa essa.
     */
    public function resolveCartaoBandeiraId(int $cartaoId, int $userId, mixed $bandeiraId = null): int
    {
        $this->assertCartaoDoUsuario($cartaoId, $userId);

        if (!empty($bandeiraId)) {
            $exists = CartaoBandeira::where('id', $bandeiraId)
                ->where('cartao_id', $cartaoId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$exists) {
                throw new Exception('Bandeira inválida para este cartão', 422);
            }

            return (int) $bandeiraId;
        }

        $bandeiras = CartaoBandeira::where('cartao_id', $cartaoId)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->orderBy('id')
            ->get(['id']);

        if ($bandeiras->isEmpty()) {
            throw new Exception('Cadastre ao menos uma bandeira neste cartão', 422);
        }

        if ($bandeiras->count() > 1) {
            throw new Exception('Selecione a bandeira da fatura', 422);
        }

        return (int) $bandeiras->first()->id;
    }

    /**
     * Anexa arquivo à fatura (substitui o anterior, se houver) e opcionalmente dispara o processamento.
     */
    private function attachPdfToFatura(
        Fatura $fatura,
        object $atributes,
        int $userId,
        string $message = 'PDF anexado à fatura existente com sucesso!'
    ): object {
        if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
            throw new Exception('Arquivo da fatura é obrigatório (PDF, CSV ou XML)', 422);
        }

        if ($fatura->arquivo_pdf && Storage::disk('local')->exists($fatura->arquivo_pdf)) {
            Storage::disk('local')->delete($fatura->arquivo_pdf);
        }

        $path = $this->storePdf($atributes->arquivo_pdf, $userId);

        $fatura->update([
            'arquivo_pdf' => $path,
            'status' => 'pendente',
            'erro_mensagem' => null,
            'processado_em' => null,
        ]);

        $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($processar) {
            $this->dispatchProcessamento($fatura->id);
        }

        return (object) [
            'data' => $fatura->fresh()->load('cartao'),
            'status' => true,
            'message' => $message,
        ];
    }

    private function validatePeriodo(object $atributes): void
    {
        if (empty($atributes->cartao_id)) {
            throw new Exception('Cartão é obrigatório', 422);
        }

        if (empty($atributes->mes) || empty($atributes->ano)) {
            throw new Exception('Mês e ano são obrigatórios', 422);
        }

        $mes = (int) $atributes->mes;
        $ano = (int) $atributes->ano;

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mês inválido', 422);
        }

        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }
    }

    private function assertCartaoDoUsuario(int|string $cartaoId, int $userId): void
    {
        $exists = Cartao::where('id', $cartaoId)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            throw new Exception('Cartão não encontrado', 404);
        }
    }

    private function storePdf(UploadedFile $file, int $userId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());

        $allowedExtensions = ['pdf', 'csv', 'xml', 'txt'];
        $allowedMimes = [
            'application/pdf',
            'text/csv',
            'text/plain',
            'text/xml',
            'application/xml',
            'application/vnd.ms-excel',
        ];

        if (!in_array($extension, $allowedExtensions, true) && !in_array($mime, $allowedMimes, true)) {
            throw new Exception('O arquivo deve ser PDF, CSV ou XML', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new Exception('O arquivo deve ter no máximo 10MB', 422);
        }

        // CSVs do Inter/Excel costumam chegar como text/plain → Laravel salva .txt
        // e o parser rejeita. Normaliza a extensão antes de persistir.
        $extension = $this->resolveInvoiceExtension($extension, $mime);

        $filename = Str::random(40) . '.' . $extension;

        return $file->storeAs("faturas/{$userId}", $filename, 'local');
    }

    private function resolveInvoiceExtension(string $extension, string $mime): string
    {
        if (in_array($extension, ['pdf', 'csv', 'xml'], true)) {
            return $extension;
        }

        if (str_contains($mime, 'pdf') || $extension === 'pdf') {
            return 'pdf';
        }

        if (str_contains($mime, 'xml') || $extension === 'xml') {
            return 'xml';
        }

        // text/plain, application/vnd.ms-excel, .txt → CSV de fatura
        if (
            in_array($extension, ['txt', 'csv', ''], true)
            || in_array($mime, ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'], true)
        ) {
            return 'csv';
        }

        return $extension !== '' ? $extension : 'csv';
    }

    /**
     * Com QUEUE_CONNECTION=sync, falha do job virava 422 no cadastro/upload.
     * O job já grava status=erro; o cadastro deve seguir.
     */
    private function dispatchProcessamento(int $faturaId): void
    {
        try {
            ProcessInvoicePdfJob::dispatch($faturaId);
        } catch (Exception $e) {
            Log::warning('Processamento automático da fatura falhou', [
                'fatura_id' => $faturaId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
