<?php

namespace App\Services\Loja;

use App\Models\Estabelecimento;
use App\Models\Loja;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LojaService
{
    public function handleLookupsLoja(): array
    {
        return [];
    }

    public function handleAddLoja(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->loja = $this->createLoja($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditLoja(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->loja = $this->updateLoja($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteLoja(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->loja = $this->deleteLoja($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleCadastrarRapidoLoja(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->loja = $this->findOrCreateByNome($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleVincularEstabelecimentos(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->loja = $this->vincularEstabelecimentosEmLote($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Cadastro rápido: reutiliza loja existente (nome case-insensitive) ou cria.
     * Restaura soft-deleted com o mesmo nome.
     * Aceita estabelecimento_id ou estabelecimento_ids[] para vincular.
     */
    public function findOrCreateByNome(object $atributes): object
    {
        $nome = $this->normalizeNome($atributes->nome ?? null);
        if ($nome === '') {
            throw new Exception('O nome da loja é obrigatório', 422);
        }

        $userId = Auth::id();
        $record = Loja::withTrashed()
            ->where('user_id', $userId)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
            ->first();

        $criado = false;

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
            }

            $dirty = false;
            if (!$record->ativo) {
                $record->ativo = true;
                $dirty = true;
            }
            if ($dirty) {
                $record->save();
            }
        } else {
            $record = new Loja([
                'user_id' => $userId,
                'nome' => $nome,
                'ativo' => true,
            ]);

            if (!$record->save()) {
                throw new Exception('Não foi possível cadastrar Loja', 500);
            }

            $criado = true;
        }

        $ids = $this->normalizeEstabelecimentoIds($atributes);
        $vinculados = 0;
        if (!empty($ids)) {
            $vinculados = $this->vincularEstabelecimentos($userId, $ids, (int) $record->id);
        }

        $message = $criado
            ? 'Loja cadastrada com sucesso!'
            : 'Loja já cadastrada — reutilizada.';

        if ($vinculados > 0) {
            $message .= " {$vinculados} estabelecimento(s) vinculado(s).";
        }

        return (object) [
            'data' => $this->getLojaId($record->id),
            'status' => true,
            'criado' => $criado,
            'vinculados' => $vinculados,
            'message' => $message,
        ];
    }

    /**
     * Vincula vários estabelecimentos a uma loja (por loja_id ou nome find-or-create).
     */
    public function vincularEstabelecimentosEmLote(object $atributes): object
    {
        $ids = $this->normalizeEstabelecimentoIds($atributes);
        if (empty($ids)) {
            throw new Exception('Informe ao menos um estabelecimento em estabelecimento_ids', 422);
        }

        $userId = Auth::id();
        $criado = false;
        $lojaId = $this->normalizeNullableId($atributes->loja_id ?? null);

        if ($lojaId !== null) {
            $loja = Loja::where('id', $lojaId)->where('user_id', $userId)->first();
            if (!$loja) {
                throw new Exception('Loja não encontrada', 404);
            }
        } else {
            $nome = $this->normalizeNome($atributes->nome ?? null);
            if ($nome === '') {
                throw new Exception('Informe loja_id ou nome da loja', 422);
            }

            $rapido = $this->findOrCreateByNome((object) [
                'nome' => $nome,
                'estabelecimento_ids' => $ids,
            ]);

            return $rapido;
        }

        $vinculados = $this->vincularEstabelecimentos($userId, $ids, $lojaId);

        return (object) [
            'data' => $this->getLojaId($lojaId),
            'status' => true,
            'criado' => $criado,
            'vinculados' => $vinculados,
            'message' => "{$vinculados} estabelecimento(s) vinculado(s) à loja.",
        ];
    }

    public function createLoja(object $atributes): object
    {
        try {
            $nome = $this->normalizeNome($atributes->nome ?? null);
            if ($nome === '') {
                throw new Exception('O nome da loja é obrigatório', 422);
            }

            $exists = Loja::where('user_id', Auth::id())
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                ->exists();

            if ($exists) {
                throw new Exception('Já existe uma loja com este nome', 422);
            }

            $newData = new Loja([
                'user_id' => Auth::id(),
                'nome' => $nome,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Loja', 500);
            }

            return (object) [
                'data' => $this->getLojaId($newData->id),
                'status' => true,
                'message' => 'Loja cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateLoja(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da loja é obrigatório', 422);
            }

            $record = Loja::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Loja não encontrada', 404);
            }

            if (isset($atributes->nome) && $this->normalizeNome($atributes->nome) !== '') {
                $nome = $this->normalizeNome($atributes->nome);
                $exists = Loja::where('user_id', Auth::id())
                    ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe uma loja com este nome', 422);
                }

                $record->nome = $nome;
            }

            if (array_key_exists('ativo', get_object_vars($atributes))) {
                $record->ativo = filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN);
            }

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Loja', 500);
            }

            return (object) [
                'data' => $this->getLojaId($record->id),
                'status' => true,
                'message' => 'Loja alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteLoja(int|string $id): object
    {
        try {
            $record = Loja::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Loja não encontrada', 404);
            }

            Estabelecimento::where('user_id', Auth::id())
                ->where('loja_id', $record->id)
                ->update(['loja_id' => null]);

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Loja', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Loja excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getLojaPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
            DB::raw('COUNT(est.id) as estabelecimentos_count'),
        );

        $query->from('lojas as ent');
        $query->leftJoin('estabelecimentos as est', function ($join) {
            $join->on('est.loja_id', '=', 'ent.id')->whereNull('est.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->groupBy('ent.id', 'ent.nome', 'ent.ativo', 'ent.created_at', 'ent.updated_at');
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
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

        return collect($resultado)->toArray();
    }

    public function getLojaId(int|string $id): array
    {
        try {
            $query = DB::table('lojas as ent')
                ->leftJoin('estabelecimentos as est', function ($join) {
                    $join->on('est.loja_id', '=', 'ent.id')->whereNull('est.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                    DB::raw('COUNT(est.id) as estabelecimentos_count'),
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id)
                ->groupBy('ent.id', 'ent.nome', 'ent.ativo', 'ent.created_at', 'ent.updated_at');

            $data = $query->first();

            if (!$data) {
                throw new Exception('Loja não encontrada', 404);
            }

            $result = collect($data)->toArray();
            $result['estabelecimentos'] = DB::table('estabelecimentos as est')
                ->whereNull('est.deleted_at')
                ->where('est.user_id', Auth::id())
                ->where('est.loja_id', $id)
                ->orderBy('est.nome')
                ->get(['est.id', 'est.nome', 'est.ativo'])
                ->toArray();

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getLojaAsync(object $params): array
    {
        $query = DB::table('lojas as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select('ent.id', 'ent.nome');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
            $query->limit(10);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }

    /**
     * @param  array<int>  $estabelecimentoIds
     */
    private function vincularEstabelecimentos(int $userId, array $estabelecimentoIds, int $lojaId): int
    {
        $ids = array_values(array_unique(array_map('intval', $estabelecimentoIds)));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

        if (empty($ids)) {
            return 0;
        }

        $encontrados = Estabelecimento::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($encontrados) !== count($ids)) {
            throw new Exception('Um ou mais estabelecimentos são inválidos', 422);
        }

        Estabelecimento::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->update(['loja_id' => $lojaId]);

        return count($ids);
    }

    /**
     * @return array<int>
     */
    private function normalizeEstabelecimentoIds(object $atributes): array
    {
        $ids = [];

        if (!empty($atributes->estabelecimento_ids) && is_array($atributes->estabelecimento_ids)) {
            $ids = $atributes->estabelecimento_ids;
        } elseif (!empty($atributes->estabelecimento_id)) {
            $ids = [$atributes->estabelecimento_id];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_filter($ids, fn (int $id) => $id > 0));
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
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
