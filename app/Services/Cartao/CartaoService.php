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
            'cores_fundo' => [
                '#ef4444', '#f59e0b', '#22c55e', '#3b82f6',
                '#8b5cf6', '#ec4899', '#6b7280', '#14b8a6',
                '#0f172a', '#f8fafc', '#fbbf24', '#be185d',
            ],
            'cores_texto' => [
                '#ffffff', '#0f172a', '#111827', '#f8fafc',
                '#fef3c7', '#ede9fe', '#ecfeff', '#fce7f3',
            ],
            'pares_cores' => [
                ['cor_fundo' => '#8b5cf6', 'cor_texto' => '#ffffff', 'label' => 'Roxo'],
                ['cor_fundo' => '#22c55e', 'cor_texto' => '#052e16', 'label' => 'Verde'],
                ['cor_fundo' => '#3b82f6', 'cor_texto' => '#ffffff', 'label' => 'Azul'],
                ['cor_fundo' => '#ef4444', 'cor_texto' => '#ffffff', 'label' => 'Vermelho'],
                ['cor_fundo' => '#f59e0b', 'cor_texto' => '#422006', 'label' => 'Âmbar'],
                ['cor_fundo' => '#0f172a', 'cor_texto' => '#f8fafc', 'label' => 'Escuro'],
                ['cor_fundo' => '#f8fafc', 'cor_texto' => '#0f172a', 'label' => 'Claro'],
                ['cor_fundo' => '#ec4899', 'cor_texto' => '#ffffff', 'label' => 'Rosa'],
                ['cor_fundo' => '#14b8a6', 'cor_texto' => '#042f2e', 'label' => 'Teal'],
                ['cor_fundo' => '#fbbf24', 'cor_texto' => '#422006', 'label' => 'Amarelo'],
            ],
            'dias' => collect(range(1, 31))->map(fn ($d) => [
                'value' => $d,
                'label' => str_pad((string) $d, 2, '0', STR_PAD_LEFT),
            ])->values()->all(),
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

            $diaLimite = $this->validateDia($atributes->dia_limite_fatura ?? null, 'Dia limite da fatura');
            $diaVencimento = $this->validateDia($atributes->dia_vencimento_fatura ?? null, 'Dia de vencimento da fatura');
            $corFundo = $this->normalizeCor($atributes->cor_fundo ?? null, 'Cor de fundo');
            $corTexto = $this->normalizeCor($atributes->cor_texto ?? null, 'Cor do texto');

            $newData = new Cartao([
                'user_id' => $userId,
                'nome' => $atributes->nome,
                'bandeira' => $atributes->bandeira ?? null,
                'banco' => $atributes->banco ?? null,
                'ultimos_digitos' => $atributes->ultimos_digitos ?? null,
                'dia_limite_fatura' => $diaLimite,
                'dia_vencimento_fatura' => $diaVencimento,
                'cor_fundo' => $corFundo,
                'cor_texto' => $corTexto,
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
            unset($data['user_id'], $data['id'], $data['cor']);

            if (array_key_exists('dia_limite_fatura', $data)) {
                $data['dia_limite_fatura'] = $this->validateDia(
                    $data['dia_limite_fatura'],
                    'Dia limite da fatura',
                    allowEmpty: true
                );
            }

            if (array_key_exists('dia_vencimento_fatura', $data)) {
                $data['dia_vencimento_fatura'] = $this->validateDia(
                    $data['dia_vencimento_fatura'],
                    'Dia de vencimento da fatura',
                    allowEmpty: true
                );
            }

            if (array_key_exists('cor_fundo', $data)) {
                $data['cor_fundo'] = $this->normalizeCor($data['cor_fundo'], 'Cor de fundo', allowEmpty: true);
            }

            if (array_key_exists('cor_texto', $data)) {
                $data['cor_texto'] = $this->normalizeCor($data['cor_texto'], 'Cor do texto', allowEmpty: true);
            }

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
            'ent.dia_limite_fatura',
            'ent.dia_vencimento_fatura',
            'ent.cor_fundo',
            'ent.cor_texto',
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
                    'ent.dia_limite_fatura',
                    'ent.dia_vencimento_fatura',
                    'ent.cor_fundo',
                    'ent.cor_texto',
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
            ->select(
                'ent.id',
                'ent.nome',
                'ent.bandeira',
                'ent.ultimos_digitos',
                'ent.dia_limite_fatura',
                'ent.dia_vencimento_fatura',
                'ent.cor_fundo',
                'ent.cor_texto',
            );

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

    /**
     * @return int|null
     */
    private function validateDia(mixed $value, string $label, bool $allowEmpty = false): ?int
    {
        if ($value === null || $value === '') {
            if ($allowEmpty) {
                return null;
            }

            throw new Exception("{$label} é obrigatório", 422);
        }

        if (!is_numeric($value)) {
            throw new Exception("{$label} deve ser um número entre 1 e 31", 422);
        }

        $dia = (int) $value;

        if ($dia < 1 || $dia > 31) {
            throw new Exception("{$label} deve ser um número entre 1 e 31", 422);
        }

        return $dia;
    }

    private function normalizeCor(mixed $value, string $label, bool $allowEmpty = true): ?string
    {
        if ($value === null || $value === '') {
            if ($allowEmpty) {
                return null;
            }

            throw new Exception("{$label} é obrigatória", 422);
        }

        $cor = strtolower(trim((string) $value));

        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $cor)) {
            throw new Exception("{$label} deve ser um hexadecimal válido (ex.: #8b5cf6)", 422);
        }

        return $cor;
    }
}
