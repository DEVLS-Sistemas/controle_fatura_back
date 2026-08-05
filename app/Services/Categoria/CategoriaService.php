<?php

namespace App\Services\Categoria;

use App\Models\Categoria;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CategoriaService
{
    public function handleLookupsCategoria(): array
    {
        return [
            'cores' => [
                '#ef4444', '#f59e0b', '#22c55e', '#3b82f6',
                '#8b5cf6', '#ec4899', '#6b7280', '#14b8a6',
            ],
        ];
    }

    public function handleAddCategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->categoria = $this->createCategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditCategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->categoria = $this->updateCategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteCategoria(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->categoria = $this->deleteCategoria($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleCadastrarRapidoCategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->categoria = $this->findOrCreateByNome($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Cadastro rápido: reutiliza categoria existente (nome case-insensitive) ou cria.
     * Restaura soft-deleted com o mesmo nome.
     */
    public function findOrCreateByNome(object $atributes): object
    {
        $nome = $this->normalizeNome($atributes->nome ?? null);
        if ($nome === '') {
            throw new Exception('O nome da categoria é obrigatório', 422);
        }

        $userId = Auth::id();
        $record = Categoria::withTrashed()
            ->where('user_id', $userId)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
            ->first();

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
            }

            $dirty = false;
            if (!$record->ativo) {
                $record->ativo = true;
                $dirty = true;
            }
            if (!empty($atributes->cor) && empty($record->cor)) {
                $record->cor = $atributes->cor;
                $dirty = true;
            }
            if ($dirty) {
                $record->save();
            }

            return (object) [
                'data' => $record->fresh(),
                'status' => true,
                'criado' => false,
                'message' => 'Categoria já cadastrada — reutilizada.',
            ];
        }

        $newData = new Categoria([
            'user_id' => $userId,
            'nome' => $nome,
            'cor' => $atributes->cor ?? null,
            'ativo' => true,
        ]);

        if (!$newData->save()) {
            throw new Exception('Não foi possível cadastrar Categoria', 500);
        }

        return (object) [
            'data' => $newData,
            'status' => true,
            'criado' => true,
            'message' => 'Categoria cadastrada com sucesso!',
        ];
    }

    public function createCategoria(object $atributes): object
    {
        try {
            $nome = $this->normalizeNome($atributes->nome ?? null);
            if ($nome === '') {
                throw new Exception('O nome da categoria é obrigatório', 422);
            }

            $exists = Categoria::where('user_id', Auth::id())
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                ->exists();

            if ($exists) {
                throw new Exception('Já existe uma categoria com este nome', 422);
            }

            $newData = new Categoria([
                'user_id' => Auth::id(),
                'nome' => $nome,
                'cor' => $atributes->cor ?? null,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Categoria', 500);
            }

            return (object) [
                'data' => $newData,
                'status' => true,
                'message' => 'Categoria cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateCategoria(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da categoria é obrigatório', 422);
            }

            $record = Categoria::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Categoria não encontrada', 404);
            }

            if (isset($atributes->nome) && $this->normalizeNome($atributes->nome) !== '') {
                $nome = $this->normalizeNome($atributes->nome);
                $exists = Categoria::where('user_id', Auth::id())
                    ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe uma categoria com este nome', 422);
                }

                $atributes->nome = $nome;
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id']);

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Categoria', 500);
            }

            return (object) [
                'data' => $record->fresh(),
                'status' => true,
                'message' => 'Categoria alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function normalizeNome(mixed $nome): string
    {
        if ($nome === null) {
            return '';
        }

        $nome = trim((string) $nome);
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? $nome);

        return $nome;
    }

    public function deleteCategoria(int|string $id): object
    {
        try {
            $record = Categoria::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Categoria não encontrada', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Categoria', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Categoria excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getCategoriaPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.cor',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('categorias as ent');
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
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

    public function getCategoriaId(int|string $id): array
    {
        try {
            $query = DB::table('categorias as ent')
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.cor',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Categoria não encontrada', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getCategoriaAsync(object $params): array
    {
        $query = DB::table('categorias as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select('ent.id', 'ent.nome', 'ent.cor');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }
}
