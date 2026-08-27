<?php

namespace App\Services\Fatura;

use App\Models\Fatura;
use App\Models\Transacao;
use Exception;
use Illuminate\Support\Facades\Auth;

class FaturaAnexoReversaoService
{
    public const MOTIVO_REMOVER = 'remover';
    public const MOTIVO_TROCAR_PDF = 'trocar_pdf';

    public const ORIGEM_DESVINCULO = 'desvinculo';
    public const ORIGEM_MATCH_EXATO = 'match_exato';
    public const ORIGEM_SUGESTAO = 'sugestao';

    /**
     * Etapa 1: preview do que a remoção/troca do anexo desfaz. Não altera dados.
     */
    public function handlePreview(int|string $id): object
    {
        $plano = $this->montarPlano((int) $id);

        return (object) [
            'status' => true,
            'message' => 'Impacto da remoção do anexo',
            'data' => $this->payloadPreview($plano),
        ];
    }

    /**
     * @return array{
     *     fatura: Fatura,
     *     lancamentos: \Illuminate\Support\Collection<int, Transacao>,
     *     parcelasOutras: \Illuminate\Support\Collection<int, Transacao>,
     *     comprasRestaurar: list<array<string, mixed>>,
     *     faturasAfetadas: list<array<string, mixed>>,
     *     stubsExcluir: list<array{id: int, competencia: string}>
     * }
     */
    public function montarPlano(int $faturaId): array
    {
        $userId = (int) Auth::id();
        if ($userId < 1) {
            throw new Exception('Não autenticado', 401);
        }

        $fatura = Fatura::where('id', $faturaId)->where('user_id', $userId)->first();
        if (!$fatura) {
            throw new Exception('Fatura não encontrada', 404);
        }

        if (($fatura->status ?? '') === 'processando') {
            throw new Exception('A fatura está sendo processada. Aguarde para remover o anexo.', 422);
        }

        $temPdf = !empty($fatura->arquivo_pdf);
        $temCsv = !empty($fatura->arquivo_csv);
        if (!$temPdf && !$temCsv) {
            throw new Exception('Fatura não possui anexo para remover', 422);
        }

        $lancamentos = $this->lancamentosDesteAnexo($fatura, $userId);
        $parcelasOutras = $this->parcelasGeradasEmOutrasFaturas($fatura, $userId, $lancamentos);
        $comprasRestaurar = $this->comprasQueVoltamAConciliar($fatura, $userId, $lancamentos);

        $idsParcelasPorFatura = $parcelasOutras
            ->groupBy(fn (Transacao $t) => (int) $t->fatura_id)
            ->map(fn ($grupo) => $grupo->pluck('id')->map(fn ($id) => (int) $id)->all());

        $faturasAfetadas = [];
        $stubsExcluir = [];

        foreach ($idsParcelasPorFatura as $outraFaturaId => $transacaoIds) {
            $outra = Fatura::where('id', $outraFaturaId)->where('user_id', $userId)->first();
            if (!$outra) {
                continue;
            }

            $quantidade = count($transacaoIds);
            $valorTotal = round((float) $parcelasOutras
                ->where('fatura_id', $outraFaturaId)
                ->sum('valor'), 2);

            $restantes = Transacao::where('fatura_id', $outraFaturaId)
                ->where('user_id', $userId)
                ->whereNotIn('id', $transacaoIds !== [] ? $transacaoIds : [0])
                ->count();

            $ficaraVazia = $restantes === 0
                && empty($outra->arquivo_pdf)
                && empty($outra->arquivo_csv)
                && ($outra->status === 'pendente');

            $item = [
                'id' => (int) $outra->id,
                'competencia' => sprintf('%02d/%d', (int) $outra->mes, (int) $outra->ano),
                'quantidade' => $quantidade,
                'valor_total' => $valorTotal,
                'ficara_vazia' => $ficaraVazia,
            ];
            $faturasAfetadas[] = $item;
            if ($ficaraVazia) {
                $stubsExcluir[] = [
                    'id' => (int) $outra->id,
                    'competencia' => $item['competencia'],
                ];
            }
        }

        usort($faturasAfetadas, function (array $a, array $b) {
            return [$a['competencia']] <=> [$b['competencia']];
        });

        return [
            'fatura' => $fatura,
            'lancamentos' => $lancamentos,
            'parcelasOutras' => $parcelasOutras,
            'comprasRestaurar' => $comprasRestaurar,
            'faturasAfetadas' => $faturasAfetadas,
            'stubsExcluir' => $stubsExcluir,
        ];
    }

    /**
     * @param array<string, mixed> $plano
     * @return array<string, mixed>
     */
    public function payloadPreview(array $plano): array
    {
        /** @var Fatura $fatura */
        $fatura = $plano['fatura'];
        $fatura->loadMissing(['cartao', 'cartaoBandeira']);

        $temPdf = !empty($fatura->arquivo_pdf);
        $temCsv = !empty($fatura->arquivo_csv);
        $lancamentos = $plano['lancamentos'];
        $parcelasOutras = $plano['parcelasOutras'];
        $compras = $plano['comprasRestaurar'];

        return [
            'fatura_id' => (int) $fatura->id,
            'competencia' => sprintf('%02d/%d', (int) $fatura->mes, (int) $fatura->ano),
            'cartao_nome' => $fatura->cartao?->nome,
            'bandeira' => $fatura->cartaoBandeira?->bandeira,
            'tem_pdf' => $temPdf,
            'tem_csv' => $temCsv,
            'pdf_url' => $temPdf ? url('/api/v1/faturas/pdf/' . $fatura->id) : null,
            'pode_remover' => true,
            'motivos' => [
                ['value' => self::MOTIVO_REMOVER, 'label' => 'Apenas remover o PDF'],
                ['value' => self::MOTIVO_TROCAR_PDF, 'label' => 'PDF incorreto — quero trocar'],
            ],
            'lancamentos_deste_anexo' => [
                'quantidade' => $lancamentos->count(),
                'valor_total' => round((float) $lancamentos->sum('valor'), 2),
            ],
            'parcelas_geradas_outras_faturas' => [
                'quantidade' => $parcelasOutras->count(),
                'valor_total' => round((float) $parcelasOutras->sum('valor'), 2),
                'faturas_afetadas' => $plano['faturasAfetadas'],
            ],
            'compras_que_voltam_a_conciliar' => $compras,
            'faturas_stub_que_serao_excluidas' => $plano['stubsExcluir'],
            'avisos' => self::montarAvisos(
                $lancamentos->count(),
                $parcelasOutras->count(),
                count($compras),
                $plano['stubsExcluir']
            ),
        ];
    }

    /**
     * @param list<array{id: int, competencia: string}> $stubsExcluir
     * @return list<string>
     */
    public static function montarAvisos(
        int $lancamentos,
        int $parcelasOutras,
        int $comprasRestaurar,
        array $stubsExcluir
    ): array {
        $avisos = [];

        if ($lancamentos === 1) {
            $avisos[] = '1 lançamento importado deste PDF será apagado nesta fatura.';
        } elseif ($lancamentos > 1) {
            $avisos[] = $lancamentos . ' lançamentos importados deste PDF serão apagados nesta fatura.';
        }

        if ($parcelasOutras === 1) {
            $avisos[] = '1 parcela automática em faturas anteriores/futuras será apagada.';
        } elseif ($parcelasOutras > 1) {
            $avisos[] = $parcelasOutras . ' parcelas automáticas em faturas anteriores/futuras serão apagadas.';
        }

        foreach ($stubsExcluir as $stub) {
            $avisos[] = 'A fatura ' . $stub['competencia'] . ' ficará vazia e será removida.';
        }

        if ($comprasRestaurar === 1) {
            $avisos[] = '1 compra manual volta a precisar de conciliação.';
        } elseif ($comprasRestaurar > 1) {
            $avisos[] = $comprasRestaurar . ' compras manuais voltam a precisar de conciliação.';
        }

        if ($avisos === []) {
            $avisos[] = 'O anexo será removido. Nenhuma parcela gerada nem compra conciliada foi encontrada.';
        }

        return $avisos;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transacao>
     */
    private function lancamentosDesteAnexo(Fatura $fatura, int $userId)
    {
        $faturaId = (int) $fatura->id;

        return Transacao::where('user_id', $userId)
            ->where('fatura_id', $faturaId)
            ->where('criada_como_manual', false)
            ->where('compra_manual', false)
            ->where(function ($q) use ($faturaId) {
                $q->where('fatura_origem_id', $faturaId)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('fatura_origem_id')
                            ->where('importada_pdf', true);
                    });
            })
            ->get();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Transacao> $lancamentos
     * @return \Illuminate\Support\Collection<int, Transacao>
     */
    private function parcelasGeradasEmOutrasFaturas(Fatura $fatura, int $userId, $lancamentos)
    {
        $faturaId = (int) $fatura->id;
        $grupoIds = $lancamentos
            ->pluck('compra_grupo_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Transacao::where('user_id', $userId)
            ->where('fatura_id', '!=', $faturaId)
            ->where('criada_como_manual', false)
            ->where('compra_manual', false)
            ->where(function ($q) use ($faturaId, $grupoIds) {
                $q->where('fatura_origem_id', $faturaId);
                if ($grupoIds !== []) {
                    $q->orWhere(function ($q2) use ($grupoIds) {
                        $q2->whereNull('fatura_origem_id')
                            ->where('importada_pdf', false)
                            ->whereIn('compra_grupo_id', $grupoIds);
                    });
                }
            })
            ->get();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Transacao> $lancamentos
     * @return list<array<string, mixed>>
     */
    private function comprasQueVoltamAConciliar(Fatura $fatura, int $userId, $lancamentos): array
    {
        $faturaId = (int) $fatura->id;
        $lancamentoIds = $lancamentos->pluck('id')->map(fn ($id) => (int) $id)->all();
        $vistos = [];
        $resultado = [];

        $adicionar = function (Transacao $compra, string $origem) use (&$vistos, &$resultado) {
            $id = (int) $compra->id;
            if (isset($vistos[$id])) {
                return;
            }
            $vistos[$id] = true;
            $resultado[] = $this->payloadCompraRestaurada($compra, $origem);
        };

        if ($lancamentoIds !== []) {
            Transacao::query()
                ->where('user_id', $userId)
                ->where('criada_como_manual', true)
                ->whereIn('lancamento_id', $lancamentoIds)
                ->whereIn('status_conciliacao', [
                    Transacao::CONCILIACAO_CONCILIADA,
                    Transacao::CONCILIACAO_PENDENTE,
                ])
                ->get()
                ->each(function (Transacao $compra) use ($adicionar) {
                    $origem = $compra->status_conciliacao === Transacao::CONCILIACAO_PENDENTE
                        ? self::ORIGEM_SUGESTAO
                        : self::ORIGEM_DESVINCULO;
                    $adicionar($compra, $origem);
                });
        }

        Transacao::query()
            ->where('user_id', $userId)
            ->where('fatura_id', $faturaId)
            ->where('criada_como_manual', true)
            ->where('importada_pdf', true)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_CONCILIADA,
                Transacao::CONCILIACAO_PENDENTE,
            ])
            ->get()
            ->each(function (Transacao $compra) use ($adicionar) {
                $origem = $compra->status_conciliacao === Transacao::CONCILIACAO_PENDENTE
                    ? self::ORIGEM_SUGESTAO
                    : self::ORIGEM_MATCH_EXATO;
                $adicionar($compra, $origem);
            });

        usort($resultado, function (array $a, array $b) {
            return [$a['data'] ?? '', $a['id']] <=> [$b['data'] ?? '', $b['id']];
        });

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadCompraRestaurada(Transacao $compra, string $origem): array
    {
        $fatura = $compra->relationLoaded('fatura')
            ? $compra->fatura
            : Fatura::find($compra->fatura_id);

        $texto = trim((string) ($compra->observacoes ?: $compra->descricao ?: ''));
        if ($texto === '') {
            $texto = 'Compra #' . $compra->id;
        }

        $statusAtual = (string) ($compra->status_conciliacao ?: Transacao::CONCILIACAO_CONCILIADA);

        return [
            'id' => (int) $compra->id,
            'texto_compra' => $texto,
            'valor' => round((float) $compra->valor, 2),
            'data' => $compra->data?->toDateString(),
            'parcela_atual' => $compra->parcela_atual !== null ? (int) $compra->parcela_atual : null,
            'parcelas_total' => $compra->parcelas_total !== null ? (int) $compra->parcelas_total : null,
            'fatura_id' => (int) $compra->fatura_id,
            'competencia' => $fatura
                ? sprintf('%02d/%d', (int) $fatura->mes, (int) $fatura->ano)
                : null,
            'status_conciliacao_atual' => $statusAtual,
            'status_conciliacao_depois' => Transacao::CONCILIACAO_NAO_CONCILIADA,
            'origem_restauracao' => $origem,
        ];
    }
}
