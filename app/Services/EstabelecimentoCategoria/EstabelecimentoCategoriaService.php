<?php

namespace App\Services\EstabelecimentoCategoria;

use App\Models\Categoria;
use App\Models\EstabelecimentoCategoria;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EstabelecimentoCategoriaService
{
    public function handleLookupsEstabelecimentoCategoria(): array
    {
        $userId = Auth::id();

        return [
            'categorias' => Categoria::where('user_id', $userId)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'cor']),
        ];
    }

    public function handleAddEstabelecimentoCategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento_categoria = $this->createEstabelecimentoCategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditEstabelecimentoCategoria(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento_categoria = $this->updateEstabelecimentoCategoria($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteEstabelecimentoCategoria(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento_categoria = $this->deleteEstabelecimentoCategoria($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Cria ou atualiza a categoria vinculada ao estabelecimento do usuário.
     * Usado também pelo TransacaoService ao receber categoria_id.
     */
    public function syncCategoriaEstabelecimento(int $userId, string $estabelecimento, int|string|null $categoriaId): ?EstabelecimentoCategoria
    {
        $estabelecimento = trim($estabelecimento);

        if ($estabelecimento === '') {
            throw new Exception('Estabelecimento é obrigatório', 422);
        }

        $record = EstabelecimentoCategoria::withTrashed()
            ->where('user_id', $userId)
            ->where('estabelecimento', $estabelecimento)
            ->first();

        if ($categoriaId === null || $categoriaId === '') {
            if ($record && !$record->trashed()) {
                $record->delete();
            }

            return null;
        }

        $this->assertCategoriaDoUsuario($categoriaId, $userId);

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
            }

            $record->categoria_id = (int) $categoriaId;
            $record->save();

            return $record->fresh()->load('categoria');
        }

        $record = new EstabelecimentoCategoria([
            'user_id' => $userId,
            'estabelecimento' => $estabelecimento,
            'categoria_id' => (int) $categoriaId,
        ]);

        if (!$record->save()) {
            throw new Exception('Não foi possível vincular categoria ao estabelecimento', 500);
        }

        return $record->load('categoria');
    }

    public function createEstabelecimentoCategoria(object $atributes): object
    {
        try {
            $userId = Auth::id();

            if (empty($atributes->estabelecimento)) {
                throw new Exception('Estabelecimento é obrigatório', 422);
            }

            if (empty($atributes->categoria_id)) {
                throw new Exception('Categoria é obrigatória', 422);
            }

            $this->assertCategoriaDoUsuario($atributes->categoria_id, $userId);

            $exists = EstabelecimentoCategoria::where('user_id', $userId)
                ->where('estabelecimento', trim($atributes->estabelecimento))
                ->exists();

            if ($exists) {
                throw new Exception('Já existe categoria vinculada a este estabelecimento', 422);
            }

            $record = $this->syncCategoriaEstabelecimento(
                $userId,
                $atributes->estabelecimento,
                $atributes->categoria_id
            );

            return (object) [
                'data' => $record,
                'status' => true,
                'message' => 'Categoria do estabelecimento cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateEstabelecimentoCategoria(object $atributes): object
    {
        try {
            if (empty($atributes->id) && !empty($atributes->estabelecimento_categoria_id)) {
                $atributes->id = $atributes->estabelecimento_categoria_id;
            }

            if (empty($atributes->id)) {
                throw new Exception('ID do vínculo é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = EstabelecimentoCategoria::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Vínculo estabelecimento/categoria não encontrado', 404);
            }

            $estabelecimento = isset($atributes->estabelecimento)
                ? trim((string) $atributes->estabelecimento)
                : $record->estabelecimento;

            if ($estabelecimento === '') {
                throw new Exception('Estabelecimento é obrigatório', 422);
            }

            if ($estabelecimento !== $record->estabelecimento) {
                $exists = EstabelecimentoCategoria::where('user_id', $userId)
                    ->where('estabelecimento', $estabelecimento)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe categoria vinculada a este estabelecimento', 422);
                }
            }

            if (array_key_exists('categoria_id', get_object_vars($atributes))) {
                if ($atributes->categoria_id === null || $atributes->categoria_id === '') {
                    $record->delete();

                    return (object) [
                        'data' => [],
                        'status' => true,
                        'message' => 'Categoria removida do estabelecimento com sucesso!',
                    ];
                }

                $this->assertCategoriaDoUsuario($atributes->categoria_id, $userId);
                $record->categoria_id = (int) $atributes->categoria_id;
            }

            $record->estabelecimento = $estabelecimento;
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar vínculo estabelecimento/categoria', 500);
            }

            return (object) [
                'data' => $record->fresh()->load('categoria'),
                'status' => true,
                'message' => 'Categoria do estabelecimento alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteEstabelecimentoCategoria(int|string $id): object
    {
        try {
            $record = EstabelecimentoCategoria::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Vínculo estabelecimento/categoria não encontrado', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir vínculo estabelecimento/categoria', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Categoria do estabelecimento excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getEstabelecimentoCategoriaPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.estabelecimento',
            'ent.categoria_id',
            'cat.nome as categoria_nome',
            'cat.cor as categoria_cor',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('estabelecimento_categorias as ent');
        $query->leftJoin('categorias as cat', function ($join) {
            $join->on('cat.id', '=', 'ent.categoria_id')->whereNull('cat.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.estabelecimento');

        if (!empty($atributes->categoria_id)) {
            $query->where('ent.categoria_id', $atributes->categoria_id);
        }

        if (!empty($atributes->estabelecimento)) {
            $chave = $atributes->estabelecimento;
            $query->where('ent.estabelecimento', 'like', '%' . $chave . '%');
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.estabelecimento', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%');
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

    public function getEstabelecimentoCategoriaId(int|string $id): array
    {
        try {
            $query = DB::table('estabelecimento_categorias as ent')
                ->leftJoin('categorias as cat', function ($join) {
                    $join->on('cat.id', '=', 'ent.categoria_id')->whereNull('cat.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.estabelecimento',
                    'ent.categoria_id',
                    'cat.nome as categoria_nome',
                    'cat.cor as categoria_cor',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Vínculo estabelecimento/categoria não encontrado', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getEstabelecimentoCategoriaAsync(object $params): array
    {
        $query = DB::table('estabelecimento_categorias as ent')
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 'ent.categoria_id')->whereNull('cat.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select(
                'ent.id',
                'ent.estabelecimento as nome',
                'ent.categoria_id',
                'cat.nome as categoria_nome',
                'cat.cor as categoria_cor',
            );

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.estabelecimento', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderBy('ent.estabelecimento')->get()->toArray();
    }

    private function assertCategoriaDoUsuario(int|string $categoriaId, int $userId): void
    {
        $exists = Categoria::where('id', $categoriaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Categoria não encontrada', 404);
        }
    }
}
