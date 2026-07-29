<?php

namespace App\Services\Transacao;

use App\Models\Categoria;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransacaoService
{
    public function handleLookupsTransacao(): array
    {
        $userId = Auth::id();

        return [
            'tipos' => [
                ['value' => 'purchase', 'label' => 'Compra'],
                ['value' => 'payment', 'label' => 'Pagamento'],
                ['value' => 'refund', 'label' => 'Estorno'],
                ['value' => 'advance', 'label' => 'Antecipação'],
            ],
            'categorias' => Categoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor']),
            'responsaveis' => Responsavel::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'tipo']),
            'cartoes' => \App\Models\Cartao::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'bandeira', 'ultimos_digitos']),
            'faturas' => Fatura::with('cartao:id,nome')
                ->where('user_id', $userId)
                ->orderByDesc('ano')
                ->orderByDesc('mes')
                ->get(['id', 'cartao_id', 'mes', 'ano', 'status']),
        ];
    }

    public function handleAddTransacao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->createTransacao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditTransacao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->updateTransacao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteTransacao(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->deleteTransacao($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createTransacao(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $this->validatePayload($atributes, $userId);

            $newData = new Transacao([
                'user_id' => $userId,
                'fatura_id' => $atributes->fatura_id,
                'data' => $atributes->data ?? null,
                'estabelecimento' => $atributes->estabelecimento,
                'valor' => $atributes->valor,
                'parcelas_total' => $atributes->parcelas_total ?? null,
                'parcela_atual' => $atributes->parcela_atual ?? null,
                'valor_parcela' => $atributes->valor_parcela ?? ($atributes->valor ?? null),
                'tipo' => $atributes->tipo ?? Transacao::TIPO_PURCHASE,
                'categoria_id' => $atributes->categoria_id ?? null,
                'responsavel_id' => $atributes->responsavel_id ?? null,
                'observacoes' => $atributes->observacoes ?? null,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Transação', 500);
            }

            return (object) [
                'data' => $newData->load(['categoria', 'responsavel', 'fatura']),
                'status' => true,
                'message' => 'Transação cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateTransacao(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da transação é obrigatório', 422);
            }

            $record = Transacao::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Transação não encontrada', 404);
            }

            $userId = Auth::id();

            if (!empty($atributes->fatura_id)) {
                $this->assertFaturaDoUsuario($atributes->fatura_id, $userId);
            }

            if (!empty($atributes->categoria_id)) {
                $this->assertCategoriaDoUsuario($atributes->categoria_id, $userId);
            }

            if (!empty($atributes->responsavel_id)) {
                $this->assertResponsavelDoUsuario($atributes->responsavel_id, $userId);
            }

            if (!empty($atributes->tipo) && !in_array($atributes->tipo, Transacao::TIPOS, true)) {
                throw new Exception('Tipo de transação inválido', 422);
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id']);

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Transação', 500);
            }

            return (object) [
                'data' => $record->fresh()->load(['categoria', 'responsavel', 'fatura']),
                'status' => true,
                'message' => 'Transação alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteTransacao(int|string $id): object
    {
        try {
            $record = Transacao::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Transação não encontrada', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Transação', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Transação excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getTransacaoPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.fatura_id',
            'ent.data',
            'ent.estabelecimento',
            'ent.valor',
            'ent.parcelas_total',
            'ent.parcela_atual',
            'ent.valor_parcela',
            'ent.tipo',
            'ent.categoria_id',
            'cat.nome as categoria_nome',
            'cat.cor as categoria_cor',
            'ent.responsavel_id',
            'resp.nome as responsavel_nome',
            'resp.tipo as responsavel_tipo',
            'ent.observacoes',
            'f.mes as fatura_mes',
            'f.ano as fatura_ano',
            'c.id as cartao_id',
            'c.nome as cartao_nome',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('transacoes as ent');
        $query->leftJoin('categorias as cat', function ($join) {
            $join->on('cat.id', '=', 'ent.categoria_id')->whereNull('cat.deleted_at');
        });
        $query->leftJoin('responsaveis as resp', function ($join) {
            $join->on('resp.id', '=', 'ent.responsavel_id')->whereNull('resp.deleted_at');
        });
        $query->leftJoin('faturas as f', function ($join) {
            $join->on('f.id', '=', 'ent.fatura_id')->whereNull('f.deleted_at');
        });
        $query->leftJoin('cartoes as c', function ($join) {
            $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderByDesc('ent.data')->orderByDesc('ent.id');

        if (!empty($atributes->data_inicio)) {
            $query->whereDate('ent.data', '>=', $atributes->data_inicio);
        }

        if (!empty($atributes->data_fim)) {
            $query->whereDate('ent.data', '<=', $atributes->data_fim);
        }

        if (!empty($atributes->categoria_id)) {
            $query->where('ent.categoria_id', $atributes->categoria_id);
        }

        if (!empty($atributes->responsavel_id)) {
            $query->where('ent.responsavel_id', $atributes->responsavel_id);
        }

        if (!empty($atributes->cartao_id)) {
            $query->where('f.cartao_id', $atributes->cartao_id);
        }

        if (!empty($atributes->fatura_id)) {
            $query->where('ent.fatura_id', $atributes->fatura_id);
        }

        if (!empty($atributes->tipo)) {
            $query->where('ent.tipo', $atributes->tipo);
        }

        if (!empty($atributes->mes)) {
            $query->where('f.mes', (int) $atributes->mes);
        }

        if (!empty($atributes->ano)) {
            $query->where('f.ano', (int) $atributes->ano);
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.estabelecimento', 'like', '%' . $chave . '%')
                    ->orWhere('ent.observacoes', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%')
                    ->orWhere('resp.nome', 'like', '%' . $chave . '%');
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

    public function getTransacaoId(int|string $id): array
    {
        try {
            $query = DB::table('transacoes as ent')
                ->leftJoin('categorias as cat', function ($join) {
                    $join->on('cat.id', '=', 'ent.categoria_id')->whereNull('cat.deleted_at');
                })
                ->leftJoin('responsaveis as resp', function ($join) {
                    $join->on('resp.id', '=', 'ent.responsavel_id')->whereNull('resp.deleted_at');
                })
                ->leftJoin('faturas as f', function ($join) {
                    $join->on('f.id', '=', 'ent.fatura_id')->whereNull('f.deleted_at');
                })
                ->leftJoin('cartoes as c', function ($join) {
                    $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.fatura_id',
                    'ent.data',
                    'ent.estabelecimento',
                    'ent.valor',
                    'ent.parcelas_total',
                    'ent.parcela_atual',
                    'ent.valor_parcela',
                    'ent.tipo',
                    'ent.categoria_id',
                    'cat.nome as categoria_nome',
                    'ent.responsavel_id',
                    'resp.nome as responsavel_nome',
                    'ent.observacoes',
                    'f.mes as fatura_mes',
                    'f.ano as fatura_ano',
                    'c.id as cartao_id',
                    'c.nome as cartao_nome',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Transação não encontrada', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getTransacaoAsync(object $params): array
    {
        $query = DB::table('transacoes as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select('ent.id', 'ent.estabelecimento as nome', 'ent.valor', 'ent.data');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.estabelecimento', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderByDesc('ent.data')->get()->toArray();
    }

    /**
     * Exporta transações filtradas em CSV (compatível com Excel).
     */
    public function exportTransacoesCsv(object $atributes): string
    {
        $atributes->page = 1;
        $atributes->perPage = 100000;
        $resultado = $this->getTransacaoPaginate($atributes);
        $rows = $resultado['data'] ?? [];

        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel

        fputcsv($handle, [
            'ID',
            'Data',
            'Estabelecimento',
            'Valor',
            'Tipo',
            'Categoria',
            'Responsavel',
            'Tipo Responsavel',
            'Cartao',
            'Fatura',
            'Parcelas',
            'Observacoes',
        ], ';');

        foreach ($rows as $row) {
            $row = (array) $row;
            $parcelas = '';
            if (!empty($row['parcela_atual']) && !empty($row['parcelas_total'])) {
                $parcelas = $row['parcela_atual'] . '/' . $row['parcelas_total'];
            }

            fputcsv($handle, [
                $row['id'] ?? '',
                $row['data'] ?? '',
                $row['estabelecimento'] ?? '',
                number_format((float) ($row['valor'] ?? 0), 2, ',', '.'),
                $row['tipo'] ?? '',
                $row['categoria_nome'] ?? '',
                $row['responsavel_nome'] ?? '',
                $row['responsavel_tipo'] ?? '',
                $row['cartao_nome'] ?? '',
                (!empty($row['fatura_mes']) ? str_pad((string) $row['fatura_mes'], 2, '0', STR_PAD_LEFT) . '/' . ($row['fatura_ano'] ?? '') : ''),
                $parcelas,
                $row['observacoes'] ?? '',
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function validatePayload(object $atributes, int $userId): void
    {
        if (empty($atributes->fatura_id)) {
            throw new Exception('Fatura é obrigatória', 422);
        }

        if (empty($atributes->estabelecimento)) {
            throw new Exception('Estabelecimento é obrigatório', 422);
        }

        if (!isset($atributes->valor) || $atributes->valor === '') {
            throw new Exception('Valor é obrigatório', 422);
        }

        $tipo = $atributes->tipo ?? Transacao::TIPO_PURCHASE;
        if (!in_array($tipo, Transacao::TIPOS, true)) {
            throw new Exception('Tipo de transação inválido', 422);
        }

        $this->assertFaturaDoUsuario($atributes->fatura_id, $userId);

        if (!empty($atributes->categoria_id)) {
            $this->assertCategoriaDoUsuario($atributes->categoria_id, $userId);
        }

        if (!empty($atributes->responsavel_id)) {
            $this->assertResponsavelDoUsuario($atributes->responsavel_id, $userId);
        }
    }

    private function assertFaturaDoUsuario(int|string $faturaId, int $userId): void
    {
        $exists = Fatura::where('id', $faturaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Fatura não encontrada', 404);
        }
    }

    private function assertCategoriaDoUsuario(int|string $categoriaId, int $userId): void
    {
        $exists = Categoria::where('id', $categoriaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Categoria não encontrada', 404);
        }
    }

    private function assertResponsavelDoUsuario(int|string $responsavelId, int $userId): void
    {
        $exists = Responsavel::where('id', $responsavelId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Responsável não encontrado', 404);
        }
    }
}
