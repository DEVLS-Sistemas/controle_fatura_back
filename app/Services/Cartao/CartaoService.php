<?php

namespace App\Services\Cartao;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
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
            'tipos_numero' => [
                ['value' => 'fisico', 'label' => 'Físico'],
                ['value' => 'virtual', 'label' => 'Virtual'],
                ['value' => 'adicional', 'label' => 'Adicional'],
            ],
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

            $diaLimite = $this->validateDia($atributes->dia_limite_fatura ?? null, 'Dia limite da fatura');
            $diaVencimento = $this->validateDia($atributes->dia_vencimento_fatura ?? null, 'Dia de vencimento da fatura');
            $corFundo = $this->normalizeCor($atributes->cor_fundo ?? null, 'Cor de fundo');
            $corTexto = $this->normalizeCor($atributes->cor_texto ?? null, 'Cor do texto');

            $newData = new Cartao([
                'user_id' => $userId,
                'nome' => $atributes->nome,
                'banco' => $atributes->banco ?? null,
                'dia_limite_fatura' => $diaLimite,
                'dia_vencimento_fatura' => $diaVencimento,
                'cor_fundo' => $corFundo,
                'cor_texto' => $corTexto,
                'ativo' => $this->normalizeBool($atributes->ativo ?? true, true),
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Cartão', 500);
            }

            $this->syncBandeiras($newData, $atributes->bandeiras ?? [], [], []);

            return (object) [
                'data' => $this->formatCartaoPayload($newData->id),
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
            $id = $atributes->id ?? $atributes->cartao_id ?? null;

            if (empty($id)) {
                throw new Exception('ID do cartão é obrigatório', 422);
            }

            $record = Cartao::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Cartão não encontrado', 404);
            }

            if (property_exists($atributes, 'nome') || isset($atributes->nome)) {
                if (array_key_exists('nome', get_object_vars($atributes)) && empty($atributes->nome)) {
                    throw new Exception('O nome do cartão é obrigatório', 422);
                }
                if (!empty($atributes->nome)) {
                    $record->nome = $atributes->nome;
                }
            }

            if (array_key_exists('banco', get_object_vars($atributes))) {
                $record->banco = $atributes->banco ?: null;
            }

            if (array_key_exists('dia_limite_fatura', get_object_vars($atributes))) {
                $record->dia_limite_fatura = $this->validateDia(
                    $atributes->dia_limite_fatura,
                    'Dia limite da fatura',
                    allowEmpty: true
                );
            }

            if (array_key_exists('dia_vencimento_fatura', get_object_vars($atributes))) {
                $record->dia_vencimento_fatura = $this->validateDia(
                    $atributes->dia_vencimento_fatura,
                    'Dia de vencimento da fatura',
                    allowEmpty: true
                );
            }

            if (array_key_exists('cor_fundo', get_object_vars($atributes))) {
                $record->cor_fundo = $this->normalizeCor($atributes->cor_fundo, 'Cor de fundo', allowEmpty: true);
            }

            if (array_key_exists('cor_texto', get_object_vars($atributes))) {
                $record->cor_texto = $this->normalizeCor($atributes->cor_texto, 'Cor do texto', allowEmpty: true);
            }

            if (array_key_exists('ativo', get_object_vars($atributes))) {
                $record->ativo = $this->normalizeBool($atributes->ativo, $record->ativo);
            }

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Cartão', 500);
            }

            if (array_key_exists('bandeiras', get_object_vars($atributes))
                || array_key_exists('bandeiras_remover', get_object_vars($atributes))
                || array_key_exists('numeros_remover', get_object_vars($atributes))
            ) {
                $this->syncBandeiras(
                    $record,
                    $atributes->bandeiras ?? [],
                    $this->normalizeIdList($atributes->bandeiras_remover ?? []),
                    $this->normalizeIdList($atributes->numeros_remover ?? [])
                );
            }

            return (object) [
                'data' => $this->formatCartaoPayload($record->id),
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

            $bandeiraIds = CartaoBandeira::where('cartao_id', $record->id)->pluck('id');

            CartaoNumero::whereIn('cartao_bandeira_id', $bandeiraIds)->delete();
            CartaoBandeira::where('cartao_id', $record->id)->delete();

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
            'ent.banco',
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
            $bandeira = $atributes->bandeira;
            $query->whereExists(function ($q) use ($bandeira) {
                $q->select(DB::raw(1))
                    ->from('cartao_bandeiras as cb')
                    ->whereColumn('cb.cartao_id', 'ent.id')
                    ->whereNull('cb.deleted_at')
                    ->where('cb.bandeira', $bandeira);
            });
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
                    ->orWhereExists(function ($sub) use ($chave) {
                        $sub->select(DB::raw(1))
                            ->from('cartao_bandeiras as cb')
                            ->leftJoin('cartao_numeros as cn', function ($join) {
                                $join->on('cn.cartao_bandeira_id', '=', 'cb.id')
                                    ->whereNull('cn.deleted_at');
                            })
                            ->whereColumn('cb.cartao_id', 'ent.id')
                            ->whereNull('cb.deleted_at')
                            ->where(function ($inner) use ($chave) {
                                $inner->where('cb.bandeira', 'like', '%' . $chave . '%')
                                    ->orWhere('cn.ultimos_digitos', 'like', '%' . $chave . '%')
                                    ->orWhere('cn.apelido', 'like', '%' . $chave . '%');
                            });
                    });
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

        $array = collect($resultado)->toArray();
        $items = collect($array['data'] ?? [])->map(function ($row) {
            return $this->formatCartaoPayload((int) $row->id);
        })->values()->all();

        $array['data'] = $items;

        return $array;
    }

    public function getCartaoId(int|string $id): array
    {
        try {
            $exists = Cartao::where('id', $id)
                ->where('user_id', Auth::id())
                ->whereNull('deleted_at')
                ->exists();

            if (!$exists) {
                throw new Exception('Cartão não encontrado', 404);
            }

            return $this->formatCartaoPayload((int) $id);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getCartaoAsync(object $params): array
    {
        $query = Cartao::query()
            ->where('user_id', Auth::id())
            ->where('ativo', true)
            ->with(['bandeiras' => function ($q) {
                $q->whereNull('deleted_at')->where('ativo', true)->orderBy('bandeira');
            }]);

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('nome', 'like', '%' . $chave . '%')
                    ->orWhere('banco', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderBy('nome')->get()->map(function (Cartao $cartao) {
            return [
                'id' => $cartao->id,
                'nome' => $cartao->nome,
                'banco' => $cartao->banco,
                'dia_limite_fatura' => $cartao->dia_limite_fatura,
                'dia_vencimento_fatura' => $cartao->dia_vencimento_fatura,
                'cor_fundo' => $cartao->cor_fundo,
                'cor_texto' => $cartao->cor_texto,
                'qtd_bandeiras' => $cartao->bandeiras->count(),
                'bandeiras' => $cartao->bandeiras->map(fn (CartaoBandeira $b) => [
                    'id' => $b->id,
                    'bandeira' => $b->bandeira,
                    'limite_credito' => $b->limite_credito,
                ])->values()->all(),
            ];
        })->all();
    }

    public function getBandeirasList(object $params): array
    {
        if (empty($params->cartao_id)) {
            throw new Exception('cartao_id é obrigatório', 422);
        }

        $cartao = Cartao::where('id', $params->cartao_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$cartao) {
            throw new Exception('Cartão não encontrado', 404);
        }

        return CartaoBandeira::query()
            ->where('cartao_id', $cartao->id)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->withCount(['numeros' => function ($q) {
                $q->whereNull('deleted_at')->where('ativo', true);
            }])
            ->orderBy('bandeira')
            ->get()
            ->map(fn (CartaoBandeira $b) => [
                'value' => $b->id,
                'label' => $b->bandeira,
                'limite_credito' => $b->limite_credito,
                'qtd_numeros' => (int) $b->numeros_count,
            ])
            ->all();
    }

    public function getNumerosList(object $params): array
    {
        if (empty($params->cartao_bandeira_id)) {
            throw new Exception('cartao_bandeira_id é obrigatório', 422);
        }

        $bandeira = CartaoBandeira::query()
            ->where('id', $params->cartao_bandeira_id)
            ->whereNull('deleted_at')
            ->whereHas('cartao', function ($q) {
                $q->where('user_id', Auth::id())->whereNull('deleted_at');
            })
            ->first();

        if (!$bandeira) {
            throw new Exception('Bandeira não encontrada', 404);
        }

        return CartaoNumero::query()
            ->where('cartao_bandeira_id', $bandeira->id)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->orderBy('ultimos_digitos')
            ->get()
            ->map(function (CartaoNumero $n) {
                $label = '•••• ' . $n->ultimos_digitos;
                if (!empty($n->apelido)) {
                    $label .= ' (' . $n->apelido . ')';
                }

                return [
                    'value' => $n->id,
                    'label' => $label,
                    'ultimos_digitos' => $n->ultimos_digitos,
                    'tipo' => $n->tipo,
                    'apelido' => $n->apelido,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $bandeirasInput
     * @param  array<int, int>  $bandeirasRemover
     * @param  array<int, int>  $numerosRemover
     */
    private function syncBandeiras(
        Cartao $cartao,
        array $bandeirasInput,
        array $bandeirasRemover,
        array $numerosRemover
    ): void {
        if (!empty($numerosRemover)) {
            CartaoNumero::query()
                ->whereIn('id', $numerosRemover)
                ->whereHas('bandeira', function ($q) use ($cartao) {
                    $q->where('cartao_id', $cartao->id);
                })
                ->delete();
        }

        if (!empty($bandeirasRemover)) {
            $bandeiras = CartaoBandeira::query()
                ->where('cartao_id', $cartao->id)
                ->whereIn('id', $bandeirasRemover)
                ->get();

            foreach ($bandeiras as $bandeira) {
                CartaoNumero::where('cartao_bandeira_id', $bandeira->id)->delete();
                $bandeira->delete();
            }
        }

        $seenBandeiraIds = [];
        $seenBandeiraNames = [];

        foreach ($bandeirasInput as $item) {
            $payload = $this->normalizeBandeiraItem($item);

            if ($payload['id']) {
                $bandeira = CartaoBandeira::query()
                    ->where('id', $payload['id'])
                    ->where('cartao_id', $cartao->id)
                    ->first();

                if (!$bandeira) {
                    throw new Exception('Bandeira #' . $payload['id'] . ' não pertence a este cartão', 422);
                }
            } else {
                $bandeira = CartaoBandeira::query()
                    ->where('cartao_id', $cartao->id)
                    ->where('bandeira', $payload['bandeira'])
                    ->whereNull('deleted_at')
                    ->first();

                if (!$bandeira) {
                    $bandeira = new CartaoBandeira([
                        'cartao_id' => $cartao->id,
                        'bandeira' => $payload['bandeira'],
                    ]);
                }
            }

            $nameKey = mb_strtolower($payload['bandeira']);
            if (isset($seenBandeiraNames[$nameKey]) && (!$payload['id'] || (int) $seenBandeiraNames[$nameKey] !== (int) $bandeira->id)) {
                throw new Exception("Bandeira duplicada no payload: {$payload['bandeira']}", 422);
            }

            $bandeira->bandeira = $payload['bandeira'];
            $bandeira->limite_credito = $payload['limite_credito'];
            $bandeira->ativo = $payload['ativo'];
            $bandeira->save();

            $seenBandeiraIds[] = (int) $bandeira->id;
            $seenBandeiraNames[$nameKey] = (int) $bandeira->id;

            $this->syncNumeros($bandeira, $payload['numeros']);
        }
    }

    /**
     * @param  array<int, mixed>  $numerosInput
     */
    private function syncNumeros(CartaoBandeira $bandeira, array $numerosInput): void
    {
        $seenDigitos = [];

        foreach ($numerosInput as $item) {
            $payload = $this->normalizeNumeroItem($item);

            if (isset($seenDigitos[$payload['ultimos_digitos']])) {
                throw new Exception(
                    "Final {$payload['ultimos_digitos']} duplicado na bandeira {$bandeira->bandeira}",
                    422
                );
            }
            $seenDigitos[$payload['ultimos_digitos']] = true;

            if ($payload['id']) {
                $numero = CartaoNumero::query()
                    ->where('id', $payload['id'])
                    ->where('cartao_bandeira_id', $bandeira->id)
                    ->first();

                if (!$numero) {
                    throw new Exception('Número #' . $payload['id'] . ' não pertence a esta bandeira', 422);
                }
            } else {
                $numero = CartaoNumero::query()
                    ->where('cartao_bandeira_id', $bandeira->id)
                    ->where('ultimos_digitos', $payload['ultimos_digitos'])
                    ->whereNull('deleted_at')
                    ->first();

                if (!$numero) {
                    $numero = new CartaoNumero([
                        'cartao_bandeira_id' => $bandeira->id,
                        'ultimos_digitos' => $payload['ultimos_digitos'],
                    ]);
                }
            }

            $duplicado = CartaoNumero::query()
                ->where('cartao_bandeira_id', $bandeira->id)
                ->where('ultimos_digitos', $payload['ultimos_digitos'])
                ->whereNull('deleted_at')
                ->when($numero->exists, fn ($q) => $q->where('id', '!=', $numero->id))
                ->exists();

            if ($duplicado) {
                throw new Exception(
                    "Já existe o final {$payload['ultimos_digitos']} na bandeira {$bandeira->bandeira}",
                    422
                );
            }

            $numero->ultimos_digitos = $payload['ultimos_digitos'];
            $numero->tipo = $payload['tipo'];
            $numero->apelido = $payload['apelido'];
            $numero->ativo = $payload['ativo'];
            $numero->save();
        }
    }

    /**
     * @return array{id: int|null, bandeira: string, limite_credito: float|null, ativo: bool, numeros: array<int, mixed>}
     */
    private function normalizeBandeiraItem(mixed $item): array
    {
        $data = is_array($item) ? $item : (array) $item;

        $bandeira = trim((string) ($data['bandeira'] ?? ''));
        if ($bandeira === '') {
            throw new Exception('Bandeira é obrigatória em cada item de bandeiras', 422);
        }

        $bandeirasValidas = $this->handleLookupsCartao()['bandeiras'];
        if (!in_array($bandeira, $bandeirasValidas, true)) {
            throw new Exception("Bandeira inválida: {$bandeira}", 422);
        }

        return [
            'id' => !empty($data['id']) ? (int) $data['id'] : null,
            'bandeira' => $bandeira,
            'limite_credito' => array_key_exists('limite_credito', $data)
                ? $this->normalizeLimiteCredito($data['limite_credito'])
                : null,
            'ativo' => $this->normalizeBool($data['ativo'] ?? true, true),
            'numeros' => array_values($data['numeros'] ?? []),
        ];
    }

    /**
     * @return array{id: int|null, ultimos_digitos: string, tipo: string|null, apelido: string|null, ativo: bool}
     */
    private function normalizeNumeroItem(mixed $item): array
    {
        $data = is_array($item) ? $item : (array) $item;

        $digitos = trim((string) ($data['ultimos_digitos'] ?? ''));
        if (!preg_match('/^\d{4}$/', $digitos)) {
            throw new Exception('Últimos dígitos devem conter 4 números', 422);
        }

        $tipo = $data['tipo'] ?? null;
        if ($tipo !== null && $tipo !== '') {
            $tipo = (string) $tipo;
            if (!in_array($tipo, CartaoNumero::TIPOS, true)) {
                throw new Exception('Tipo de cartão inválido', 422);
            }
        } else {
            $tipo = null;
        }

        $apelido = isset($data['apelido']) ? trim((string) $data['apelido']) : null;
        if ($apelido === '') {
            $apelido = null;
        }

        return [
            'id' => !empty($data['id']) ? (int) $data['id'] : null,
            'ultimos_digitos' => $digitos,
            'tipo' => $tipo,
            'apelido' => $apelido,
            'ativo' => $this->normalizeBool($data['ativo'] ?? true, true),
        ];
    }

    private function formatCartaoPayload(int $id): array
    {
        $cartao = Cartao::query()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->with(['bandeiras' => function ($q) {
                $q->whereNull('deleted_at')
                    ->orderBy('bandeira')
                    ->with(['numeros' => function ($n) {
                        $n->whereNull('deleted_at')->orderBy('ultimos_digitos');
                    }]);
            }])
            ->firstOrFail();

        $bandeiras = $cartao->bandeiras->map(function (CartaoBandeira $b) {
            return [
                'id' => $b->id,
                'bandeira' => $b->bandeira,
                'limite_credito' => $b->limite_credito,
                'ativo' => (bool) $b->ativo,
                'numeros' => $b->numeros->map(fn (CartaoNumero $n) => [
                    'id' => $n->id,
                    'ultimos_digitos' => $n->ultimos_digitos,
                    'tipo' => $n->tipo,
                    'apelido' => $n->apelido,
                    'ativo' => (bool) $n->ativo,
                ])->values()->all(),
            ];
        })->values()->all();

        $qtdNumeros = collect($bandeiras)->sum(fn ($b) => count($b['numeros']));

        return [
            'id' => $cartao->id,
            'nome' => $cartao->nome,
            'banco' => $cartao->banco,
            'dia_limite_fatura' => $cartao->dia_limite_fatura,
            'dia_vencimento_fatura' => $cartao->dia_vencimento_fatura,
            'cor_fundo' => $cartao->cor_fundo,
            'cor_texto' => $cartao->cor_texto,
            'ativo' => (bool) $cartao->ativo,
            'qtd_bandeiras' => count($bandeiras),
            'qtd_numeros' => $qtdNumeros,
            'bandeiras' => $bandeiras,
            'created_at' => optional($cartao->created_at)?->toJSON(),
            'updated_at' => optional($cartao->updated_at)?->toJSON(),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Limite de crédito da bandeira (opcional). Aceita número ou string BR (ex.: "5.000,00").
     */
    private function normalizeLimiteCredito(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $limite = round((float) $value, 2);
        } else {
            $raw = trim((string) $value);
            $raw = str_replace(['R$', ' '], '', $raw);
            $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? $raw;

            if (str_contains($raw, ',') && str_contains($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } elseif (str_contains($raw, ',')) {
                $raw = str_replace(',', '.', $raw);
            }

            if (!is_numeric($raw)) {
                throw new Exception('Limite de crédito inválido', 422);
            }

            $limite = round((float) $raw, 2);
        }

        if ($limite <= 0) {
            throw new Exception('Limite de crédito deve ser maior que zero', 422);
        }

        if ($limite > 9999999999.99) {
            throw new Exception('Limite de crédito excede o valor máximo permitido', 422);
        }

        return $limite;
    }

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
