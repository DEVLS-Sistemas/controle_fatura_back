<?php

namespace App\Services\Subcategoria;

use App\Models\Categoria;
use App\Models\Subcategoria;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubcategoriaService
{
    public function handleLookupsSubcategoria(): array
    {
        $userId = Auth::id();

        return [
            'categorias' => Categoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor']),
        ];
    }

    public function handleAddSubcategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->subcategoria = $this->createSubcategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditSubcategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->subcategoria = $this->updateSubcategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteSubcategoria(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->subcategoria = $this->deleteSubcategoria($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createSubcategoria(object $atributes): object
    {
        try {
            if (empty($atributes->nome) || trim((string) $atributes->nome) === '') {
                throw new Exception('O nome da subcategoria é obrigatório', 422);
            }

            $nome = trim((string) $atributes->nome);
            $userId = Auth::id();

            $exists = Subcategoria::where('user_id', $userId)
                ->where('nome', $nome)
                ->exists();

            if ($exists) {
                throw new Exception('Já existe uma subcategoria com este nome', 422);
            }

            $categoriaIds = $this->normalizeCategoriaIds($atributes->categoria_ids ?? $atributes->categorias ?? []);
            if (count($categoriaIds) === 0) {
                throw new Exception('Informe ao menos uma categoria vinculada à subcategoria', 422);
            }

            $this->assertCategoriasDoUsuario($categoriaIds, $userId);

            $newData = new Subcategoria([
                'user_id' => $userId,
                'nome' => $nome,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Subcategoria', 500);
            }

            $newData->categorias()->sync($categoriaIds);

            return (object) [
                'data' => $this->getSubcategoriaId($newData->id),
                'status' => true,
                'message' => 'Subcategoria cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateSubcategoria(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da subcategoria é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = Subcategoria::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Subcategoria não encontrada', 404);
            }

            if (isset($atributes->nome) && trim((string) $atributes->nome) !== '') {
                $nome = trim((string) $atributes->nome);
                $exists = Subcategoria::where('user_id', $userId)
                    ->where('nome', $nome)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe uma subcategoria com este nome', 422);
                }

                $record->nome = $nome;
            }

            if (isset($atributes->ativo) && $atributes->ativo !== '') {
                $record->ativo = filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN);
            }

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Subcategoria', 500);
            }

            $vars = get_object_vars($atributes);
            if (array_key_exists('categoria_ids', $vars) || array_key_exists('categorias', $vars)) {
                $categoriaIds = $this->normalizeCategoriaIds($atributes->categoria_ids ?? $atributes->categorias ?? []);
                if (count($categoriaIds) === 0) {
                    throw new Exception('Informe ao menos uma categoria vinculada à subcategoria', 422);
                }
                $this->assertCategoriasDoUsuario($categoriaIds, $userId);
                $record->categorias()->sync($categoriaIds);
            }

            return (object) [
                'data' => $this->getSubcategoriaId($record->id),
                'status' => true,
                'message' => 'Subcategoria alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteSubcategoria(int|string $id): object
    {
        try {
            $record = Subcategoria::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Subcategoria não encontrada', 404);
            }

            if ($record->transacoes()->exists()) {
                throw new Exception('Não é possível excluir subcategoria vinculada a transações', 422);
            }

            $record->categorias()->detach();
            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Subcategoria', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Subcategoria excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getSubcategoriaPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('subcategorias as ent');
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $query->where('ent.nome', 'like', '%' . $atributes->nome . '%');
        }

        if (!empty($atributes->categoria_id)) {
            $query->whereExists(function ($q) use ($atributes) {
                $q->select(DB::raw(1))
                    ->from('categoria_subcategoria as cs')
                    ->whereColumn('cs.subcategoria_id', 'ent.id')
                    ->where('cs.categoria_id', $atributes->categoria_id);
            });
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
        }

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $query,
            $atributes->page,
            $atributes->perPage,
            ['path' => $atributes->url, 'query' => $atributes->query]
        );
        $resultado->appends((array) $atributes);

        $items = collect($resultado->items())->map(function ($item) {
            $item = (array) $item;
            $item['categorias'] = $this->categoriasDaSubcategoria((int) $item['id']);
            return $item;
        })->values()->all();

        $array = collect($resultado)->toArray();
        $array['data'] = $items;

        return $array;
    }

    public function getSubcategoriaId(int|string $id): array
    {
        try {
            $query = DB::table('subcategorias as ent')
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Subcategoria não encontrada', 404);
            }

            $result = collect($data)->toArray();
            $result['categorias'] = $this->categoriasDaSubcategoria((int) $id);
            $result['categoria_ids'] = array_map(fn ($c) => $c['id'], $result['categorias']);

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getSubcategoriaAsync(object $params): array
    {
        $query = DB::table('subcategorias as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select('ent.id', 'ent.nome');

        if (!empty($params->categoria_id)) {
            $query->whereExists(function ($q) use ($params) {
                $q->select(DB::raw(1))
                    ->from('categoria_subcategoria as cs')
                    ->whereColumn('cs.subcategoria_id', 'ent.id')
                    ->where('cs.categoria_id', $params->categoria_id);
            });
        }

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
            $query->limit(10);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }

    /**
     * Valida se subcategoria pertence ao usuário e está vinculada à categoria.
     */
    public function assertSubcategoriaValidaParaCategoria(int|string $subcategoriaId, int|string $categoriaId, int $userId): void
    {
        $exists = Subcategoria::where('id', $subcategoriaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Subcategoria não encontrada', 404);
        }

        $vinculo = DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->where('subcategoria_id', $subcategoriaId)
            ->exists();

        if (!$vinculo) {
            throw new Exception('Subcategoria não está vinculada à categoria informada', 422);
        }
    }

    private function categoriasDaSubcategoria(int $subcategoriaId): array
    {
        return DB::table('categoria_subcategoria as cs')
            ->join('categorias as c', function ($join) {
                $join->on('c.id', '=', 'cs.categoria_id')->whereNull('c.deleted_at');
            })
            ->where('cs.subcategoria_id', $subcategoriaId)
            ->orderBy('c.nome')
            ->get(['c.id', 'c.nome', 'c.cor'])
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'nome' => $item->nome,
                'cor' => $item->cor,
            ])
            ->toArray();
    }

    private function normalizeCategoriaIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                $item = (array) $item;
                $item = $item['id'] ?? null;
            }
            if ($item === null || $item === '') {
                continue;
            }
            $ids[] = (int) $item;
        }

        return array_values(array_unique($ids));
    }

    private function assertCategoriasDoUsuario(array $categoriaIds, int $userId): void
    {
        $count = Categoria::where('user_id', $userId)
            ->whereIn('id', $categoriaIds)
            ->count();

        if ($count !== count($categoriaIds)) {
            throw new Exception('Uma ou mais categorias informadas são inválidas', 422);
        }
    }
}
