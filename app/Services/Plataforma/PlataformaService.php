<?php

namespace App\Services\Plataforma;

use App\Models\Plataforma;
use App\Services\Categoria\CategoriaCoresTema;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlataformaService
{
    public function handleLookupsPlataforma(): array
    {
        return CategoriaCoresTema::lookups();
    }

    public function handleAddPlataforma(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->plataforma = $this->createPlataforma($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditPlataforma(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->plataforma = $this->updatePlataforma($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeletePlataforma(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->plataforma = $this->deletePlataforma($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleCadastrarRapidoPlataforma(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->plataforma = $this->findOrCreateByNome($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Cadastro rápido: reutiliza plataforma existente (nome case-insensitive) ou cria.
     * Restaura soft-deleted com o mesmo nome.
     */
    public function findOrCreateByNome(object $atributes): object
    {
        $nome = $this->normalizeNome($atributes->nome ?? null);
        if ($nome === '') {
            throw new Exception('O nome da plataforma é obrigatório', 422);
        }

        $userId = Auth::id();
        $record = Plataforma::withTrashed()
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
                $record->cor = CategoriaCoresTema::parseParaGravar($atributes->cor);
                $dirty = true;
            }
            if ($dirty) {
                $record->save();
            }

            return (object) [
                'data' => $this->mapCorListagem($record->fresh()),
                'status' => true,
                'criado' => false,
                'message' => 'Plataforma já cadastrada — reutilizada.',
            ];
        }

        $newData = new Plataforma([
            'user_id' => $userId,
            'nome' => $nome,
            'cor' => CategoriaCoresTema::parseParaGravar($atributes->cor ?? null),
            'ativo' => true,
        ]);

        if (!$newData->save()) {
            throw new Exception('Não foi possível cadastrar Plataforma', 500);
        }

        return (object) [
            'data' => $this->mapCorListagem($newData),
            'status' => true,
            'criado' => true,
            'message' => 'Plataforma cadastrada com sucesso!',
        ];
    }

    public function createPlataforma(object $atributes): object
    {
        try {
            $nome = $this->normalizeNome($atributes->nome ?? null);
            if ($nome === '') {
                throw new Exception('O nome da plataforma é obrigatório', 422);
            }

            $exists = Plataforma::where('user_id', Auth::id())
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                ->exists();

            if ($exists) {
                throw new Exception('Já existe uma plataforma com este nome', 422);
            }

            $newData = new Plataforma([
                'user_id' => Auth::id(),
                'nome' => $nome,
                'cor' => CategoriaCoresTema::parseParaGravar($atributes->cor ?? null),
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Plataforma', 500);
            }

            return (object) [
                'data' => $this->mapCorListagem($newData),
                'status' => true,
                'message' => 'Plataforma cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updatePlataforma(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da plataforma é obrigatório', 422);
            }

            $record = Plataforma::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Plataforma não encontrada', 404);
            }

            if (isset($atributes->nome) && $this->normalizeNome($atributes->nome) !== '') {
                $nome = $this->normalizeNome($atributes->nome);
                $exists = Plataforma::where('user_id', Auth::id())
                    ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe uma plataforma com este nome', 422);
                }

                $atributes->nome = $nome;
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id']);

            if (array_key_exists('cor', $data)) {
                $data['cor'] = CategoriaCoresTema::parseParaGravar($data['cor']);
            }

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Plataforma', 500);
            }

            return (object) [
                'data' => $this->mapCorListagem($record->fresh()),
                'status' => true,
                'message' => 'Plataforma alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deletePlataforma(int|string $id): object
    {
        try {
            $record = Plataforma::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Plataforma não encontrada', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Plataforma', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Plataforma excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getPlataformaPaginate(object $atributes): array
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

        $query->from('plataformas as ent');
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

        $pagina = collect($resultado)->toArray();
        if (isset($pagina['data']) && is_array($pagina['data'])) {
            $pagina['data'] = array_map(fn ($item) => $this->mapCorListagem($item), $pagina['data']);
        }

        return $pagina;
    }

    public function getPlataformaId(int|string $id): array
    {
        try {
            $query = DB::table('plataformas as ent')
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
                throw new Exception('Plataforma não encontrada', 404);
            }

            return $this->mapCorListagem(collect($data)->toArray());
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getPlataformaAsync(object $params): array
    {
        $query = DB::table('plataformas as ent')
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

        return $query->orderBy('ent.nome')->get()
            ->map(fn ($item) => $this->mapCorListagem($item))
            ->values()
            ->all();
    }

    /**
     * @param mixed $item
     * @return mixed
     */
    private function mapCorListagem(mixed $item): mixed
    {
        if (is_array($item)) {
            $item['cor'] = CategoriaCoresTema::normalizar($item['cor'] ?? null);

            return $item;
        }

        if (is_object($item)) {
            $item->cor = CategoriaCoresTema::normalizar($item->cor ?? null);

            return $item;
        }

        return $item;
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
}
