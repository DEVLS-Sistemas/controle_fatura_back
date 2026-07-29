<?php

namespace App\Services\Responsavel;

use App\Models\Responsavel;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ResponsavelService
{
    public function handleLookupsResponsavel(): array
    {
        return [
            'tipos' => [
                ['value' => 'pessoal', 'label' => 'Pessoal'],
                ['value' => 'empresa', 'label' => 'Empresa'],
            ],
        ];
    }

    public function handleAddResponsavel(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->responsavel = $this->createResponsavel($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditResponsavel(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->responsavel = $this->updateResponsavel($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteResponsavel(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->responsavel = $this->deleteResponsavel($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createResponsavel(object $atributes): object
    {
        try {
            if (empty($atributes->nome)) {
                throw new Exception('O nome do responsável é obrigatório', 422);
            }

            $tipo = $atributes->tipo ?? 'pessoal';
            if (!in_array($tipo, ['pessoal', 'empresa'], true)) {
                throw new Exception('Tipo inválido. Use pessoal ou empresa', 422);
            }

            $newData = new Responsavel([
                'user_id' => Auth::id(),
                'nome' => $atributes->nome,
                'tipo' => $tipo,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Responsável', 500);
            }

            return (object) [
                'data' => $newData,
                'status' => true,
                'message' => 'Responsável cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateResponsavel(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID do responsável é obrigatório', 422);
            }

            $record = Responsavel::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Responsável não encontrado', 404);
            }

            if (!empty($atributes->tipo) && !in_array($atributes->tipo, ['pessoal', 'empresa'], true)) {
                throw new Exception('Tipo inválido. Use pessoal ou empresa', 422);
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id']);

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Responsável', 500);
            }

            return (object) [
                'data' => $record->fresh(),
                'status' => true,
                'message' => 'Responsável alterado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteResponsavel(int|string $id): object
    {
        try {
            $record = Responsavel::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Responsável não encontrado', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Responsável', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Responsável excluído com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getResponsavelPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.tipo',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('responsaveis as ent');
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
        }

        if (!empty($atributes->tipo)) {
            $query->where('ent.tipo', $atributes->tipo);
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
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

    public function getResponsavelId(int|string $id): array
    {
        try {
            $query = DB::table('responsaveis as ent')
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.tipo',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Responsável não encontrado', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getResponsavelAsync(object $params): array
    {
        $query = DB::table('responsaveis as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select('ent.id', 'ent.nome', 'ent.tipo');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        if (!empty($params->tipo)) {
            $query->where('ent.tipo', $params->tipo);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }
}
