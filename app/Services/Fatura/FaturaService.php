<?php

namespace App\Services\Fatura;

use App\Exceptions\PdfPasswordException;
use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\PaginateService;
use App\Services\Pdf\PdfSenhaRegra;
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
                ->get()
                ->map(fn (Cartao $c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'banco' => $c->banco,
                    'dia_limite_fatura' => $c->dia_limite_fatura,
                    'dia_vencimento_fatura' => $c->dia_vencimento_fatura,
                    'cor_fundo' => $c->cor_fundo,
                    'cor_texto' => $c->cor_texto,
                    'tem_senha_pdf' => $c->temSenhaPdf(),
                    'senha_pdf_regra' => $c->senha_pdf_regra,
                    'senha_pdf_orientacao' => PdfSenhaRegra::orientacao($c->senha_pdf_regra),
                    'bandeiras' => $c->bandeiras->map(fn (CartaoBandeira $b) => [
                        'id' => $b->id,
                        'cartao_id' => $b->cartao_id,
                        'bandeira' => $b->bandeira,
                        'limite_credito' => $b->limite_credito,
                        'ativo' => (bool) $b->ativo,
                    ])->values()->all(),
                ])->values()->all(),
            'meses' => collect(range(1, 12))->map(fn ($m) => [
                'value' => $m,
                'label' => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
            ]),
            'senhas_pdf_regras' => PdfSenhaRegra::all(),
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

    /**
     * Soft-delete de todas as faturas e transações do usuário autenticado.
     * Útil para resetar dados em testes. Exige confirmar=true.
     */
    public function handleDeleteTodasFaturas(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->deleteTodasFaturas($atributes);

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

    public function handleProcessarPdf(int|string $id, ?object $atributes = null): object
    {
        try {
            $fatura = Fatura::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$fatura) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if (!$fatura->arquivo_pdf && !$fatura->arquivo_csv) {
                throw new Exception('Fatura sem arquivo para processar', 422);
            }

            $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
            $salvarSenha = filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN);

            $fatura->update([
                'status' => 'pendente',
                'erro_mensagem' => null,
                'erro_codigo' => null,
            ]);

            $this->dispatchProcessamento(
                $fatura->id,
                null,
                $senhaPdf,
                $salvarSenha,
                rethrowSenha: true
            );

            return $this->buildFaturaProcessamentoResponse(
                $fatura->fresh(['cartao']),
                'Processamento da fatura iniciado!'
            );
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

            $existingQuery = Fatura::where('user_id', $userId)
                ->where('cartao_id', (int) $atributes->cartao_id)
                ->where('mes', (int) $atributes->mes)
                ->where('ano', (int) $atributes->ano);
            if ($bandeiraId !== null) {
                $existingQuery->where('cartao_bandeira_id', $bandeiraId);
            } else {
                $existingQuery->whereNull('cartao_bandeira_id');
            }
            $existing = $existingQuery->first();

            // Fatura já criada (ex.: parcela futura): com arquivo no request, anexa/substitui e processa.
            if ($existing) {
                $temArquivo = !empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile;

                if (!$temArquivo) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                $tipoAnexo = $this->resolveAnexoTipo($atributes->arquivo_pdf);
                $jaTem = $tipoAnexo === 'pdf'
                    ? !empty($existing->arquivo_pdf)
                    : !empty($existing->arquivo_csv);
                $rotulo = $tipoAnexo === 'pdf' ? 'PDF' : 'CSV';
                $message = $jaTem
                    ? "{$rotulo} atualizado na fatura existente com sucesso!"
                    : "{$rotulo} anexado à fatura existente com sucesso!";

                return $this->attachPdfToFatura($existing, $atributes, $userId, $message);
            }

            $arquivoPdfPath = null;
            $arquivoCsvPath = null;
            $tipoAnexo = null;
            $processar = false;

            if (!empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile) {
                $tipoAnexo = $this->resolveAnexoTipo($atributes->arquivo_pdf);
                $path = $this->storePdf($atributes->arquivo_pdf, $userId);
                if ($tipoAnexo === 'pdf') {
                    $arquivoPdfPath = $path;
                } else {
                    $arquivoCsvPath = $path;
                }
                $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
            }

            $newData = new Fatura([
                'user_id' => $userId,
                'cartao_id' => $atributes->cartao_id,
                'cartao_bandeira_id' => $bandeiraId,
                'mes' => (int) $atributes->mes,
                'ano' => (int) $atributes->ano,
                'valor_total' => $atributes->valor_total ?? 0,
                'arquivo_pdf' => $arquivoPdfPath,
                'arquivo_csv' => $arquivoCsvPath,
                'status' => 'pendente',
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Fatura', 500);
            }

            if ($processar && ($arquivoPdfPath || $arquivoCsvPath)) {
                $this->dispatchProcessamento(
                    $newData->id,
                    $tipoAnexo,
                    $this->extractSenhaPdfFromRequest($atributes),
                    filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN)
                );
            }

            return $this->buildFaturaProcessamentoResponse(
                $newData->fresh(['cartao']),
                'Fatura cadastrada com sucesso!'
            );
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

                $existsQuery = Fatura::where('user_id', Auth::id())
                    ->where('cartao_id', $cartaoId)
                    ->where('mes', $mes)
                    ->where('ano', $ano)
                    ->where('id', '!=', $record->id);
                if ($bandeiraId !== null) {
                    $existsQuery->where('cartao_bandeira_id', $bandeiraId);
                } else {
                    $existsQuery->whereNull('cartao_bandeira_id');
                }

                if ($existsQuery->exists()) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                $atributes->cartao_bandeira_id = $bandeiraId;
            }

            $data = get_object_vars($atributes);
            unset(
                $data['user_id'],
                $data['id'],
                $data['fatura_id'],
                $data['arquivo_pdf'],
                $data['arquivo_csv'],
                $data['processar_automatico']
            );

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

            $this->deleteStoredAnexo($record->arquivo_pdf);
            $this->deleteStoredAnexo($record->arquivo_csv);

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

    public function deleteTodasFaturas(object $atributes): object
    {
        $confirmado = filter_var($atributes->confirmar ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$confirmado) {
            throw new Exception('Envie confirmar=true para excluir todas as faturas e transações', 422);
        }

        $userId = Auth::id();
        if (!$userId) {
            throw new Exception('Não autenticado', 401);
        }

        $faturas = Fatura::where('user_id', $userId)->get(['id', 'arquivo_pdf', 'arquivo_csv']);

        foreach ($faturas as $fatura) {
            $this->deleteStoredAnexo($fatura->arquivo_pdf);
            $this->deleteStoredAnexo($fatura->arquivo_csv);
        }

        $transacoesExcluidas = Transacao::where('user_id', $userId)->delete();
        $faturasExcluidas = Fatura::where('user_id', $userId)->delete();

        return (object) [
            'data' => [
                'faturas_excluidas' => (int) $faturasExcluidas,
                'transacoes_excluidas' => (int) $transacoesExcluidas,
            ],
            'status' => true,
            'message' => 'Todas as faturas e transações foram excluídas com sucesso!',
        ];
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
                'ent.arquivo_csv',
                'ent.status',
                'ent.erro_mensagem',
                'ent.erro_codigo',
                'c.senha_pdf_regra as cartao_senha_pdf_regra',
                DB::raw('(c.senha_pdf IS NOT NULL) as cartao_tem_senha_pdf'),
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

            $item = array_merge([
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
                'status' => $fatura->status,
                'erro_mensagem' => $fatura->erro_mensagem,
                'erro_codigo' => $fatura->erro_codigo,
                'processado_em' => $fatura->processado_em,
                'total_transacoes' => (int) $fatura->total_transacoes,
                'transacoes_com_categoria' => (int) $fatura->transacoes_com_categoria,
                'created_at' => $fatura->created_at,
                'updated_at' => $fatura->updated_at,
                'senha_pdf' => $this->buildSenhaPdfMeta(
                    $fatura->erro_codigo,
                    (int) $fatura->cartao_id,
                    $fatura->cartao_senha_pdf_regra ?? null,
                    (bool) ($fatura->cartao_tem_senha_pdf ?? false)
                ),
                'precisa_senha_pdf' => $this->isSenhaPdfErro($fatura->erro_codigo),
            ], $this->buildAnexoMeta(
                $fatura->arquivo_pdf,
                $fatura->arquivo_csv ?? null,
                (int) $fatura->id
            ));

            $grupos[$cartaoId]['faturas'][] = $item;
            $grupos[$cartaoId]['total_faturas']++;
            $grupos[$cartaoId]['valor_total'] = round(
                $grupos[$cartaoId]['valor_total'] + (float) $fatura->valor_total,
                2
            );
        }

        $pagamentoById = $this->resolvePagamentoStatusByFaturaIds(
            $faturas->map(fn ($f) => [
                'id' => (int) $f->id,
                'cartao_id' => (int) $f->cartao_id,
                'cartao_bandeira_id' => $f->cartao_bandeira_id !== null
                    ? (int) $f->cartao_bandeira_id
                    : null,
                'mes' => (int) $f->mes,
                'ano' => (int) $f->ano,
                'valor_total' => (float) $f->valor_total,
            ])->all(),
            (int) $userId
        );

        foreach ($grupos as $cartaoId => $grupo) {
            foreach ($grupo['faturas'] as $index => $item) {
                $pagamento = $pagamentoById[$item['id']] ?? ProcessInvoicePdfJob::buildPagamentoStatus(
                    (float) $item['valor_total'],
                    0.0
                );
                $grupos[$cartaoId]['faturas'][$index] = array_merge($item, $pagamento);
            }
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
                    'ent.arquivo_csv',
                    'ent.status',
                    'ent.erro_mensagem',
                    'ent.erro_codigo',
                    'c.senha_pdf_regra as cartao_senha_pdf_regra',
                    DB::raw('(c.senha_pdf IS NOT NULL) as cartao_tem_senha_pdf'),
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
            $result = array_merge($result, $this->buildAnexoMeta(
                $result['arquivo_pdf'] ?? null,
                $result['arquivo_csv'] ?? null,
                (int) $id
            ));
            $result['senha_pdf'] = $this->buildSenhaPdfMeta(
                $result['erro_codigo'] ?? null,
                (int) $result['cartao_id'],
                $result['cartao_senha_pdf_regra'] ?? null,
                (bool) ($result['cartao_tem_senha_pdf'] ?? false)
            );
            $result['precisa_senha_pdf'] = $this->isSenhaPdfErro($result['erro_codigo'] ?? null);
            unset($result['cartao_senha_pdf_regra'], $result['cartao_tem_senha_pdf']);
            $result['grupos_por_cartao'] = $this->buildGruposPorCartao((int) $id);

            $faturaId = (int) $result['id'];
            $pagamentoById = $this->resolvePagamentoStatusByFaturaIds(
                [[
                    'id' => $faturaId,
                    'cartao_id' => (int) $result['cartao_id'],
                    'cartao_bandeira_id' => $result['cartao_bandeira_id'] !== null
                        ? (int) $result['cartao_bandeira_id']
                        : null,
                    'mes' => (int) $result['mes'],
                    'ano' => (int) $result['ano'],
                    'valor_total' => (float) $result['valor_total'],
                ]],
                (int) Auth::id()
            );
            $pagamento = $pagamentoById[$faturaId]
                ?? ProcessInvoicePdfJob::buildPagamentoStatus((float) $result['valor_total'], 0.0);
            $result = array_merge($result, $pagamento);

            $pagamentosTotal = $this->sumPagamentosByFaturaIds([$faturaId])[$faturaId] ?? 0.0;
            $faturaModel = Fatura::find($faturaId);
            $previousTotal = $faturaModel
                ? ProcessInvoicePdfJob::resolvePreviousFaturaTotal($faturaModel)
                : null;
            $alocacao = ProcessInvoicePdfJob::allocatePayments(
                $pagamentosTotal,
                (float) ($previousTotal ?? 0)
            );
            $result['pagamentos_total'] = round($pagamentosTotal, 2);
            $result['pagamentos_abatido_anterior'] = $alocacao['applied_to_previous'];
            $result['pagamentos_antecipado'] = $alocacao['applied_to_current'];

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function downloadPdf(int|string $id)
    {
        return $this->downloadAnexo($id, 'pdf');
    }

    public function downloadCsv(int|string $id)
    {
        return $this->downloadAnexo($id, 'csv');
    }

    /**
     * @param  'pdf'|'csv'  $tipo
     */
    private function downloadAnexo(int|string $id, string $tipo): string
    {
        $fatura = Fatura::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$fatura) {
            throw new Exception('Fatura não encontrada', 404);
        }

        $relative = $tipo === 'pdf' ? $fatura->arquivo_pdf : $fatura->arquivo_csv;
        $label = $tipo === 'pdf' ? 'PDF' : 'CSV';

        if (!$relative || !Storage::disk('local')->exists($relative)) {
            throw new Exception("Arquivo {$label} não encontrado", 404);
        }

        return Storage::disk('local')->path($relative);
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

        // Residual da fatura anterior só no fechamento do extrato (PDF/processada),
        // e apenas se a anterior também estiver processada (não stub de parcela).
        // Faturas pendentes (compras manuais) refletem só o saldo do ciclo.
        $previousTotal = $fatura->status === 'processada'
            ? ProcessInvoicePdfJob::resolvePreviousFaturaTotal($fatura)
            : null;

        $valorTotal = ProcessInvoicePdfJob::calculateValorTotal($transactions, $previousTotal);

        $fatura->update(['valor_total' => $valorTotal]);

        return $valorTotal;
    }

    /**
     * Agrupa transações da fatura por final do cartão (para a view de detalhe).
     *
     * @return list<array<string, mixed>>
     */
    private function buildGruposPorCartao(int $faturaId): array
    {
        $rows = DB::table('transacoes as t')
            ->leftJoin('cartao_numeros as cn', function ($join) {
                $join->on('cn.id', '=', 't.cartao_numero_id')->whereNull('cn.deleted_at');
            })
            ->where('t.fatura_id', $faturaId)
            ->whereNull('t.deleted_at')
            ->select(
                'cn.id as cartao_numero_id',
                'cn.ultimos_digitos',
                'cn.tipo',
                'cn.apelido',
                'cn.nome_no_cartao',
                DB::raw('COUNT(*) as total_transacoes'),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total')
            )
            ->groupBy('cn.id', 'cn.ultimos_digitos', 'cn.tipo', 'cn.apelido', 'cn.nome_no_cartao')
            ->orderByRaw('cn.ultimos_digitos IS NULL')
            ->orderBy('cn.ultimos_digitos')
            ->get();

        return $rows->map(function ($row) {
            $ultimos = $row->ultimos_digitos;
            if ($ultimos) {
                $label = '•••• ' . $ultimos;
                if (!empty($row->nome_no_cartao)) {
                    $label .= ' · ' . $row->nome_no_cartao;
                } elseif (!empty($row->apelido)) {
                    $label .= ' · ' . $row->apelido;
                }
            } else {
                $label = 'Sem cartão identificado';
            }

            return [
                'cartao_numero_id' => $row->cartao_numero_id !== null ? (int) $row->cartao_numero_id : null,
                'ultimos_digitos' => $ultimos,
                'tipo' => $row->tipo,
                'apelido' => $row->apelido,
                'nome_no_cartao' => $row->nome_no_cartao,
                'label' => $label,
                'total_transacoes' => (int) $row->total_transacoes,
                'valor_total' => round((float) $row->valor_total, 2),
            ];
        })->all();
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
     * Para cada fatura, calcula quitação com os pagamentos da competência seguinte
     * (mesma bandeira; fallback para o grupo do cartão).
     *
     * @param  list<array{id:int,cartao_id:int,cartao_bandeira_id:?int,mes:int,ano:int,valor_total:float}>  $faturas
     * @return array<int, array{pago: bool, valor_pago: float, valor_restante: float}>
     */
    private function resolvePagamentoStatusByFaturaIds(array $faturas, int $userId): array
    {
        if ($faturas === []) {
            return [];
        }

        $nextKeys = [];
        foreach ($faturas as $fatura) {
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura['mes'],
                (int) $fatura['ano']
            );
            $scopeKey = $fatura['cartao_bandeira_id'] !== null
                ? 'b:' . $fatura['cartao_bandeira_id']
                : 'c:' . $fatura['cartao_id'];
            $nextKeys[$scopeKey . ':' . $nextMes . ':' . $nextAno] = [
                'cartao_id' => (int) $fatura['cartao_id'],
                'cartao_bandeira_id' => $fatura['cartao_bandeira_id'],
                'mes' => $nextMes,
                'ano' => $nextAno,
            ];
        }

        $nextFaturasQuery = Fatura::query()
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
            });

        $nextFaturas = $nextFaturasQuery->get(['id', 'cartao_id', 'cartao_bandeira_id', 'mes', 'ano']);
        $nextIdByKey = [];
        foreach ($nextFaturas as $next) {
            $scopeKey = $next->cartao_bandeira_id !== null
                ? 'b:' . $next->cartao_bandeira_id
                : 'c:' . $next->cartao_id;
            $nextIdByKey[$scopeKey . ':' . $next->mes . ':' . $next->ano] = (int) $next->id;
        }

        $paymentSums = $this->sumPagamentosByFaturaIds($nextFaturas->pluck('id')->all());

        $result = [];
        foreach ($faturas as $fatura) {
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura['mes'],
                (int) $fatura['ano']
            );
            $scopeKey = $fatura['cartao_bandeira_id'] !== null
                ? 'b:' . $fatura['cartao_bandeira_id']
                : 'c:' . $fatura['cartao_id'];
            $nextId = $nextIdByKey[$scopeKey . ':' . $nextMes . ':' . $nextAno] ?? null;
            $pagamentosNext = $nextId !== null ? ($paymentSums[$nextId] ?? 0.0) : 0.0;

            $result[(int) $fatura['id']] = ProcessInvoicePdfJob::buildPagamentoStatus(
                (float) $fatura['valor_total'],
                $pagamentosNext
            );
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $faturaIds
     * @return array<int, float>
     */
    private function sumPagamentosByFaturaIds(array $faturaIds): array
    {
        $faturaIds = array_values(array_unique(array_filter(array_map('intval', $faturaIds))));
        if ($faturaIds === []) {
            return [];
        }

        return DB::table('transacoes')
            ->whereIn('fatura_id', $faturaIds)
            ->where('tipo', Transacao::TIPO_PAYMENT)
            ->whereNull('deleted_at')
            ->groupBy('fatura_id')
            ->selectRaw('fatura_id, COALESCE(SUM(valor), 0) as total')
            ->pluck('total', 'fatura_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
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

        $faturaQuery = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->where('cartao_id', $cartaoId)
            ->where('mes', $mes)
            ->where('ano', $ano);
        if ($bandeiraId !== null) {
            $faturaQuery->where('cartao_bandeira_id', $bandeiraId);
        } else {
            $faturaQuery->whereNull('cartao_bandeira_id');
        }
        $fatura = $faturaQuery->first();

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
     * Se o cartão não tiver bandeiras, retorna null (cartao_bandeira_id é opcional).
     */
    public function resolveCartaoBandeiraId(int $cartaoId, int $userId, mixed $bandeiraId = null): ?int
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
            return null;
        }

        if ($bandeiras->count() > 1) {
            throw new Exception('Selecione a bandeira da fatura', 422);
        }

        return (int) $bandeiras->first()->id;
    }

    /**
     * Anexa arquivo à fatura.
     * PDF e CSV convivem: só substitui o anexo do mesmo tipo.
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

        $tipoAnexo = $this->resolveAnexoTipo($atributes->arquivo_pdf);
        $path = $this->storePdf($atributes->arquivo_pdf, $userId);

        $update = [
            'status' => 'pendente',
            'erro_mensagem' => null,
            'erro_codigo' => null,
            'processado_em' => null,
        ];

        if ($tipoAnexo === 'pdf') {
            $this->deleteStoredAnexo($fatura->arquivo_pdf);
            $update['arquivo_pdf'] = $path;
        } else {
            $this->deleteStoredAnexo($fatura->arquivo_csv);
            $update['arquivo_csv'] = $path;
        }

        $fatura->update($update);

        $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($processar) {
            $this->dispatchProcessamento(
                $fatura->id,
                $tipoAnexo,
                $this->extractSenhaPdfFromRequest($atributes),
                filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        }

        return $this->buildFaturaProcessamentoResponse(
            $fatura->fresh(['cartao']),
            $message
        );
    }

    /**
     * @return array{
     *   arquivo_pdf: ?string,
     *   arquivo_csv: ?string,
     *   tipo_arquivo: ?string,
     *   tem_pdf: bool,
     *   tem_csv: bool,
     *   pdf_url: ?string,
     *   csv_url: ?string
     * }
     */
    private function buildAnexoMeta(?string $arquivoPdf, ?string $arquivoCsv, int $faturaId): array
    {
        $temPdf = !empty($arquivoPdf);
        $temCsv = !empty($arquivoCsv);

        return [
            'arquivo_pdf' => $arquivoPdf,
            'arquivo_csv' => $arquivoCsv,
            'tipo_arquivo' => $temPdf ? 'pdf' : ($temCsv ? 'csv' : null),
            'tem_pdf' => $temPdf,
            'tem_csv' => $temCsv,
            'pdf_url' => $temPdf ? url('/api/v1/faturas/pdf/' . $faturaId) : null,
            'csv_url' => $temCsv ? url('/api/v1/faturas/csv/' . $faturaId) : null,
        ];
    }

    private function deleteStoredAnexo(?string $relativePath): void
    {
        if ($relativePath && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    /**
     * @return 'pdf'|'csv'
     */
    private function resolveAnexoTipo(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());
        $resolved = $this->resolveInvoiceExtension($extension, $mime);

        return $resolved === 'pdf' ? 'pdf' : 'csv';
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
     * O job já grava status=erro; o cadastro deve seguir (exceto rethrowSenha no reprocessar).
     */
    private function dispatchProcessamento(
        int $faturaId,
        ?string $arquivoPreferido = null,
        ?string $senhaPdf = null,
        bool $salvarSenhaPdf = false,
        bool $rethrowSenha = false
    ): void {
        try {
            ProcessInvoicePdfJob::dispatch($faturaId, $arquivoPreferido, $senhaPdf, $salvarSenhaPdf);
        } catch (PdfPasswordException $e) {
            if ($rethrowSenha) {
                throw $e;
            }

            Log::warning('Processamento automático da fatura aguarda senha do PDF', [
                'fatura_id' => $faturaId,
                'motivo' => $e->motivo,
            ]);
        } catch (Exception $e) {
            Log::warning('Processamento automático da fatura falhou', [
                'fatura_id' => $faturaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractSenhaPdfFromRequest(?object $atributes): ?string
    {
        if (!$atributes || !isset($atributes->senha_pdf)) {
            return null;
        }

        $senha = trim((string) $atributes->senha_pdf);

        return $senha === '' ? null : $senha;
    }

    private function buildFaturaProcessamentoResponse(Fatura $fatura, string $message): object
    {
        $cartao = $fatura->relationLoaded('cartao') ? $fatura->cartao : $fatura->cartao()->first();
        $data = $fatura->toArray();
        unset($data['cartao']);

        $data = array_merge($data, $this->buildAnexoMeta(
            $fatura->arquivo_pdf,
            $fatura->arquivo_csv,
            (int) $fatura->id
        ));

        $data['senha_pdf'] = $this->buildSenhaPdfMeta(
            $fatura->erro_codigo,
            (int) $fatura->cartao_id,
            $cartao?->senha_pdf_regra,
            (bool) ($cartao?->temSenhaPdf())
        );
        $data['precisa_senha_pdf'] = $this->isSenhaPdfErro($fatura->erro_codigo);

        if ($cartao) {
            $data['cartao'] = [
                'id' => $cartao->id,
                'nome' => $cartao->nome,
                'banco' => $cartao->banco,
                'tem_senha_pdf' => $cartao->temSenhaPdf(),
                'senha_pdf_regra' => $cartao->senha_pdf_regra,
                'senha_pdf_orientacao' => PdfSenhaRegra::orientacao($cartao->senha_pdf_regra),
            ];
        }

        return (object) [
            'data' => $data,
            'status' => true,
            'message' => $message,
            'precisa_senha_pdf' => $data['precisa_senha_pdf'],
        ];
    }

    /**
     * @return array{
     *   necessaria: bool,
     *   motivo: string,
     *   regra: ?string,
     *   orientacao: ?string,
     *   label_regra: ?string,
     *   tem_senha_cadastrada: bool,
     *   cartao_id: ?int
     * }|null
     */
    private function buildSenhaPdfMeta(
        ?string $erroCodigo,
        ?int $cartaoId,
        ?string $regra,
        bool $temSenhaCadastrada
    ): ?array {
        if (!$this->isSenhaPdfErro($erroCodigo)) {
            return null;
        }

        $regraEfetiva = $regra ?: null;

        return [
            'necessaria' => true,
            'motivo' => $erroCodigo === PdfSenhaRegra::CODIGO_SENHA_INCORRETA
                ? PdfPasswordException::MOTIVO_INCORRETA
                : PdfPasswordException::MOTIVO_AUSENTE,
            'regra' => $regraEfetiva,
            'orientacao' => PdfSenhaRegra::orientacao($regraEfetiva),
            'label_regra' => PdfSenhaRegra::label($regraEfetiva),
            'tem_senha_cadastrada' => $temSenhaCadastrada,
            'cartao_id' => $cartaoId,
        ];
    }

    private function isSenhaPdfErro(?string $erroCodigo): bool
    {
        return in_array($erroCodigo, [
            PdfSenhaRegra::CODIGO_SENHA_NECESSARIA,
            PdfSenhaRegra::CODIGO_SENHA_INCORRETA,
        ], true);
    }
}
