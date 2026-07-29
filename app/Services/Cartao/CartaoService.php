<?php

namespace App\Services\Cartao;

use App\Models\Cartao;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartaoService
{
    public function handleLookupsCartao(): array
    {
        return [
            'bandeiras' => ['Visa', 'Mastercard', 'Elo', 'Amex', 'Hipercard', 'Outra'],
        ];
    }

    public function handleAddCartao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->cartao = $this->createCartao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditCartao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->cartao = $this->updateCartao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteCartao(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->cartao = $this->deleteCartao($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createCartao(object $atributes): object
    {
        try {
            $userId = Auth::id();

            if (empty($atributes->nome)) {
                throw new Exception('O nome do cartão é obrigatório', 422);
            }

            if (!empty($atributes->ultimos_digitos) && !preg_match('/^\d{4}$/', $atributes->ultimos_digitos)) {
                throw new Exception('Últimos dígitos devem conter 4 números', 422);
            }

            $newData = new Cartao([
                'user_id' => $userId,
                'nome' => $atributes->nome,
                'bandeira' => $atributes->bandeira ?? null,
                'banco' => $atributes->banco ?? null,
                'ultimos_digitos' => $atributes->ultimos_digitos ?? null,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Cartão', 500);
            }

            return (object) [
                'data' => $newData,
                'status' => true,
                'message' => 'Cartão cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateCartao(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID do cartão é obrigatório', 422);
            }

            $record = Cartao::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Cartão não encontrado', 404);
            }

            if (!empty($atributes->ultimos_digitos) && !preg_match('/^\d{4}$/', $atributes->ultimos_digitos)) {
                throw new Exception('Últimos dígitos devem conter 4 números', 422);
            }

            $data = get_object_vars($atributes);
            unset($data['user_id'], $data['id']);

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Cartão', 500);
            }

            return (object) [
                'data' => $record->fresh(),
                'status' => true,
                'message' => 'Cartão alterado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteCartao(int|string $id): object
    {
        try {
            $record = Cartao::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Cartão não encontrado', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Cartão', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Cartão excluído com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getCartaoPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.bandeira',
            'ent.banco',
            'ent.ultimos_digitos',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('cartoes as ent');
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
        }

        if (!empty($atributes->bandeira)) {
            $query->where('ent.bandeira', $atributes->bandeira);
        }

        if (!empty($atributes->banco)) {
            $query->where('ent.banco', 'like', '%' . $atributes->banco . '%');
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.banco', 'like', '%' . $chave . '%')
                    ->orWhere('ent.bandeira', 'like', '%' . $chave . '%')
                    ->orWhere('ent.ultimos_digitos', 'like', '%' . $chave . '%');
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

    public function getCartaoId(int|string $id): array
    {
        try {
            $query = DB::table('cartoes as ent')
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.bandeira',
                    'ent.banco',
                    'ent.ultimos_digitos',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Cartão não encontrado', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getCartaoAsync(object $params): array
    {
        $query = DB::table('cartoes as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select('ent.id', 'ent.nome', 'ent.bandeira', 'ent.ultimos_digitos');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.banco', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderBy('ent.nome')->get()->toArray();
    }
}
