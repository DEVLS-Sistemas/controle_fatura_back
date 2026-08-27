<?php

namespace App\Services\Transacao;

use App\Models\CompraHistorico;
use App\Models\Estabelecimento;
use App\Models\Transacao;
use App\Services\Fatura\FaturaService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ConciliacaoService
{
    private ConciliacaoMatcher $matcher;
    private CompraHistoricoService $historicoService;
    private FaturaService $faturaService;

    public function __construct(
        ?ConciliacaoMatcher $matcher = null,
        ?CompraHistoricoService $historicoService = null,
        ?FaturaService $faturaService = null
    ) {
        $this->matcher = $matcher ?? new ConciliacaoMatcher();
        $this->historicoService = $historicoService ?? new CompraHistoricoService();
        $this->faturaService = $faturaService ?? new FaturaService();
    }

    public function handleLookups(): array
    {
        return [
            'status_conciliacao' => array_map(
                fn (string $value) => [
                    'value' => $value,
                    'label' => Transacao::CONCILIACAO_LABELS[$value],
                ],
                Transacao::CONCILIACAO_STATUS
            ),
        ];
    }

    public function handleListarCandidatos(string $identificador): object
    {
        $userId = (int) Auth::id();
        $registro = $this->resolverCompra($userId, $identificador);

        $data = $registro->importada_pdf
            ? $this->listarManuaisCandidatos($registro)
            : $this->listarCandidatos($registro);

        return (object) [
            'data' => $data,
            'status' => true,
            'message' => 'Candidatos carregados com sucesso!',
        ];
    }

    public function handleConciliar(object $atributes): object
    {
        $userId = (int) Auth::id();
        $compraId = $atributes->compra_id ?? $atributes->transacao_id ?? $atributes->id ?? null;
        $lancamentoId = $atributes->lancamento_id ?? null;

        if (empty($compraId) || empty($lancamentoId)) {
            throw new Exception('Informe a compra e o lançamento da fatura', 422);
        }

        $esquerda = $this->resolverCompra($userId, (string) $compraId);
        $direita = Transacao::where('id', $lancamentoId)
            ->where('user_id', $userId)
            ->first();

        if (!$direita) {
            throw new Exception('Lançamento da fatura não encontrado', 404);
        }

        [$compra, $lancamento] = $this->orientarCompraELancamento($esquerda, $direita);
        $this->conciliar($compra, $lancamento);

        return (object) [
            'data' => $this->payloadCompra($compra->fresh()),
            'status' => true,
            'message' => 'Compra conciliada com o lançamento da fatura!',
        ];
    }

    public function handleDesvincular(object $atributes): object
    {
        $userId = (int) Auth::id();
        $identificador = (string) ($atributes->compra_id ?? $atributes->transacao_id ?? $atributes->id ?? $atributes->lancamento_id ?? '');
        if ($identificador === '') {
            throw new Exception('Informe a compra', 422);
        }

        $compra = $this->resolverCompraOuVinculo($userId, $identificador);
        $this->desvincular($compra);

        return (object) [
            'data' => $this->payloadCompra($compra->fresh()),
            'status' => true,
            'message' => 'Lançamento desvinculado da compra!',
        ];
    }

    public function handleRejeitar(object $atributes): object
    {
        $userId = (int) Auth::id();
        $identificador = (string) ($atributes->compra_id ?? $atributes->transacao_id ?? $atributes->id ?? $atributes->lancamento_id ?? '');
        if ($identificador === '') {
            throw new Exception('Informe a compra', 422);
        }

        $compra = $this->resolverCompraOuVinculo($userId, $identificador);
        $this->rejeitar($compra);

        return (object) [
            'data' => $this->payloadCompra($compra->fresh()),
            'status' => true,
            'message' => 'Sugestão de conciliação rejeitada!',
        ];
    }

    public function conciliar(Transacao $compra, Transacao $lancamento): void
    {
        $this->assertPodeConciliar($compra, $lancamento);

        $descricaoFatura = $this->nomeEstabelecimento($lancamento);

        $compra->status_conciliacao = Transacao::CONCILIACAO_CONCILIADA;
        $compra->lancamento_id = (int) $lancamento->id;
        $compra->descricao_fatura = $descricaoFatura;
        $compra->ignorar_no_total = true;
        $compra->save();

        $this->copiarDadosDaCompraParaLancamento($compra, $lancamento);
        $lancamento->ignorar_no_total = false;
        $lancamento->save();

        $this->historicoService->registrar(
            $compra,
            CompraHistorico::ACAO_CONCILIADA,
            'Conciliada com o lançamento "' . $descricaoFatura . '"',
            [
                'lancamento_id' => (int) $lancamento->id,
                'descricao_fatura' => $descricaoFatura,
                'descricao_compra' => $compra->descricao ?: $compra->observacoes,
            ]
        );

        $this->faturaService->recalculateValorTotalMany([
            (int) $compra->fatura_id,
            (int) $lancamento->fatura_id,
        ]);
    }

    public function desvincular(Transacao $compra): void
    {
        if ($compra->status_conciliacao !== Transacao::CONCILIACAO_CONCILIADA
            && $compra->status_conciliacao !== Transacao::CONCILIACAO_PENDENTE
        ) {
            throw new Exception('Esta compra não possui lançamento vinculado', 422);
        }

        $lancamentoId = $compra->lancamento_id ? (int) $compra->lancamento_id : null;
        $lancamento = $lancamentoId
            ? Transacao::where('id', $lancamentoId)->where('user_id', $compra->user_id)->first()
            : null;

        $compra->status_conciliacao = Transacao::CONCILIACAO_NAO_CONCILIADA;
        $compra->lancamento_id = null;
        $compra->ignorar_no_total = false;
        $compra->save();

        if ($lancamento) {
            $lancamento->ignorar_no_total = false;
            $lancamento->save();
        }

        $this->historicoService->registrar(
            $compra,
            CompraHistorico::ACAO_DESVINCULADA,
            'Lançamento da fatura desvinculado'
        );

        $faturaIds = [(int) $compra->fatura_id];
        if ($lancamento) {
            $faturaIds[] = (int) $lancamento->fatura_id;
        }
        $this->faturaService->recalculateValorTotalMany($faturaIds);
    }

    public function rejeitar(Transacao $compra): void
    {
        $lancamentoId = $compra->lancamento_id ? (int) $compra->lancamento_id : null;
        $lancamento = $lancamentoId
            ? Transacao::where('id', $lancamentoId)->where('user_id', $compra->user_id)->first()
            : null;

        $compra->status_conciliacao = Transacao::CONCILIACAO_REJEITADA;
        $compra->lancamento_id = null;
        $compra->ignorar_no_total = false;
        $compra->save();

        if ($lancamento) {
            $lancamento->ignorar_no_total = false;
            $lancamento->save();
        }

        $this->historicoService->registrar(
            $compra,
            CompraHistorico::ACAO_REJEITADA,
            'Sugestão de conciliação rejeitada'
        );

        $faturaIds = [(int) $compra->fatura_id];
        if ($lancamento) {
            $faturaIds[] = (int) $lancamento->fatura_id;
        }
        $this->faturaService->recalculateValorTotalMany($faturaIds);
    }

    /**
     * Import PDF: se o match exato for uma compra manual, concilia sem duplicar descrição.
     */
    public function conciliarMatchExato(Transacao $compra, string $descricaoFatura): void
    {
        if ($compra->status_conciliacao === null) {
            return;
        }

        $compra->descricao_fatura = $descricaoFatura !== '' ? $descricaoFatura : $compra->descricao_fatura;
        $compra->status_conciliacao = Transacao::CONCILIACAO_CONCILIADA;
        $compra->importada_pdf = true;
        $compra->ignorar_no_total = false;
        $compra->save();

        $this->historicoService->registrar(
            $compra,
            CompraHistorico::ACAO_CONCILIADA,
            'Conciliada automaticamente com o lançamento da fatura "' . $descricaoFatura . '"'
        );
    }

    /**
     * Import PDF: após criar os lançamentos, sugere vínculo 1:1 com compras manuais.
     * O lançamento permanece visível na fatura (como sugestão), mas não entra no total até o usuário confirmar.
     */
    public function sugerirParaFatura(int $userId, int $faturaId): void
    {
        $this->limparVinculosPendentesOrfaos($userId, $faturaId);

        $ocupados = Transacao::where('user_id', $userId)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->whereNotNull('lancamento_id')
            ->pluck('lancamento_id')
            ->all();
        $ocupados = array_map('intval', $ocupados);

        $manuais = Transacao::where('user_id', $userId)
            ->where('fatura_id', $faturaId)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where('importada_pdf', false)
            ->where('status_conciliacao', Transacao::CONCILIACAO_NAO_CONCILIADA)
            ->whereNull('lancamento_id')
            ->get()
            ->values();

        $lancamentos = Transacao::with('estabelecimento')
            ->where('user_id', $userId)
            ->where('fatura_id', $faturaId)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where('importada_pdf', true)
            ->whereNotIn('id', $ocupados !== [] ? $ocupados : [0])
            ->get()
            ->values();

        if ($manuais->isEmpty() || $lancamentos->isEmpty()) {
            return;
        }

        $pares = $this->matcher->parearUnico(
            $manuais->map(fn (Transacao $c) => $this->toMatcherPayload($c))->all(),
            $lancamentos->map(fn (Transacao $l) => $this->toMatcherPayload($l))->all()
        );

        foreach ($pares as $par) {
            $compra = $manuais[$par['compra']] ?? null;
            $lancamento = $lancamentos[$par['lancamento']] ?? null;
            if (!$compra || !$lancamento) {
                continue;
            }
            $this->marcarPendente($compra, $lancamento);
        }
    }

    /**
     * Import PDF: após criar o lançamento, sugere vínculo com compra manual (sem mesclar).
     */
    public function sugerirParaLancamento(Transacao $lancamento): void
    {
        if ($lancamento->tipo !== Transacao::TIPO_PURCHASE) {
            return;
        }

        $this->sugerirParaFatura((int) $lancamento->user_id, (int) $lancamento->fatura_id);
    }

    public function aoExcluirCompra(Transacao $compra): void
    {
        if (!$compra->lancamento_id) {
            return;
        }

        $lancamento = Transacao::where('id', $compra->lancamento_id)
            ->where('user_id', $compra->user_id)
            ->first();

        if ($lancamento && $lancamento->ignorar_no_total) {
            $lancamento->ignorar_no_total = false;
            $lancamento->save();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarCandidatos(Transacao $compra): array
    {
        $ocupados = Transacao::where('user_id', $compra->user_id)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->whereNotNull('lancamento_id')
            ->where('id', '!=', $compra->id)
            ->pluck('lancamento_id')
            ->all();

        $lancamentos = Transacao::with('estabelecimento')
            ->where('user_id', $compra->user_id)
            ->where('fatura_id', $compra->fatura_id)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where('importada_pdf', true)
            ->where('id', '!=', $compra->id)
            ->where(function ($q) use ($compra) {
                $q->where('ignorar_no_total', false);
                if ($compra->lancamento_id) {
                    $q->orWhere('id', (int) $compra->lancamento_id);
                }
            })
            ->whereNotIn('id', $ocupados !== [] ? $ocupados : [0])
            ->get();

        $itens = [];
        foreach ($lancamentos as $lancamento) {
            $score = $this->matcher->score(
                $this->toMatcherPayload($compra),
                $this->toMatcherPayload($lancamento)
            );
            $itens[] = [
                'id' => (int) $lancamento->id,
                'estabelecimento' => $this->nomeEstabelecimento($lancamento),
                'valor' => round((float) $lancamento->valor, 2),
                'data' => $lancamento->data?->toDateString(),
                'parcela_atual' => $lancamento->parcela_atual !== null ? (int) $lancamento->parcela_atual : null,
                'parcelas_total' => $lancamento->parcelas_total !== null ? (int) $lancamento->parcelas_total : null,
                'importada_pdf' => (bool) $lancamento->importada_pdf,
                'score' => $score,
                'sugestao' => $this->matcher->isSugestao($score),
            ];
        }

        usort($itens, function (array $a, array $b) {
            return ($b['score'] <=> $a['score']) ?: strcmp((string) $a['estabelecimento'], (string) $b['estabelecimento']);
        });

        return $itens;
    }

    /**
     * @return array<string, mixed>
     */
    public function blocoVisualizacao(Transacao $compra): array
    {
        $status = $compra->status_conciliacao;
        $lancamento = null;
        if ($compra->lancamento_id) {
            $lancamentoModel = Transacao::with('estabelecimento')->find($compra->lancamento_id);
            if ($lancamentoModel) {
                $lancamento = [
                    'id' => (int) $lancamentoModel->id,
                    'estabelecimento' => $this->nomeEstabelecimento($lancamentoModel),
                    'valor' => round((float) $lancamentoModel->valor, 2),
                    'data' => $lancamentoModel->data?->toDateString(),
                    'importada_pdf' => (bool) $lancamentoModel->importada_pdf,
                ];
            }
        }

        return [
            'status' => $status,
            'status_label' => $status !== null
                ? (Transacao::CONCILIACAO_LABELS[$status] ?? $status)
                : null,
            'mensagem' => $this->mensagem($status),
            'descricao_compra' => $compra->descricao ?: $compra->observacoes,
            'descricao_fatura' => $compra->descricao_fatura,
            'compra_id' => (int) $compra->id,
            'lancamento_id' => $compra->lancamento_id !== null ? (int) $compra->lancamento_id : null,
            'lancamento' => $lancamento,
        ];
    }

    public function mensagem(?string $status): ?string
    {
        return match ($status) {
            Transacao::CONCILIACAO_NAO_CONCILIADA => 'O lançamento real desta compra ainda não foi localizado na fatura.',
            Transacao::CONCILIACAO_PENDENTE => 'Encontramos um lançamento na fatura que pode corresponder a esta compra.',
            Transacao::CONCILIACAO_CONCILIADA => 'Compra vinculada ao lançamento da fatura. A descrição amigável foi preservada.',
            Transacao::CONCILIACAO_REJEITADA => 'A sugestão de conciliação foi rejeitada. Compra e lançamento permanecem independentes.',
            default => null,
        };
    }

    private function assertPodeConciliar(Transacao $compra, Transacao $lancamento): void
    {
        if ((int) $compra->user_id !== (int) $lancamento->user_id) {
            throw new Exception('Lançamento da fatura não encontrado', 404);
        }
        if ($compra->tipo !== Transacao::TIPO_PURCHASE || $lancamento->tipo !== Transacao::TIPO_PURCHASE) {
            throw new Exception('Só é possível conciliar compras', 422);
        }
        if ((int) $compra->id === (int) $lancamento->id) {
            throw new Exception('Compra e lançamento devem ser registros diferentes', 422);
        }
        if ((int) $compra->fatura_id !== (int) $lancamento->fatura_id) {
            throw new Exception('A conciliação deve ser feita com um lançamento da mesma fatura', 422);
        }

        $jaUsado = Transacao::where('user_id', $compra->user_id)
            ->where('lancamento_id', $lancamento->id)
            ->where('id', '!=', $compra->id)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->exists();

        if ($jaUsado) {
            throw new Exception('Este lançamento já está vinculado a outra compra', 422);
        }
    }

    private function resolverCompra(int $userId, string $identificador): Transacao
    {
        $identificador = trim($identificador);
        $query = Transacao::where('user_id', $userId)->where('tipo', Transacao::TIPO_PURCHASE);

        if (Str::isUuid($identificador)) {
            $record = (clone $query)->where('compra_grupo_id', $identificador)
                ->orderBy('parcela_atual')
                ->first();
        } elseif (ctype_digit($identificador)) {
            $record = (clone $query)->where('id', (int) $identificador)->first();
        } else {
            $record = null;
        }

        if (!$record) {
            throw new Exception('Compra não encontrada', 404);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadCompra(?Transacao $compra): array
    {
        if (!$compra) {
            return [];
        }

        return [
            'id' => (int) $compra->id,
            'descricao' => $compra->descricao,
            'descricao_fatura' => $compra->descricao_fatura,
            'observacoes' => $compra->observacoes,
            'status_conciliacao' => $compra->status_conciliacao,
            'status_conciliacao_label' => $compra->status_conciliacao !== null
                ? (Transacao::CONCILIACAO_LABELS[$compra->status_conciliacao] ?? $compra->status_conciliacao)
                : null,
            'lancamento_id' => $compra->lancamento_id !== null ? (int) $compra->lancamento_id : null,
            'ignorar_no_total' => (bool) $compra->ignorar_no_total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toMatcherPayload(Transacao $transacao): array
    {
        return [
            'valor' => (float) $transacao->valor,
            'data' => $transacao->data?->toDateString(),
            'fatura_id' => (int) $transacao->fatura_id,
            'cartao_numero_id' => $transacao->cartao_numero_id !== null ? (int) $transacao->cartao_numero_id : null,
            'parcela_atual' => $transacao->parcela_atual !== null ? (int) $transacao->parcela_atual : 1,
            'parcelas_total' => $transacao->parcelas_total !== null ? (int) $transacao->parcelas_total : 1,
            'descricao' => $transacao->descricao,
            'estabelecimento' => $this->nomeEstabelecimento($transacao),
            'descricao_fatura' => $transacao->descricao_fatura,
        ];
    }

    private function nomeEstabelecimento(Transacao $transacao): string
    {
        if ($transacao->relationLoaded('estabelecimento') && $transacao->estabelecimento) {
            return (string) $transacao->estabelecimento->nome;
        }

        if ($transacao->estabelecimento_id) {
            $nome = Estabelecimento::where('id', $transacao->estabelecimento_id)->value('nome');
            if ($nome) {
                return (string) $nome;
            }
        }

        return (string) ($transacao->descricao_fatura ?: $transacao->descricao ?: $transacao->observacoes ?: '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarManuaisCandidatos(Transacao $lancamento): array
    {
        $ocupadas = Transacao::where('user_id', $lancamento->user_id)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->whereNotNull('lancamento_id')
            ->where('lancamento_id', '!=', $lancamento->id)
            ->pluck('id')
            ->all();

        $manuais = Transacao::where('user_id', $lancamento->user_id)
            ->where('fatura_id', $lancamento->fatura_id)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where('importada_pdf', false)
            ->where('id', '!=', $lancamento->id)
            ->where(function ($q) use ($lancamento) {
                $q->where(function ($aberta) {
                    $aberta->where('status_conciliacao', Transacao::CONCILIACAO_NAO_CONCILIADA)
                        ->whereNull('lancamento_id');
                })->orWhere(function ($desta) use ($lancamento) {
                    $desta->where('lancamento_id', $lancamento->id)
                        ->whereIn('status_conciliacao', [
                            Transacao::CONCILIACAO_PENDENTE,
                            Transacao::CONCILIACAO_CONCILIADA,
                        ]);
                });
            })
            ->whereNotIn('id', $ocupadas !== [] ? $ocupadas : [0])
            ->get();

        $itens = [];
        foreach ($manuais as $compra) {
            $score = $this->matcher->score(
                $this->toMatcherPayload($compra),
                $this->toMatcherPayload($lancamento)
            );
            $itens[] = array_merge($this->payloadVinculo($compra), [
                'score' => $score,
                'sugestao' => $this->matcher->isSugestao($score),
            ]);
        }

        usort($itens, function (array $a, array $b) {
            return ($b['score'] <=> $a['score'])
                ?: strcmp((string) ($a['texto_compra'] ?? ''), (string) ($b['texto_compra'] ?? ''));
        });

        return $itens;
    }

    public function localizarVinculoDoLancamento(Transacao $lancamento): ?Transacao
    {
        return Transacao::where('user_id', $lancamento->user_id)
            ->where('lancamento_id', $lancamento->id)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->orderByRaw("CASE WHEN status_conciliacao = ? THEN 0 ELSE 1 END", [
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadVinculo(Transacao $compra): array
    {
        $texto = Transacao::textoCompraFromRow([
            'observacoes' => $compra->observacoes,
            'descricao' => $compra->descricao,
        ]);
        $status = $compra->status_conciliacao;

        return [
            'id' => (int) $compra->id,
            'compra_grupo_id' => $compra->compra_grupo_id ?: null,
            'texto_compra' => $texto,
            'observacoes' => $compra->observacoes,
            'descricao' => $compra->descricao,
            'valor' => round((float) $compra->valor, 2),
            'data' => $compra->data?->toDateString(),
            'status_conciliacao' => $status,
            'status_conciliacao_label' => $status !== null
                ? (Transacao::CONCILIACAO_LABELS[$status] ?? $status)
                : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function anexarNasLinhas(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $rows;
        }

        $vinculos = Transacao::whereIn('lancamento_id', $ids)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_PENDENTE,
                Transacao::CONCILIACAO_CONCILIADA,
            ])
            ->get();

        $porLancamento = [];
        foreach ($vinculos as $compra) {
            $porLancamento[(int) $compra->lancamento_id] = $compra;
        }

        return array_map(function (array $row) use ($porLancamento) {
            $id = (int) ($row['id'] ?? 0);
            $compra = $porLancamento[$id] ?? null;
            $row['compra_manual_vinculada'] = $compra ? $this->payloadVinculo($compra) : null;
            $row['conciliada_com_manual'] = $compra
                && $compra->status_conciliacao === Transacao::CONCILIACAO_CONCILIADA;
            $row['tem_sugestao_conciliacao'] = $compra
                && $compra->status_conciliacao === Transacao::CONCILIACAO_PENDENTE;
            $row['conciliada_com_manual_label'] = $row['conciliada_com_manual']
                ? Transacao::CONCILIADA_COM_MANUAL_LABEL
                : null;
            $textoManual = $compra
                ? (Transacao::textoCompraFromRow([
                    'observacoes' => $compra->observacoes,
                    'descricao' => $compra->descricao,
                ]) ?: 'esta compra')
                : null;
            $row['sugestao_conciliacao_label'] = $row['tem_sugestao_conciliacao']
                ? Transacao::SUGESTAO_CONCILIACAO_LABEL . ' «' . $textoManual . '»'
                : null;
            $row['conta_no_total'] = empty($row['ignorar_no_total']);

            return $row;
        }, $rows);
    }

    /**
     * @return array{0: Transacao, 1: Transacao}
     */
    private function orientarCompraELancamento(Transacao $esquerda, Transacao $direita): array
    {
        $esquerdaManual = ! $esquerda->importada_pdf;
        $direitaManual = ! $direita->importada_pdf;

        if ($esquerdaManual === $direitaManual) {
            throw new Exception('Informe a compra manual e o lançamento importado da fatura', 422);
        }

        if ($esquerdaManual && ! $direitaManual) {
            return [$esquerda, $direita];
        }

        return [$direita, $esquerda];
    }

    private function resolverCompraOuVinculo(int $userId, string $identificador): Transacao
    {
        $record = $this->resolverCompra($userId, $identificador);
        if ($record->importada_pdf) {
            $vinculo = $this->localizarVinculoDoLancamento($record);
            if ($vinculo) {
                return $vinculo;
            }
        }

        return $record;
    }

    private function marcarPendente(Transacao $compra, Transacao $lancamento): void
    {
        $descricaoFatura = $this->nomeEstabelecimento($lancamento);

        $compra->status_conciliacao = Transacao::CONCILIACAO_PENDENTE;
        $compra->lancamento_id = (int) $lancamento->id;
        $compra->descricao_fatura = $descricaoFatura;
        $compra->save();

        $lancamento->ignorar_no_total = true;
        $lancamento->save();

        $this->historicoService->registrar(
            $compra,
            CompraHistorico::ACAO_PENDENTE,
            'Sugestão de conciliação com o lançamento "' . $descricaoFatura . '"'
        );
    }

    private function limparVinculosPendentesOrfaos(int $userId, int $faturaId): void
    {
        $pendentes = Transacao::where('user_id', $userId)
            ->where('fatura_id', $faturaId)
            ->where('status_conciliacao', Transacao::CONCILIACAO_PENDENTE)
            ->whereNotNull('lancamento_id')
            ->get();

        foreach ($pendentes as $compra) {
            $existe = Transacao::where('id', $compra->lancamento_id)
                ->where('user_id', $userId)
                ->exists();
            if ($existe) {
                continue;
            }
            $compra->status_conciliacao = Transacao::CONCILIACAO_NAO_CONCILIADA;
            $compra->lancamento_id = null;
            $compra->descricao_fatura = null;
            $compra->save();
        }
    }

    private function copiarDadosDaCompraParaLancamento(Transacao $compra, Transacao $lancamento): void
    {
        $this->copiarObservacaoParaLancamento($compra, $lancamento);

        if (empty($lancamento->categoria_id) && $compra->categoria_id) {
            $lancamento->categoria_id = $compra->categoria_id;
            $lancamento->subcategoria_id = $compra->subcategoria_id;
        }
        if (empty($lancamento->origem_compra) && $compra->origem_compra) {
            $lancamento->origem_compra = $compra->origem_compra;
        }
        if ($compra->eh_assinatura) {
            $lancamento->eh_assinatura = true;
        }
        if ($compra->responsavel_id) {
            $lancamento->responsavel_id = $compra->responsavel_id;
        }
    }

    private function copiarObservacaoParaLancamento(Transacao $compra, Transacao $lancamento): void
    {
        $texto = trim((string) ($compra->observacoes ?: $compra->descricao ?: ''));
        if ($texto === '') {
            return;
        }

        $atual = trim((string) ($lancamento->observacoes ?? ''));
        if ($atual === '') {
            $lancamento->observacoes = $texto;
        }
    }
}
