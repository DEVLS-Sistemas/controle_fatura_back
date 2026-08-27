<?php

namespace App\Services\Fatura;

use App\Models\CompraHistorico;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\Transacao\CompraHistoricoService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
     * Etapa 2: remove o anexo e desfaz lançamentos/parcelas gerados por ele.
     */
    public function handleRemover(object $atributes): object
    {
        $faturaId = (int) ($atributes->id ?? 0);
        if ($faturaId < 1) {
            throw new Exception('Informe a fatura', 422);
        }

        $motivo = trim((string) ($atributes->motivo ?? ''));
        if ($motivo === self::MOTIVO_TROCAR_PDF) {
            throw new Exception(
                'Para trocar o PDF, envie o arquivo novo. Essa opção entra na etapa 3.',
                422
            );
        }
        if ($motivo !== self::MOTIVO_REMOVER) {
            throw new Exception('Informe o motivo: remover ou trocar_pdf', 422);
        }

        $plano = $this->montarPlano($faturaId);
        $tipo = $this->resolverTipoAnexo($atributes->tipo ?? null, $plano['fatura']);
        $this->aplicarReversao($plano, $tipo);

        $compras = $plano['comprasRestaurar'];
        $quantidadeCompras = count($compras);
        $fatura = $plano['fatura']->fresh();

        return (object) [
            'status' => true,
            'message' => self::montarMensagemRemocao($quantidadeCompras),
            'data' => [
                'fatura_id' => (int) $plano['fatura']->id,
                'motivo' => self::MOTIVO_REMOVER,
                'anexo_removido' => true,
                'tem_pdf' => !empty($fatura?->arquivo_pdf),
                'tem_csv' => !empty($fatura?->arquivo_csv),
                'pode_remover_anexo' => !empty($fatura?->arquivo_pdf) || !empty($fatura?->arquivo_csv),
                'lancamentos_apagados' => $plano['lancamentos']->count(),
                'parcelas_apagadas_outras_faturas' => $plano['parcelasOutras']->count(),
                'faturas_stub_excluidas' => array_map(
                    fn (array $stub) => $stub['id'],
                    $plano['stubsExcluir']
                ),
                'compras_que_voltaram_a_conciliar' => $compras,
                'avisos' => self::montarAvisos(
                    $plano['lancamentos']->count(),
                    $plano['parcelasOutras']->count(),
                    $quantidadeCompras,
                    $plano['stubsExcluir']
                ),
            ],
        ];
    }

    /**
     * Usado ao excluir a fatura: desfaz parcelas geradas em competências vizinhas
     * e restaura compras manuais antes de apagar a própria fatura.
     */
    public function reverterAntesDeExcluirFatura(Fatura $fatura): void
    {
        $temAnexo = !empty($fatura->arquivo_pdf) || !empty($fatura->arquivo_csv);
        if ($temAnexo && ($fatura->status ?? '') !== 'processando') {
            $plano = $this->montarPlano((int) $fatura->id);
            $this->aplicarReversao($plano, 'ambos', false);
            return;
        }

        $this->apagarParcelasOrigemEmOutrasFaturas($fatura);
    }

    /**
     * Etapa 3: desfaz o anexo errado sem apagar o arquivo ainda (o attach substitui).
     *
     * @return array<string, mixed>
     */
    public function reverterMantendoArquivo(int $faturaId): array
    {
        $plano = $this->montarPlano($faturaId);
        $this->aplicarReversao($plano, 'ambos', false);

        return $plano;
    }

    /**
     * @return array{
     *     fatura: Fatura,
     *     lancamentos: \Illuminate\Support\Collection<int, Transacao>,
     *     parcelasOutras: \Illuminate\Support\Collection<int, Transacao>,
     *     comprasRestaurar: list<array<string, mixed>>,
     *     comprasRestaurarItens: list<array{compra: Transacao, origem: string}>,
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
        $comprasItens = $this->comprasQueVoltamAConciliar($fatura, $userId, $lancamentos);
        $comprasRestaurar = array_map(
            fn (array $item) => $this->payloadCompraRestaurada($item['compra'], $item['origem']),
            $comprasItens
        );

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
            'comprasRestaurarItens' => $comprasItens,
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

    public static function montarMensagemRemocao(int $comprasRestauradas): string
    {
        if ($comprasRestauradas === 1) {
            return 'Anexo removido. 1 compra voltou a precisar de conciliação.';
        }
        if ($comprasRestauradas > 1) {
            return 'Anexo removido. ' . $comprasRestauradas . ' compras voltaram a precisar de conciliação.';
        }

        return 'Anexo removido.';
    }

    /**
     * @param array<string, mixed> $plano
     * @param 'pdf'|'csv'|'ambos' $tipo
     */
    public function aplicarReversao(array $plano, string $tipo, bool $removerArquivo = true): void
    {
        /** @var Fatura $fatura */
        $fatura = $plano['fatura'];
        $historico = new CompraHistoricoService();

        foreach ($plano['comprasRestaurarItens'] as $item) {
            $this->restaurarCompra($item['compra'], $item['origem'], $historico);
        }

        $idsLancamentos = $plano['lancamentos']->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($idsLancamentos !== []) {
            Transacao::whereIn('id', $idsLancamentos)->delete();
        }

        $idsParcelas = $plano['parcelasOutras']->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($idsParcelas !== []) {
            Transacao::whereIn('id', $idsParcelas)->delete();
        }

        $faturaIdsRecalc = [(int) $fatura->id];
        foreach ($plano['faturasAfetadas'] as $afetada) {
            $faturaIdsRecalc[] = (int) $afetada['id'];
        }
        $stubIds = array_map(fn (array $stub) => (int) $stub['id'], $plano['stubsExcluir']);
        $faturaIdsRecalc = array_values(array_diff($faturaIdsRecalc, $stubIds));

        foreach ($plano['stubsExcluir'] as $stub) {
            $stubFatura = Fatura::where('id', $stub['id'])
                ->where('user_id', $fatura->user_id)
                ->first();
            if ($stubFatura) {
                $stubFatura->delete();
            }
        }

        if ($removerArquivo) {
            $this->removerArquivosDoTipo($fatura, $tipo);
        }

        $fatura->status = 'pendente';
        $fatura->processado_em = null;
        $fatura->erro_mensagem = null;
        $fatura->erro_codigo = null;
        $fatura->save();

        (new FaturaService())->recalculateValorTotalMany($faturaIdsRecalc);
    }

    /**
     * @return 'pdf'|'csv'|'ambos'
     */
    private function resolverTipoAnexo(mixed $tipo, Fatura $fatura): string
    {
        $tipo = strtolower(trim((string) ($tipo ?? '')));
        $temPdf = !empty($fatura->arquivo_pdf);
        $temCsv = !empty($fatura->arquivo_csv);

        if ($tipo === 'pdf') {
            if (!$temPdf) {
                throw new Exception('Esta fatura não possui PDF para remover', 422);
            }
            return 'pdf';
        }
        if ($tipo === 'csv') {
            if (!$temCsv) {
                throw new Exception('Esta fatura não possui CSV para remover', 422);
            }
            return 'csv';
        }
        if ($tipo === 'ambos') {
            return 'ambos';
        }

        if ($temPdf) {
            return 'pdf';
        }

        return 'csv';
    }

    /**
     * @param 'pdf'|'csv'|'ambos' $tipo
     */
    private function removerArquivosDoTipo(Fatura $fatura, string $tipo): void
    {
        if ($tipo === 'pdf' || $tipo === 'ambos') {
            $this->apagarArquivoStorage($fatura->arquivo_pdf);
            $fatura->arquivo_pdf = null;
        }
        if ($tipo === 'csv' || $tipo === 'ambos') {
            $this->apagarArquivoStorage($fatura->arquivo_csv);
            $fatura->arquivo_csv = null;
        }
    }

    private function apagarArquivoStorage(?string $relativePath): void
    {
        if ($relativePath && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    private function restaurarCompra(
        Transacao $compra,
        string $origem,
        CompraHistoricoService $historico
    ): void {
        $compra->status_conciliacao = Transacao::CONCILIACAO_NAO_CONCILIADA;
        $compra->lancamento_id = null;
        $compra->ignorar_no_total = false;
        $compra->compra_manual = true;

        if ($origem === self::ORIGEM_MATCH_EXATO) {
            $compra->importada_pdf = false;
            $compra->fatura_origem_id = null;
            $compra->criada_como_manual = true;
            $compra->descricao_fatura = null;
        }

        $compra->save();

        $historico->registrar(
            $compra,
            CompraHistorico::ACAO_DESVINCULADA,
            'Anexo da fatura removido; compra voltou a precisar de conciliação',
            ['origem_restauracao' => $origem]
        );
    }

    private function apagarParcelasOrigemEmOutrasFaturas(Fatura $fatura): void
    {
        $userId = (int) $fatura->user_id;
        $faturaId = (int) $fatura->id;

        $parcelas = Transacao::where('user_id', $userId)
            ->where('fatura_origem_id', $faturaId)
            ->where('fatura_id', '!=', $faturaId)
            ->where('criada_como_manual', false)
            ->where('compra_manual', false)
            ->get();

        $faturaIds = $parcelas->pluck('fatura_id')->map(fn ($id) => (int) $id)->unique()->all();
        if (!$parcelas->isEmpty()) {
            Transacao::whereIn('id', $parcelas->pluck('id')->all())->delete();
        }

        foreach ($faturaIds as $outraId) {
            $outra = Fatura::where('id', $outraId)->where('user_id', $userId)->first();
            if (!$outra) {
                continue;
            }
            $restantes = Transacao::where('fatura_id', $outraId)->where('user_id', $userId)->count();
            if (
                $restantes === 0
                && empty($outra->arquivo_pdf)
                && empty($outra->arquivo_csv)
                && $outra->status === 'pendente'
            ) {
                $outra->delete();
                continue;
            }
            (new FaturaService())->recalculateValorTotal($outraId);
        }
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
     * @return list<array{compra: Transacao, origem: string}>
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
            $resultado[] = [
                'compra' => $compra,
                'origem' => $origem,
            ];
        };

        if ($lancamentoIds !== []) {
            Transacao::query()
                ->with('fatura')
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
            ->with('fatura')
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
            $dataA = $a['compra']->data?->toDateString() ?? '';
            $dataB = $b['compra']->data?->toDateString() ?? '';

            return [$dataA, (int) $a['compra']->id] <=> [$dataB, (int) $b['compra']->id];
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
            'precisa_conciliar' => true,
            'precisa_conciliar_label' => Transacao::PRECISA_CONCILIAR_LABEL,
        ];
    }
}
