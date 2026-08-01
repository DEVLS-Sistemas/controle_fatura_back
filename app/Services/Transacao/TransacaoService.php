<?php

namespace App\Services\Transacao;

use App\Models\Cartao;
use App\Models\Categoria;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Subcategoria;
use App\Models\Transacao;
use App\Services\Estabelecimento\EstabelecimentoService;
use App\Services\Fatura\FaturaService;
use App\Services\PaginateService;
use App\Services\Subcategoria\SubcategoriaService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransacaoService
{
    private EstabelecimentoService $estabelecimentoService;
    private SubcategoriaService $subcategoriaService;
    private FaturaService $faturaService;

    public function __construct(
        ?EstabelecimentoService $estabelecimentoService = null,
        ?SubcategoriaService $subcategoriaService = null,
        ?FaturaService $faturaService = null
    ) {
        $this->estabelecimentoService = $estabelecimentoService ?? new EstabelecimentoService();
        $this->subcategoriaService = $subcategoriaService ?? new SubcategoriaService();
        $this->faturaService = $faturaService ?? new FaturaService();
    }

    public function handleLookupsTransacao(): array
    {
        $userId = Auth::id();
        $defaultResponsavelId = $this->resolveDefaultResponsavelId($userId);

        return [
            'tipos' => [
                ['value' => 'purchase', 'label' => 'Compra'],
                ['value' => 'payment', 'label' => 'Pagamento'],
                ['value' => 'refund', 'label' => 'Estorno'],
                ['value' => 'advance', 'label' => 'Antecipação'],
            ],
            'categorias' => Categoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor']),
            'subcategorias' => Subcategoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'responsaveis' => Responsavel::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'tipo']),
            'default_responsavel_id' => $defaultResponsavelId,
            'cartoes' => \App\Models\Cartao::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'bandeira', 'ultimos_digitos']),
            'faturas' => Fatura::with('cartao:id,nome')
                ->where('user_id', $userId)
                ->orderByDesc('ano')
                ->orderByDesc('mes')
                ->get(['id', 'cartao_id', 'mes', 'ano', 'status']),
        ];
    }

    public function handleAddTransacao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->createTransacao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditTransacao(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->updateTransacao($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteTransacao(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->transacao = $this->deleteTransacao($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createTransacao(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $this->validatePayload($atributes, $userId, true);

            $valor = $this->parseValor($atributes->valor);
            $faturaId = $this->resolveFaturaId($atributes, $userId);
            $estabelecimento = $this->resolveEstabelecimento($atributes, $userId);
            $vars = get_object_vars($atributes);

            $categoriaId = array_key_exists('categoria_id', $vars)
                ? $this->normalizeNullableId($atributes->categoria_id)
                : $estabelecimento->categoria_padrao_id;

            if (array_key_exists('subcategoria_id', $vars)) {
                $subcategoriaId = $this->normalizeNullableId($atributes->subcategoria_id);
            } else {
                $subcategoriaId = $this->resolveSubcategoriaPadraoCompativel(
                    $estabelecimento->subcategoria_padrao_id,
                    $categoriaId
                );
            }

            $this->assertCategoriaSubcategoria($categoriaId, $subcategoriaId, $userId);

            $responsavelId = !empty($atributes->responsavel_id)
                ? (int) $atributes->responsavel_id
                : $this->resolveDefaultResponsavelId($userId);

            $this->assertResponsavelDoUsuario($responsavelId, $userId);

            $parcelaAtual = $this->normalizeNullableInt($atributes->parcela_atual ?? null);
            $parcelasTotal = $this->normalizeNullableInt($atributes->parcelas_total ?? null);
            $valorParcela = array_key_exists('valor_parcela', $vars) && $atributes->valor_parcela !== null && $atributes->valor_parcela !== ''
                ? $this->parseValor($atributes->valor_parcela)
                : $valor;

            $newData = new Transacao([
                'user_id' => $userId,
                'fatura_id' => $faturaId,
                'estabelecimento_id' => $estabelecimento->id,
                'data' => $atributes->data ?? null,
                'valor' => $valor,
                'parcelas_total' => $parcelasTotal,
                'parcela_atual' => $parcelaAtual,
                'valor_parcela' => $valorParcela,
                'tipo' => $atributes->tipo ?? Transacao::TIPO_PURCHASE,
                'categoria_id' => $categoriaId,
                'subcategoria_id' => $subcategoriaId,
                'responsavel_id' => $responsavelId,
                'observacoes' => $atributes->observacoes ?? null,
                'importada_pdf' => false,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Transação', 500);
            }

            return (object) [
                'data' => $this->getTransacaoId($newData->id),
                'status' => true,
                'message' => 'Transação cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateTransacao(object $atributes): object
    {
        try {
            if (empty($atributes->id) && !empty($atributes->transacao_id)) {
                $atributes->id = $atributes->transacao_id;
            }

            if (empty($atributes->id)) {
                throw new Exception('ID da transação é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = Transacao::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Transação não encontrada', 404);
            }

            $vars = get_object_vars($atributes);

            if (!empty($atributes->fatura_id) || !empty($atributes->cartao_id)) {
                $record->fatura_id = $this->resolveFaturaId($atributes, $userId, $record);
            } elseif (array_key_exists('data', $vars) && !empty($atributes->data)) {
                // Data mudou: realoca para a fatura do mesmo cartão no novo mês
                $faturaAtual = Fatura::where('id', $record->fatura_id)->first();
                if ($faturaAtual) {
                    $proxy = (object) [
                        'cartao_id' => $faturaAtual->cartao_id,
                        'data' => $atributes->data,
                    ];
                    $record->fatura_id = $this->resolveFaturaId($proxy, $userId);
                }
            }

            if (!empty($atributes->estabelecimento_id) || (isset($atributes->estabelecimento) && trim((string) $atributes->estabelecimento) !== '')) {
                $estabelecimento = $this->resolveEstabelecimento($atributes, $userId);
                $record->estabelecimento_id = $estabelecimento->id;
            }

            if (array_key_exists('data', $vars)) {
                $record->data = $atributes->data;
            }
            if (array_key_exists('valor', $vars) && $atributes->valor !== '') {
                $record->valor = $this->parseValor($atributes->valor);
            }
            if (array_key_exists('parcelas_total', $vars)) {
                $record->parcelas_total = $this->normalizeNullableInt($atributes->parcelas_total);
            }
            if (array_key_exists('parcela_atual', $vars)) {
                $record->parcela_atual = $this->normalizeNullableInt($atributes->parcela_atual);
            }
            if (array_key_exists('valor_parcela', $vars)) {
                $record->valor_parcela = ($atributes->valor_parcela === null || $atributes->valor_parcela === '')
                    ? null
                    : $this->parseValor($atributes->valor_parcela);
            }
            if (!empty($atributes->tipo)) {
                if (!in_array($atributes->tipo, Transacao::TIPOS, true)) {
                    throw new Exception('Tipo de transação inválido', 422);
                }
                $record->tipo = $atributes->tipo;
            }
            if (array_key_exists('observacoes', $vars)) {
                $record->observacoes = $atributes->observacoes;
            }

            if (!empty($atributes->responsavel_id)) {
                $this->assertResponsavelDoUsuario($atributes->responsavel_id, $userId);
                $record->responsavel_id = (int) $atributes->responsavel_id;
            }

            if (array_key_exists('categoria_id', $vars)) {
                $record->categoria_id = $this->normalizeNullableId($atributes->categoria_id);
                if ($record->categoria_id === null) {
                    $record->subcategoria_id = null;
                }
            }

            if (array_key_exists('subcategoria_id', $vars)) {
                $record->subcategoria_id = $this->normalizeNullableId($atributes->subcategoria_id);
            }

            $this->assertCategoriaSubcategoria($record->categoria_id, $record->subcategoria_id, $userId);

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Transação', 500);
            }

            return (object) [
                'data' => $this->getTransacaoId($record->id),
                'status' => true,
                'message' => 'Transação alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteTransacao(int|string $id): object
    {
        try {
            $record = Transacao::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Transação não encontrada', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Transação', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Transação excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getTransacaoPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.fatura_id',
            'ent.data',
            'ent.estabelecimento_id',
            'est.nome as estabelecimento',
            'ent.valor',
            'ent.parcelas_total',
            'ent.parcela_atual',
            'ent.valor_parcela',
            'ent.tipo',
            'ent.categoria_id',
            'cat.nome as categoria_nome',
            'cat.cor as categoria_cor',
            'ent.subcategoria_id',
            'sub.nome as subcategoria_nome',
            'ent.responsavel_id',
            'resp.nome as responsavel_nome',
            'resp.tipo as responsavel_tipo',
            'ent.observacoes',
            'f.mes as fatura_mes',
            'f.ano as fatura_ano',
            'c.id as cartao_id',
            'c.nome as cartao_nome',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('transacoes as ent');
        $this->joinClassificacao($query, 'ent');
        $query->leftJoin('responsaveis as resp', function ($join) {
            $join->on('resp.id', '=', 'ent.responsavel_id')->whereNull('resp.deleted_at');
        });
        $query->join('faturas as f', function ($join) {
            $join->on('f.id', '=', 'ent.fatura_id')->whereNull('f.deleted_at');
        });
        $query->leftJoin('cartoes as c', function ($join) {
            $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderByDesc('ent.data')->orderByDesc('ent.id');

        if (!empty($atributes->data_inicio)) {
            $query->whereDate('ent.data', '>=', $atributes->data_inicio);
        }

        if (!empty($atributes->data_fim)) {
            $query->whereDate('ent.data', '<=', $atributes->data_fim);
        }

        if (!empty($atributes->categoria_id)) {
            $query->where('ent.categoria_id', $atributes->categoria_id);
        }

        if (!empty($atributes->subcategoria_id)) {
            $query->where('ent.subcategoria_id', $atributes->subcategoria_id);
        }

        if (!empty($atributes->estabelecimento_id)) {
            $query->where('ent.estabelecimento_id', $atributes->estabelecimento_id);
        }

        if (!empty($atributes->responsavel_id)) {
            $query->where('ent.responsavel_id', $atributes->responsavel_id);
        }

        if (!empty($atributes->cartao_id)) {
            $query->where('f.cartao_id', $atributes->cartao_id);
        }

        if (!empty($atributes->fatura_id)) {
            $query->where('ent.fatura_id', $atributes->fatura_id);
        }

        if (!empty($atributes->tipo)) {
            $query->where('ent.tipo', $atributes->tipo);
        }

        if (!empty($atributes->mes)) {
            $query->where('f.mes', (int) $atributes->mes);
        }

        if (!empty($atributes->ano)) {
            $query->where('f.ano', (int) $atributes->ano);
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('est.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.observacoes', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%')
                    ->orWhere('sub.nome', 'like', '%' . $chave . '%')
                    ->orWhere('resp.nome', 'like', '%' . $chave . '%');
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

    public function getTransacaoId(int|string $id): array
    {
        try {
            $query = DB::table('transacoes as ent');
            $this->joinClassificacao($query, 'ent');
            $query->leftJoin('responsaveis as resp', function ($join) {
                $join->on('resp.id', '=', 'ent.responsavel_id')->whereNull('resp.deleted_at');
            })
                ->join('faturas as f', function ($join) {
                    $join->on('f.id', '=', 'ent.fatura_id')->whereNull('f.deleted_at');
                })
                ->leftJoin('cartoes as c', function ($join) {
                    $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.fatura_id',
                    'ent.data',
                    'ent.estabelecimento_id',
                    'est.nome as estabelecimento',
                    'ent.valor',
                    'ent.parcelas_total',
                    'ent.parcela_atual',
                    'ent.valor_parcela',
                    'ent.tipo',
                    'ent.categoria_id',
                    'cat.nome as categoria_nome',
                    'cat.cor as categoria_cor',
                    'ent.subcategoria_id',
                    'sub.nome as subcategoria_nome',
                    'ent.responsavel_id',
                    'resp.nome as responsavel_nome',
                    'resp.tipo as responsavel_tipo',
                    'ent.observacoes',
                    'f.mes as fatura_mes',
                    'f.ano as fatura_ano',
                    'c.id as cartao_id',
                    'c.nome as cartao_nome',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Transação não encontrada', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getTransacaoAsync(object $params): array
    {
        $query = DB::table('transacoes as ent')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 'ent.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 'ent.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select('ent.id', 'est.nome as nome', 'ent.valor', 'ent.data');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('est.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderByDesc('ent.data')->get()->toArray();
    }

    /**
     * Exporta transações filtradas em CSV (compatível com Excel).
     */
    public function exportTransacoesCsv(object $atributes): string
    {
        $atributes->page = 1;
        $atributes->perPage = 100000;
        $resultado = $this->getTransacaoPaginate($atributes);
        $rows = $resultado['data'] ?? [];

        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($handle, [
            'ID',
            'Data',
            'Estabelecimento',
            'Valor',
            'Tipo',
            'Categoria',
            'Subcategoria',
            'Responsavel',
            'Tipo Responsavel',
            'Cartao',
            'Fatura',
            'Parcelas',
            'Observacoes',
        ], ';');

        foreach ($rows as $row) {
            $row = (array) $row;
            $parcelas = '';
            if (!empty($row['parcela_atual']) && !empty($row['parcelas_total'])) {
                $parcelas = $row['parcela_atual'] . '/' . $row['parcelas_total'];
            }

            fputcsv($handle, [
                $row['id'] ?? '',
                $row['data'] ?? '',
                $row['estabelecimento'] ?? '',
                number_format((float) ($row['valor'] ?? 0), 2, ',', '.'),
                $row['tipo'] ?? '',
                $row['categoria_nome'] ?? '',
                $row['subcategoria_nome'] ?? '',
                $row['responsavel_nome'] ?? '',
                $row['responsavel_tipo'] ?? '',
                $row['cartao_nome'] ?? '',
                (!empty($row['fatura_mes']) ? str_pad((string) $row['fatura_mes'], 2, '0', STR_PAD_LEFT) . '/' . ($row['fatura_ano'] ?? '') : ''),
                $parcelas,
                $row['observacoes'] ?? '',
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    public function resolveDefaultResponsavelId(int $userId): int
    {
        $responsavel = Responsavel::where('user_id', $userId)
            ->where('nome', 'Eu')
            ->where('ativo', true)
            ->first();

        if ($responsavel) {
            return (int) $responsavel->id;
        }

        $responsavel = Responsavel::create([
            'user_id' => $userId,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);

        return (int) $responsavel->id;
    }

    private function joinClassificacao($query, string $alias = 'ent'): void
    {
        $query->leftJoin('estabelecimentos as est', function ($join) use ($alias) {
            $join->on('est.id', '=', "{$alias}.estabelecimento_id")->whereNull('est.deleted_at');
        });
        $query->leftJoin('categorias as cat', function ($join) use ($alias) {
            $join->on('cat.id', '=', "{$alias}.categoria_id")->whereNull('cat.deleted_at');
        });
        $query->leftJoin('subcategorias as sub', function ($join) use ($alias) {
            $join->on('sub.id', '=', "{$alias}.subcategoria_id")->whereNull('sub.deleted_at');
        });
    }

    private function validatePayload(object $atributes, int $userId, bool $creating): void
    {
        $hasFatura = !empty($atributes->fatura_id);
        $hasCartao = !empty($atributes->cartao_id);

        if ($creating && !$hasFatura && !$hasCartao) {
            throw new Exception('Cartão é obrigatório', 422);
        }

        $hasEstabelecimentoId = !empty($atributes->estabelecimento_id);
        $hasEstabelecimentoNome = isset($atributes->estabelecimento) && trim((string) $atributes->estabelecimento) !== '';

        if ($creating && !$hasEstabelecimentoId && !$hasEstabelecimentoNome) {
            throw new Exception('Estabelecimento é obrigatório', 422);
        }

        if (!isset($atributes->valor) || $atributes->valor === '') {
            throw new Exception('Valor é obrigatório', 422);
        }

        $tipo = $atributes->tipo ?? Transacao::TIPO_PURCHASE;
        if (!in_array($tipo, Transacao::TIPOS, true)) {
            throw new Exception('Tipo de transação inválido', 422);
        }

        if ($hasFatura) {
            $this->assertFaturaDoUsuario($atributes->fatura_id, $userId);
        }

        if ($hasCartao) {
            $this->assertCartaoDoUsuario($atributes->cartao_id, $userId);
        }

        if (!empty($atributes->responsavel_id)) {
            $this->assertResponsavelDoUsuario($atributes->responsavel_id, $userId);
        }
    }

    /**
     * Resolve fatura_id a partir de fatura_id explícito ou cartao_id + data (mes/ano).
     * Se a fatura do período não existir, cria automaticamente (pendente).
     */
    private function resolveFaturaId(object $atributes, int $userId, ?Transacao $atual = null): int
    {
        if (!empty($atributes->fatura_id)) {
            $this->assertFaturaDoUsuario($atributes->fatura_id, $userId);

            return (int) $atributes->fatura_id;
        }

        $cartaoId = !empty($atributes->cartao_id)
            ? (int) $atributes->cartao_id
            : null;

        if ($cartaoId === null && $atual) {
            $faturaAtual = Fatura::where('id', $atual->fatura_id)->first();
            $cartaoId = $faturaAtual?->cartao_id;
        }

        if ($cartaoId === null) {
            throw new Exception('Cartão é obrigatório', 422);
        }

        $this->assertCartaoDoUsuario($cartaoId, $userId);

        $dataRef = !empty($atributes->data)
            ? Carbon::parse($atributes->data)
            : ($atual && $atual->data ? Carbon::parse($atual->data) : now());

        $fatura = $this->faturaService->findOrCreateByCartaoPeriodo(
            $userId,
            $cartaoId,
            (int) $dataRef->month,
            (int) $dataRef->year
        );

        return (int) $fatura->id;
    }

    private function assertCartaoDoUsuario(int|string $cartaoId, int $userId): void
    {
        $exists = Cartao::where('id', $cartaoId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Cartão não encontrado', 404);
        }
    }

    /**
     * Aceita "125,50", "1.234,56", "125.50" ou número.
     */
    private function parseValor(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            throw new Exception('Valor é obrigatório', 422);
        }

        $raw = str_replace(['R$', ' '], '', $raw);
        $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? $raw;

        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }

        if (!is_numeric($raw)) {
            throw new Exception('Valor inválido', 422);
        }

        return round((float) $raw, 2);
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function resolveEstabelecimento(object $atributes, int $userId): Estabelecimento
    {
        if (!empty($atributes->estabelecimento_id)) {
            $record = Estabelecimento::where('id', $atributes->estabelecimento_id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            return $record;
        }

        return $this->estabelecimentoService->findOrCreateByNome(
            $userId,
            (string) ($atributes->estabelecimento ?? 'Desconhecido')
        );
    }

    private function assertCategoriaSubcategoria(?int $categoriaId, ?int $subcategoriaId, int $userId): void
    {
        if ($subcategoriaId !== null && $categoriaId === null) {
            throw new Exception('Subcategoria exige categoria informada', 422);
        }

        if ($categoriaId !== null) {
            $exists = Categoria::where('id', $categoriaId)->where('user_id', $userId)->exists();
            if (!$exists) {
                throw new Exception('Categoria não encontrada', 404);
            }
        }

        if ($subcategoriaId !== null) {
            $this->subcategoriaService->assertSubcategoriaValidaParaCategoria(
                $subcategoriaId,
                $categoriaId,
                $userId
            );
        }
    }

    private function assertFaturaDoUsuario(int|string $faturaId, int $userId): void
    {
        $exists = Fatura::where('id', $faturaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Fatura não encontrada', 404);
        }
    }

    private function assertResponsavelDoUsuario(int|string $responsavelId, int $userId): void
    {
        $exists = Responsavel::where('id', $responsavelId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Responsável não encontrado', 404);
        }
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function resolveSubcategoriaPadraoCompativel(?int $subcategoriaPadraoId, ?int $categoriaId): ?int
    {
        if ($subcategoriaPadraoId === null || $categoriaId === null) {
            return null;
        }

        $vinculo = DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->where('subcategoria_id', $subcategoriaPadraoId)
            ->exists();

        return $vinculo ? $subcategoriaPadraoId : null;
    }
}
