<?php

namespace App\Services\Fatura;

use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\PaginateService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                ->orderBy('nome')
                ->get(['id', 'nome', 'bandeira', 'ultimos_digitos']),
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

            ProcessInvoicePdfJob::dispatch($fatura->id);

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

            $exists = Fatura::where('user_id', $userId)
                ->where('cartao_id', $atributes->cartao_id)
                ->where('mes', (int) $atributes->mes)
                ->where('ano', (int) $atributes->ano)
                ->exists();

            if ($exists) {
                throw new Exception('Já existe fatura para este cartão no período informado', 422);
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
                'mes' => (int) $atributes->mes,
                'ano' => (int) $atributes->ano,
                'valor_total' => $atributes->valor_total ?? 0,
                'arquivo_pdf' => $arquivoPath,
                'status' => $arquivoPath ? 'pendente' : 'pendente',
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Fatura', 500);
            }

            if ($processar && $arquivoPath) {
                ProcessInvoicePdfJob::dispatch($newData->id);
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

            if (!empty($atributes->mes) || !empty($atributes->ano) || !empty($atributes->cartao_id)) {
                $mes = (int) ($atributes->mes ?? $record->mes);
                $ano = (int) ($atributes->ano ?? $record->ano);
                $cartaoId = (int) ($atributes->cartao_id ?? $record->cartao_id);

                if ($mes < 1 || $mes > 12) {
                    throw new Exception('Mês inválido', 422);
                }

                $exists = Fatura::where('user_id', Auth::id())
                    ->where('cartao_id', $cartaoId)
                    ->where('mes', $mes)
                    ->where('ano', $ano)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe fatura para este cartão no período informado', 422);
                }
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

            if ($record->arquivo_pdf && Storage::disk('local')->exists($record->arquivo_pdf)) {
                Storage::disk('local')->delete($record->arquivo_pdf);
            }

            $path = $this->storePdf($atributes->arquivo_pdf, Auth::id());

            $record->update([
                'arquivo_pdf' => $path,
                'status' => 'pendente',
                'erro_mensagem' => null,
                'processado_em' => null,
            ]);

            $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($processar) {
                ProcessInvoicePdfJob::dispatch($record->id);
            }

            return (object) [
                'data' => $record->fresh()->load('cartao'),
                'status' => true,
                'message' => 'PDF enviado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getFaturaPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.cartao_id',
            'c.nome as cartao_nome',
            'c.bandeira as cartao_bandeira',
            'c.ultimos_digitos as cartao_ultimos_digitos',
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
        );

        $query->from('faturas as ent');
        $query->leftJoin('cartoes as c', function ($join) {
            $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderByDesc('ent.ano')->orderByDesc('ent.mes');

        if (!empty($atributes->cartao_id)) {
            $query->where('ent.cartao_id', $atributes->cartao_id);
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

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $query,
            $atributes->page,
            $atributes->perPage,
            ['path' => $atributes->url, 'query' => $atributes->query]
        );
        $resultado->appends((array) $atributes);

        return collect($resultado)->toArray();
    }

    public function getFaturaId(int|string $id): array
    {
        try {
            $query = DB::table('faturas as ent')
                ->leftJoin('cartoes as c', function ($join) {
                    $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.cartao_id',
                    'c.nome as cartao_nome',
                    'c.bandeira as cartao_bandeira',
                    'c.ultimos_digitos as cartao_ultimos_digitos',
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
     * Localiza fatura do cartão no período ou cria (status pendente).
     * Usado no cadastro de compra via cartao_id + data.
     */
    public function findOrCreateByCartaoPeriodo(int $userId, int $cartaoId, int $mes, int $ano): Fatura
    {
        $this->assertCartaoDoUsuario($cartaoId, $userId);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mês inválido', 422);
        }

        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }

        $fatura = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->where('cartao_id', $cartaoId)
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
            'mes' => $mes,
            'ano' => $ano,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
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

        $allowedExtensions = ['pdf', 'csv', 'xml'];
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

        return $file->store("faturas/{$userId}", 'local');
    }
}
