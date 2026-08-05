<?php

namespace App\Services\Repasse;

use App\Models\Repasse;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Services\PaginateService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RepasseService
{
    private const MESES_LABEL = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    private const TOLERANCIA = 0.01;

    public function handleLookupsRepasse(): array
    {
        $userId = Auth::id();

        return [
            'status_repasse' => array_map(
                fn ($value) => [
                    'value' => $value,
                    'label' => Repasse::STATUS_LABELS[$value] ?? $value,
                ],
                Repasse::STATUS
            ),
            'responsaveis' => Responsavel::query()
                ->where('user_id', $userId)
                ->where('ativo', true)
                ->whereNull('deleted_at')
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->toArray(),
        ];
    }

    public function handleAddRepasse(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->repasse = $this->createRepasse($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditRepasse(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->repasse = $this->updateRepasse($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteRepasse(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->repasse = $this->deleteRepasse($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleQuitarCompetencia(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->quitar = $this->quitarCompetencia($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createRepasse(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $transacao = $this->resolveTransacaoPurchase($atributes->transacao_id ?? null, $userId);

            $dataPagamento = $this->parseDate($atributes->data_pagamento ?? null);
            $quitar = filter_var($atributes->quitar ?? false, FILTER_VALIDATE_BOOLEAN);

            $pagoAtual = $this->sumValorPago((int) $transacao->id, $userId);
            $aberto = $this->roundMoney(max((float) $transacao->valor - $pagoAtual, 0));

            if ($quitar) {
                if ($aberto <= self::TOLERANCIA) {
                    throw new Exception('Parcela já está quitada', 422);
                }
                $valor = $aberto;
            } else {
                if (!isset($atributes->valor) || $atributes->valor === '' || $atributes->valor === null) {
                    throw new Exception('Valor do repasse é obrigatório', 422);
                }
                $valor = $this->parseValor($atributes->valor);
                if ($valor <= 0) {
                    throw new Exception('Valor do repasse deve ser maior que zero', 422);
                }
                if ($valor > $aberto + self::TOLERANCIA) {
                    throw new Exception(
                        'Valor do repasse (R$ ' . number_format($valor, 2, ',', '.')
                        . ') excede o em aberto (R$ ' . number_format($aberto, 2, ',', '.') . ')',
                        422
                    );
                }
            }

            $repasse = new Repasse([
                'user_id' => $userId,
                'transacao_id' => $transacao->id,
                'valor' => $valor,
                'data_pagamento' => $dataPagamento,
                'observacoes' => $atributes->observacoes ?? null,
            ]);

            if (!$repasse->save()) {
                throw new Exception('Não foi possível cadastrar Repasse', 500);
            }

            return (object) [
                'data' => $this->getRepasseId($repasse->id),
                'status' => true,
                'message' => 'Repasse cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateRepasse(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID do repasse é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = Repasse::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Repasse não encontrado', 404);
            }

            $transacao = $this->resolveTransacaoPurchase($record->transacao_id, $userId);
            $vars = get_object_vars($atributes);

            if (array_key_exists('data_pagamento', $vars) && $atributes->data_pagamento !== null && $atributes->data_pagamento !== '') {
                $record->data_pagamento = $this->parseDate($atributes->data_pagamento);
            }

            if (array_key_exists('observacoes', $vars)) {
                $record->observacoes = $atributes->observacoes;
            }

            if (array_key_exists('valor', $vars) && $atributes->valor !== null && $atributes->valor !== '') {
                $valor = $this->parseValor($atributes->valor);
                if ($valor <= 0) {
                    throw new Exception('Valor do repasse deve ser maior que zero', 422);
                }

                $pagoOutros = $this->sumValorPago((int) $transacao->id, $userId, (int) $record->id);
                $abertoDisponivel = $this->roundMoney(max((float) $transacao->valor - $pagoOutros, 0));

                if ($valor > $abertoDisponivel + self::TOLERANCIA) {
                    throw new Exception(
                        'Valor do repasse (R$ ' . number_format($valor, 2, ',', '.')
                        . ') excede o em aberto (R$ ' . number_format($abertoDisponivel, 2, ',', '.') . ')',
                        422
                    );
                }

                $record->valor = $valor;
            }

            if (!$record->save()) {
                throw new Exception('Não foi possível editar Repasse', 500);
            }

            return (object) [
                'data' => $this->getRepasseId($record->id),
                'status' => true,
                'message' => 'Repasse alterado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteRepasse(int|string $id): object
    {
        try {
            $record = Repasse::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Repasse não encontrado', 404);
            }

            if (!$record->delete()) {
                throw new Exception('Não foi possível excluir Repasse', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Repasse excluído com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function quitarCompetencia(object $atributes): object
    {
        $userId = Auth::id();

        if (empty($atributes->responsavel_id)) {
            throw new Exception('responsavel_id é obrigatório', 422);
        }
        if (empty($atributes->mes) || empty($atributes->ano)) {
            throw new Exception('mes e ano são obrigatórios', 422);
        }

        $this->assertResponsavelDoUsuario((int) $atributes->responsavel_id, $userId);

        $dataPagamento = $this->parseDate($atributes->data_pagamento ?? null);
        $mes = (int) $atributes->mes;
        $ano = (int) $atributes->ano;

        $parcelas = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('t.responsavel_id', (int) $atributes->responsavel_id)
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->where('f.mes', $mes)
            ->where('f.ano', $ano)
            ->select('t.id', 't.valor')
            ->get();

        if ($parcelas->isEmpty()) {
            throw new Exception('Nenhuma parcela encontrada nesta competência para o responsável', 404);
        }

        $criados = [];
        foreach ($parcelas as $parcela) {
            $pago = $this->sumValorPago((int) $parcela->id, $userId);
            $aberto = $this->roundMoney(max((float) $parcela->valor - $pago, 0));
            if ($aberto <= self::TOLERANCIA) {
                continue;
            }

            $repasse = Repasse::create([
                'user_id' => $userId,
                'transacao_id' => (int) $parcela->id,
                'valor' => $aberto,
                'data_pagamento' => $dataPagamento,
                'observacoes' => $atributes->observacoes ?? null,
            ]);

            $criados[] = $this->getRepasseId($repasse->id);
        }

        return (object) [
            'data' => [
                'qtd_repasses' => count($criados),
                'repasses' => $criados,
                'mes' => $mes,
                'ano' => $ano,
                'responsavel_id' => (int) $atributes->responsavel_id,
            ],
            'status' => true,
            'message' => count($criados) > 0
                ? count($criados) . ' parcela(s) quitada(s) com sucesso!'
                : 'Nenhuma parcela em aberto nesta competência.',
        ];
    }

    public function getRepassePaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.transacao_id',
            'ent.valor',
            'ent.data_pagamento',
            'ent.observacoes',
            't.valor as valor_parcela',
            't.parcela_atual',
            't.parcelas_total',
            't.compra_grupo_id',
            't.responsavel_id',
            'est.nome as estabelecimento',
            'f.mes as fatura_mes',
            'f.ano as fatura_ano',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('repasses as ent');
        $query->join('transacoes as t', function ($join) {
            $join->on('t.id', '=', 'ent.transacao_id')->whereNull('t.deleted_at');
        });
        $query->join('faturas as f', function ($join) {
            $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
        });
        $query->leftJoin('estabelecimentos as est', function ($join) {
            $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderByDesc('ent.data_pagamento')->orderByDesc('ent.id');

        if (!empty($atributes->transacao_id)) {
            $query->where('ent.transacao_id', (int) $atributes->transacao_id);
        }

        if (!empty($atributes->responsavel_id)) {
            $query->where('t.responsavel_id', (int) $atributes->responsavel_id);
        }

        if (!empty($atributes->mes)) {
            $query->where('f.mes', (int) $atributes->mes);
        }

        if (!empty($atributes->ano)) {
            $query->where('f.ano', (int) $atributes->ano);
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

    public function getRepasseId(int|string $id): array
    {
        try {
            $data = DB::table('repasses as ent')
                ->join('transacoes as t', function ($join) {
                    $join->on('t.id', '=', 'ent.transacao_id')->whereNull('t.deleted_at');
                })
                ->join('faturas as f', function ($join) {
                    $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
                })
                ->leftJoin('estabelecimentos as est', function ($join) {
                    $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.transacao_id',
                    'ent.valor',
                    'ent.data_pagamento',
                    'ent.observacoes',
                    't.valor as valor_parcela',
                    't.parcela_atual',
                    't.parcelas_total',
                    't.compra_grupo_id',
                    't.responsavel_id',
                    'est.nome as estabelecimento',
                    'f.mes as fatura_mes',
                    'f.ano as fatura_ano',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id)
                ->first();

            if (!$data) {
                throw new Exception('Repasse não encontrado', 404);
            }

            return collect($data)->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getRepasseAsync(object $params): array
    {
        $query = DB::table('repasses as ent')
            ->join('transacoes as t', function ($join) {
                $join->on('t.id', '=', 'ent.transacao_id')->whereNull('t.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select('ent.id', 'est.nome as nome', 'ent.valor', 'ent.data_pagamento');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('est.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.observacoes', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderByDesc('ent.data_pagamento')->get()->toArray();
    }

    public function handleMatriz(object $atributes): object
    {
        try {
            $userId = Auth::id();

            if (empty($atributes->responsavel_id)) {
                throw new Exception('responsavel_id é obrigatório', 422);
            }

            $responsavelId = (int) $atributes->responsavel_id;
            $responsavel = Responsavel::where('id', $responsavelId)
                ->where('user_id', $userId)
                ->first();

            if (!$responsavel) {
                throw new Exception('Responsável não encontrado', 404);
            }

            $referencia = Carbon::create(
                (int) ($atributes->ano ?? now()->year),
                (int) ($atributes->mes ?? now()->month),
                1
            );
            $janela = max(1, min(36, (int) ($atributes->janela ?? 13)));
            $colunas = $this->buildColunas($referencia, $janela);
            $chavesColunas = collect($colunas)->pluck('chave')->all();
            $chaveReferencia = $this->periodoChave((int) $referencia->month, (int) $referencia->year);

            $incluirAbertos = $this->parseBool($atributes->incluir_abertos ?? true, true);
            $somenteAbertos = $this->parseBool($atributes->somente_abertos ?? false, false);
            $cartaoId = !empty($atributes->cartao_id) ? (int) $atributes->cartao_id : null;

            $parcelas = $this->loadParcelasResponsavel($userId, $responsavelId, $cartaoId);
            $transacaoIds = $parcelas->pluck('id')->map(fn ($id) => (int) $id)->all();
            $pagosPorTransacao = $this->loadPagosPorTransacao($userId, $transacaoIds);

            $compras = $this->buildComprasMatriz($parcelas, $pagosPorTransacao, $chavesColunas);

            $compras = $compras->filter(function (array $compra) use ($chavesColunas, $incluirAbertos, $somenteAbertos) {
                $temCelulaNaJanela = count(array_intersect(array_keys($compra['celulas']), $chavesColunas)) > 0;
                $temAberto = $compra['valor_aberto'] > self::TOLERANCIA;

                if ($somenteAbertos && !$temAberto) {
                    return false;
                }

                if ($temCelulaNaJanela) {
                    return true;
                }

                return $incluirAbertos && $temAberto;
            })->values();

            $resumo = $this->buildResumoMatriz($compras, $chaveReferencia);

            return (object) [
                'data' => [
                    'responsavel_id' => $responsavelId,
                    'responsavel_nome' => $responsavel->nome,
                    'responsavel_tipo' => $responsavel->tipo,
                    'referencia' => [
                        'mes' => (int) $referencia->month,
                        'ano' => (int) $referencia->year,
                    ],
                    'colunas' => $colunas,
                    'resumo' => $resumo,
                    'compras' => $compras->all(),
                ],
                'status' => true,
                'message' => 'Matriz de repasses carregada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Status calculado de uma parcela.
     *
     * @return array{valor_devido: float, valor_pago: float, valor_aberto: float, status_repasse: string}
     */
    public function computeStatusParcela(float $valorDevido, float $valorPago): array
    {
        $devido = $this->roundMoney($valorDevido);
        $pago = $this->roundMoney($valorPago);
        $aberto = $this->roundMoney(max($devido - $pago, 0));

        return [
            'valor_devido' => $devido,
            'valor_pago' => $pago,
            'valor_aberto' => $aberto,
            'status_repasse' => $this->resolveStatusRepasse($devido, $pago),
        ];
    }

    public function resolveStatusRepasse(float $valorDevido, float $valorPago): string
    {
        $devido = $this->roundMoney($valorDevido);
        $pago = $this->roundMoney($valorPago);

        if ($pago <= self::TOLERANCIA) {
            return Repasse::STATUS_PENDENTE;
        }

        if ($pago + self::TOLERANCIA >= $devido) {
            return Repasse::STATUS_PAGO;
        }

        return Repasse::STATUS_PARCIAL;
    }

    public function chaveCompra(?string $compraGrupoId, int $transacaoId): string
    {
        if (!empty($compraGrupoId)) {
            return (string) $compraGrupoId;
        }

        return 't:' . $transacaoId;
    }

    /**
     * @return array<int, array{mes: int, ano: int, chave: string, label: string, referencia: bool}>
     */
    public function buildColunas(Carbon $referencia, int $janela = 13): array
    {
        $inicio = $referencia->copy()->subMonth();
        $colunas = [];

        for ($i = 0; $i < $janela; $i++) {
            $mes = (int) $inicio->month;
            $ano = (int) $inicio->year;
            $colunas[] = [
                'mes' => $mes,
                'ano' => $ano,
                'chave' => $this->periodoChave($mes, $ano),
                'label' => (self::MESES_LABEL[$mes] ?? (string) $mes) . '/' . $ano,
                'referencia' => $mes === (int) $referencia->month && $ano === (int) $referencia->year,
            ];
            $inicio->addMonth();
        }

        return $colunas;
    }

    public function periodoChave(int $mes, int $ano): string
    {
        return sprintf('%04d-%02d', $ano, $mes);
    }

    /**
     * Soft-delete repasses das transações informadas (cascata lógica).
     *
     * @param array<int> $transacaoIds
     */
    public function softDeleteByTransacaoIds(array $transacaoIds, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $transacaoIds)));
        if ($ids === []) {
            return 0;
        }

        return Repasse::where('user_id', $userId)
            ->whereIn('transacao_id', $ids)
            ->delete();
    }

    private function loadParcelasResponsavel(int $userId, int $responsavelId, ?int $cartaoId): Collection
    {
        $query = DB::table('transacoes as t')
            ->join('faturas as f', function ($join) {
                $join->on('f.id', '=', 't.fatura_id')->whereNull('f.deleted_at');
            })
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'f.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_numeros as cn', function ($join) {
                $join->on('cn.id', '=', 't.cartao_numero_id')->whereNull('cn.deleted_at');
            })
            ->leftJoin('estabelecimentos as est', function ($join) {
                $join->on('est.id', '=', 't.estabelecimento_id')->whereNull('est.deleted_at');
            })
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->where('t.responsavel_id', $responsavelId)
            ->where('t.tipo', Transacao::TIPO_PURCHASE)
            ->select(
                't.id',
                't.fatura_id',
                't.data',
                't.valor',
                't.parcelas_total',
                't.parcela_atual',
                't.compra_grupo_id',
                't.observacoes',
                't.estabelecimento_id',
                'est.nome as estabelecimento',
                'f.mes as fatura_mes',
                'f.ano as fatura_ano',
                'f.cartao_id',
                'c.nome as cartao_nome',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'cn.ultimos_digitos',
            )
            ->orderBy('t.data')
            ->orderBy('t.parcela_atual')
            ->orderBy('t.id');

        if ($cartaoId !== null) {
            $query->where('f.cartao_id', $cartaoId);
        }

        return $query->get();
    }

    /**
     * @param array<int> $transacaoIds
     * @return array<int, array{valor_pago: float, data_ultimo: ?string, qtd: int}>
     */
    private function loadPagosPorTransacao(int $userId, array $transacaoIds): array
    {
        if ($transacaoIds === []) {
            return [];
        }

        $rows = DB::table('repasses')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereIn('transacao_id', $transacaoIds)
            ->groupBy('transacao_id')
            ->select(
                'transacao_id',
                DB::raw('SUM(valor) as valor_pago'),
                DB::raw('MAX(data_pagamento) as data_ultimo'),
                DB::raw('COUNT(*) as qtd')
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->transacao_id] = [
                'valor_pago' => $this->roundMoney((float) $row->valor_pago),
                'data_ultimo' => $row->data_ultimo,
                'qtd' => (int) $row->qtd,
            ];
        }

        return $map;
    }

    /**
     * @param array<int, array{valor_pago: float, data_ultimo: ?string, qtd: int}> $pagosPorTransacao
     * @param array<int, string> $chavesColunas
     */
    private function buildComprasMatriz(Collection $parcelas, array $pagosPorTransacao, array $chavesColunas): Collection
    {
        $grupos = $parcelas->groupBy(function ($p) {
            return $this->chaveCompra($p->compra_grupo_id ?? null, (int) $p->id);
        });

        return $grupos->map(function (Collection $grupo, string $chave) use ($pagosPorTransacao, $chavesColunas) {
            $primeira = $grupo->sortBy(fn ($p) => [(int) ($p->parcela_atual ?? 1), (int) $p->id])->first();
            $celulas = [];
            $valorTotal = 0.0;
            $valorPagoTotal = 0.0;
            $parcelasPagas = 0;
            $parcelasParciais = 0;
            $parcelasPendentes = 0;

            foreach ($grupo as $parcela) {
                $tid = (int) $parcela->id;
                $pagoInfo = $pagosPorTransacao[$tid] ?? ['valor_pago' => 0.0, 'data_ultimo' => null, 'qtd' => 0];
                $status = $this->computeStatusParcela((float) $parcela->valor, (float) $pagoInfo['valor_pago']);

                $valorTotal += $status['valor_devido'];
                $valorPagoTotal += $status['valor_pago'];

                if ($status['status_repasse'] === Repasse::STATUS_PAGO) {
                    $parcelasPagas++;
                } elseif ($status['status_repasse'] === Repasse::STATUS_PARCIAL) {
                    $parcelasParciais++;
                } else {
                    $parcelasPendentes++;
                }

                $chaveMes = $this->periodoChave((int) $parcela->fatura_mes, (int) $parcela->fatura_ano);
                if (!in_array($chaveMes, $chavesColunas, true)) {
                    continue;
                }

                $celulas[$chaveMes] = [
                    'transacao_id' => $tid,
                    'fatura_id' => (int) $parcela->fatura_id,
                    'parcela_atual' => $parcela->parcela_atual !== null ? (int) $parcela->parcela_atual : null,
                    'parcelas_total' => $parcela->parcelas_total !== null ? (int) $parcela->parcelas_total : null,
                    'valor_devido' => $status['valor_devido'],
                    'valor_pago' => $status['valor_pago'],
                    'valor_aberto' => $status['valor_aberto'],
                    'status_repasse' => $status['status_repasse'],
                    'data_ultimo_pagamento' => $pagoInfo['data_ultimo'],
                    'qtd_repasses' => $pagoInfo['qtd'],
                ];
            }

            $valorTotal = $this->roundMoney($valorTotal);
            $valorPagoTotal = $this->roundMoney($valorPagoTotal);
            $valorAberto = $this->roundMoney(max($valorTotal - $valorPagoTotal, 0));

            $compraGrupoId = $primeira->compra_grupo_id ?? null;
            $parcelasTotalMeta = $primeira->parcelas_total !== null
                ? (int) $primeira->parcelas_total
                : $grupo->count();

            return [
                'chave_compra' => $chave,
                'compra_grupo_id' => $compraGrupoId,
                'transacao_id_avista' => empty($compraGrupoId) ? (int) $primeira->id : null,
                'estabelecimento' => $primeira->estabelecimento,
                'observacoes' => $primeira->observacoes,
                'data_compra' => $primeira->data,
                'cartao_id' => $primeira->cartao_id !== null ? (int) $primeira->cartao_id : null,
                'cartao_nome' => $primeira->cartao_nome,
                'cartao_cor_fundo' => $primeira->cartao_cor_fundo,
                'cartao_cor_texto' => $primeira->cartao_cor_texto,
                'ultimos_digitos' => $primeira->ultimos_digitos,
                'parcelas_total' => $parcelasTotalMeta,
                'valor_total' => $valorTotal,
                'valor_pago' => $valorPagoTotal,
                'valor_aberto' => $valorAberto,
                'parcelas_pagas' => $parcelasPagas,
                'parcelas_parciais' => $parcelasParciais,
                'parcelas_pendentes' => $parcelasPendentes,
                'status_repasse' => $this->resolveStatusCompra($valorAberto, $valorPagoTotal),
                'celulas' => $celulas,
            ];
        })->sortBy(function (array $compra) {
            return [
                $compra['status_repasse'] === Repasse::STATUS_PAGO ? 1 : 0,
                $compra['estabelecimento'] ?? '',
                $compra['data_compra'] ?? '',
            ];
        })->values();
    }

    private function resolveStatusCompra(float $valorAberto, float $valorPago): string
    {
        if ($valorAberto <= self::TOLERANCIA) {
            return Repasse::STATUS_PAGO;
        }

        if ($valorPago > self::TOLERANCIA) {
            return Repasse::STATUS_PARCIAL;
        }

        return Repasse::STATUS_PENDENTE;
    }

    /**
     * @param Collection<int, array> $compras
     */
    private function buildResumoMatriz(Collection $compras, string $chaveReferencia): array
    {
        $valorTotal = 0.0;
        $valorPago = 0.0;
        $valorAberto = 0.0;
        $comprasAbertas = 0;
        $comprasPagas = 0;
        $parcelasPendentesRef = 0;
        $valorAbertoRef = 0.0;

        foreach ($compras as $compra) {
            $valorTotal += $compra['valor_total'];
            $valorPago += $compra['valor_pago'];
            $valorAberto += $compra['valor_aberto'];

            if ($compra['valor_aberto'] > self::TOLERANCIA) {
                $comprasAbertas++;
            } else {
                $comprasPagas++;
            }

            $celula = $compra['celulas'][$chaveReferencia] ?? null;
            if ($celula !== null && $celula['valor_aberto'] > self::TOLERANCIA) {
                $parcelasPendentesRef++;
                $valorAbertoRef += $celula['valor_aberto'];
            }
        }

        return [
            'valor_total_compras' => $this->roundMoney($valorTotal),
            'valor_pago' => $this->roundMoney($valorPago),
            'valor_aberto' => $this->roundMoney($valorAberto),
            'compras_abertas' => $comprasAbertas,
            'compras_pagas' => $comprasPagas,
            'parcelas_pendentes_na_referencia' => $parcelasPendentesRef,
            'valor_aberto_na_referencia' => $this->roundMoney($valorAbertoRef),
        ];
    }

    private function resolveTransacaoPurchase(mixed $transacaoId, int $userId): Transacao
    {
        if (empty($transacaoId)) {
            throw new Exception('transacao_id é obrigatório', 422);
        }

        $transacao = Transacao::where('id', $transacaoId)
            ->where('user_id', $userId)
            ->first();

        if (!$transacao) {
            throw new Exception('Transação não encontrada', 404);
        }

        if ($transacao->tipo !== Transacao::TIPO_PURCHASE) {
            throw new Exception('Só é possível registrar repasse em compras (tipo purchase)', 422);
        }

        return $transacao;
    }

    private function sumValorPago(int $transacaoId, int $userId, ?int $excetoRepasseId = null): float
    {
        $query = Repasse::where('user_id', $userId)
            ->where('transacao_id', $transacaoId);

        if ($excetoRepasseId !== null) {
            $query->where('id', '!=', $excetoRepasseId);
        }

        return $this->roundMoney((float) $query->sum('valor'));
    }

    private function assertResponsavelDoUsuario(int $responsavelId, int $userId): void
    {
        $exists = Responsavel::where('id', $responsavelId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Responsável não encontrado', 404);
        }
    }

    private function parseDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Exception $e) {
            throw new Exception('data_pagamento inválida', 422);
        }
    }

    /**
     * Aceita "125,50", "1.234,56", "125.50" ou número.
     */
    public function parseValor(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return $this->roundMoney((float) $value);
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

        return $this->roundMoney((float) $raw);
    }

    private function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
