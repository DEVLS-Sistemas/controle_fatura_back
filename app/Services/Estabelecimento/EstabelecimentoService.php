<?php

namespace App\Services\Estabelecimento;

use App\Models\Categoria;
use App\Models\Estabelecimento;
use App\Models\Subcategoria;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EstabelecimentoService
{
    public function handleLookupsEstabelecimento(): array
    {
        $userId = Auth::id();

        return [
            'categorias' => Categoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor']),
            'subcategorias' => Subcategoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function handleAddEstabelecimento(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->createEstabelecimento($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditEstabelecimento(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->updateEstabelecimento($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteEstabelecimento(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->deleteEstabelecimento($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Localiza estabelecimento pelo nome (trim) ou cria. Restaura soft-deleted se existir.
     */
    public function findOrCreateByNome(int $userId, string $nome): Estabelecimento
    {
        $nome = trim($nome);
        if ($nome === '') {
            $nome = 'Desconhecido';
        }

        $record = Estabelecimento::withTrashed()
            ->where('user_id', $userId)
            ->where('nome', $nome)
            ->first();

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
                $record->ativo = true;
                $record->save();
            }

            return $record;
        }

        return Estabelecimento::create([
            'user_id' => $userId,
            'nome' => $nome,
            'categoria_padrao_id' => null,
            'subcategoria_padrao_id' => null,
            'ativo' => true,
        ]);
    }

    public function createEstabelecimento(object $atributes): object
    {
        try {
            if (empty($atributes->nome) || trim((string) $atributes->nome) === '') {
                throw new Exception('O nome do estabelecimento é obrigatório', 422);
            }

            $nome = trim((string) $atributes->nome);
            $userId = Auth::id();

            $exists = Estabelecimento::where('user_id', $userId)
                ->where('nome', $nome)
                ->exists();

            if ($exists) {
                throw new Exception('Já existe um estabelecimento com este nome', 422);
            }

            $categoriaPadraoId = $this->normalizeNullableId($atributes->categoria_padrao_id ?? null);
            $subcategoriaPadraoId = $this->normalizeNullableId($atributes->subcategoria_padrao_id ?? null);

            $this->assertPadroesValidos($userId, $categoriaPadraoId, $subcategoriaPadraoId);

            $newData = new Estabelecimento([
                'user_id' => $userId,
                'nome' => $nome,
                'categoria_padrao_id' => $categoriaPadraoId,
                'subcategoria_padrao_id' => $subcategoriaPadraoId,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Estabelecimento', 500);
            }

            return (object) [
                'data' => $this->getEstabelecimentoId($newData->id),
                'status' => true,
                'message' => 'Estabelecimento cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateEstabelecimento(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID do estabelecimento é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = Estabelecimento::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            if (isset($atributes->nome) && trim((string) $atributes->nome) !== '') {
                $nome = trim((string) $atributes->nome);
                $exists = Estabelecimento::where('user_id', $userId)
                    ->where('nome', $nome)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe um estabelecimento com este nome', 422);
                }

                $record->nome = $nome;
            }

            $vars = get_object_vars($atributes);

            if (array_key_exists('categoria_padrao_id', $vars)) {
                $record->categoria_padrao_id = $this->normalizeNullableId($atributes->categoria_padrao_id);
            }

            if (array_key_exists('subcategoria_padrao_id', $vars)) {
                $record->subcategoria_padrao_id = $this->normalizeNullableId($atributes->subcategoria_padrao_id);
            }

            if (array_key_exists('ativo', $vars)) {
                $record->ativo = filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN);
            }

            // Se limpar categoria padrão, limpa subcategoria padrão
            if ($record->categoria_padrao_id === null) {
                $record->subcategoria_padrao_id = null;
            }

            $this->assertPadroesValidos($userId, $record->categoria_padrao_id, $record->subcategoria_padrao_id);

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Estabelecimento', 500);
            }

            return (object) [
                'data' => $this->getEstabelecimentoId($record->id),
                'status' => true,
                'message' => 'Estabelecimento alterado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteEstabelecimento(int|string $id): object
    {
        try {
            $record = Estabelecimento::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            $emUso = $record->transacoes()->exists();
            if ($emUso) {
                throw new Exception('Não é possível excluir estabelecimento vinculado a transações', 422);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Estabelecimento', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Estabelecimento excluído com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getEstabelecimentoPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.categoria_padrao_id',
            'cat.nome as categoria_padrao_nome',
            'cat.cor as categoria_padrao_cor',
            'ent.subcategoria_padrao_id',
            'sub.nome as subcategoria_padrao_nome',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('estabelecimentos as ent');
        $query->leftJoin('categorias as cat', function ($join) {
            $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
        });
        $query->leftJoin('subcategorias as sub', function ($join) {
            $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
        }

        if (!empty($atributes->categoria_padrao_id)) {
            $query->where('ent.categoria_padrao_id', $atributes->categoria_padrao_id);
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%')
                    ->orWhere('sub.nome', 'like', '%' . $chave . '%');
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

    public function getEstabelecimentoId(int|string $id): array
    {
        try {
            $query = DB::table('estabelecimentos as ent')
                ->leftJoin('categorias as cat', function ($join) {
                    $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
                })
                ->leftJoin('subcategorias as sub', function ($join) {
                    $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.categoria_padrao_id',
                    'cat.nome as categoria_padrao_nome',
                    'cat.cor as categoria_padrao_cor',
                    'ent.subcategoria_padrao_id',
                    'sub.nome as subcategoria_padrao_nome',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getEstabelecimentoAsync(object $params): array
    {
        $query = DB::table('estabelecimentos as ent')
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
            })
            ->leftJoin('subcategorias as sub', function ($join) {
                $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select(
                'ent.id',
                'ent.nome',
                'ent.categoria_padrao_id',
                'cat.nome as categoria_padrao_nome',
                'cat.cor as categoria_padrao_cor',
                'ent.subcategoria_padrao_id',
                'sub.nome as subcategoria_padrao_nome',
            );

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }

    private function assertPadroesValidos(int $userId, ?int $categoriaId, ?int $subcategoriaId): void
    {
        if ($categoriaId !== null) {
            $exists = Categoria::where('id', $categoriaId)->where('user_id', $userId)->exists();
            if (!$exists) {
                throw new Exception('Categoria padrão não encontrada', 404);
            }
        }

        if ($subcategoriaId !== null) {
            if ($categoriaId === null) {
                throw new Exception('Subcategoria padrão exige categoria padrão', 422);
            }

            $exists = Subcategoria::where('id', $subcategoriaId)->where('user_id', $userId)->exists();
            if (!$exists) {
                throw new Exception('Subcategoria padrão não encontrada', 404);
            }

            $vinculo = DB::table('categoria_subcategoria')
                ->where('categoria_id', $categoriaId)
                ->where('subcategoria_id', $subcategoriaId)
                ->exists();

            if (!$vinculo) {
                throw new Exception('Subcategoria padrão não está vinculada à categoria padrão', 422);
            }
        }
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
