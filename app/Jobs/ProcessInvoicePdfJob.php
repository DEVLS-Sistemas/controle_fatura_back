<?php

namespace App\Jobs;

use App\Exceptions\PdfPasswordException;
use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\Estabelecimento\EstabelecimentoService;
use App\Services\Fatura\FaturaService;
use App\Services\Pdf\InvoicePdfParserService;
use App\Services\Pdf\PdfSenhaRegra;
use App\Services\Transacao\TransacaoService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessInvoicePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  string|null  $arquivoPreferido  'pdf'|'csv'|null — qual anexo processar
     * @param  string|null  $senhaPdf  senha informada no request (tem prioridade sobre a do cartão)
     * @param  bool  $salvarSenhaPdf  grava a senha no cartão após desbloqueio bem-sucedido
     * @param  int|null  $cartaoNumeroIdPadrao  final padrão (CSV sem dígitos no arquivo)
     */
    public function __construct(
        public int $faturaId,
        public ?string $arquivoPreferido = null,
        public ?string $senhaPdf = null,
        public bool $salvarSenhaPdf = false,
        public ?int $cartaoNumeroIdPadrao = null,
    ) {
    }

    public function handle(InvoicePdfParserService $parserService): void
    {
        $fatura = Fatura::find($this->faturaId);

        if (!$fatura) {
            return;
        }

        try {
            $fatura->update([
                'status' => 'processando',
                'erro_mensagem' => null,
                'erro_codigo' => null,
            ]);

            $cartao = Cartao::find($fatura->cartao_id);
            $senhaResolvida = $this->resolveSenhaPdf($cartao);
            $absolutePath = $this->resolveAbsolutePath($fatura);

            try {
                $parsed = $parserService->parseFile($absolutePath, $senhaResolvida);
            } catch (PdfPasswordException $e) {
                throw new PdfPasswordException(
                    motivo: $e->motivo,
                    cartaoId: $cartao?->id,
                    regra: $cartao?->senha_pdf_regra ?? PdfSenhaRegra::sugerirPorBanco($cartao?->banco),
                    temSenhaCadastrada: (bool) ($cartao?->temSenhaPdf()),
                    message: $e->getMessage(),
                );
            }

            if ($this->salvarSenhaPdf && filled($this->senhaPdf) && $cartao) {
                $cartao->senha_pdf = $this->senhaPdf;
                if (empty($cartao->senha_pdf_regra)) {
                    $cartao->senha_pdf_regra = PdfSenhaRegra::sugerirPorBanco($cartao->banco);
                }
                $cartao->save();
            }

            DB::transaction(function () use ($fatura, $parsed) {
                $estabelecimentoService = new EstabelecimentoService();
                $transacaoService = new TransacaoService($estabelecimentoService);
                $responsavelId = $transacaoService->resolveDefaultResponsavelId((int) $fatura->user_id);

                // Sem bandeira no cadastro: cria/vincula a partir do PDF antes de resolver finais.
                $this->ensureFaturaBandeira($fatura, $parsed['text'] ?? null);

                $existing = Transacao::where('fatura_id', $fatura->id)->get();
                $keptImportIds = [];

                foreach ($parsed['transactions'] as $item) {
                    $valor = (float) ($item['valor'] ?? 0);
                    $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;
                    $nomeEstabelecimento = (string) ($item['estabelecimento'] ?? 'Desconhecido');
                    $cartaoNumeroId = $this->resolveCartaoNumeroIdFromParsed($fatura, $item);
                    if ($cartaoNumeroId === null && $this->cartaoNumeroIdPadrao !== null) {
                        $cartaoNumeroId = $this->cartaoNumeroIdPadrao;
                    }

                    $estabelecimento = $estabelecimentoService->findOrCreateByNome(
                        (int) $fatura->user_id,
                        $nomeEstabelecimento
                    );

                    $match = $this->findMatchingTransacao(
                        $existing,
                        $keptImportIds,
                        $estabelecimento->id,
                        $valor,
                        $item['parcela_atual'] ?? null,
                        $item['parcelas_total'] ?? null
                    );

                    if ($match) {
                        $keptImportIds[] = $match->id;
                        $update = [
                            'data' => $item['data'] ?? $match->data,
                            'valor' => $valor,
                            'parcelas_total' => $item['parcelas_total'] ?? null,
                            'parcela_atual' => $item['parcela_atual'] ?? null,
                            'valor_parcela' => $item['valor_parcela'] ?? null,
                            'tipo' => $tipo,
                            'importada_pdf' => true,
                        ];
                        if ($cartaoNumeroId !== null) {
                            $update['cartao_numero_id'] = $cartaoNumeroId;
                        }
                        $match->update($update);
                        $transacaoService->materializarParcelasFuturas($match->fresh());
                        continue;
                    }

                    $categoriaId = $estabelecimento->categoria_padrao_id;
                    $subcategoriaId = null;
                    if ($categoriaId && $estabelecimento->subcategoria_padrao_id) {
                        $vinculo = DB::table('categoria_subcategoria')
                            ->where('categoria_id', $categoriaId)
                            ->where('subcategoria_id', $estabelecimento->subcategoria_padrao_id)
                            ->exists();
                        if ($vinculo) {
                            $subcategoriaId = $estabelecimento->subcategoria_padrao_id;
                        }
                    }

                    $created = Transacao::create([
                        'user_id' => $fatura->user_id,
                        'fatura_id' => $fatura->id,
                        'cartao_numero_id' => $cartaoNumeroId,
                        'data' => $item['data'] ?? null,
                        'estabelecimento_id' => $estabelecimento->id,
                        'valor' => $valor,
                        'parcelas_total' => $item['parcelas_total'] ?? null,
                        'parcela_atual' => $item['parcela_atual'] ?? null,
                        'valor_parcela' => $item['valor_parcela'] ?? null,
                        'tipo' => $tipo,
                        'categoria_id' => $categoriaId,
                        'subcategoria_id' => $subcategoriaId,
                        'responsavel_id' => $responsavelId,
                        'importada_pdf' => true,
                    ]);
                    $keptImportIds[] = $created->id;
                    $transacaoService->materializarParcelasFuturas($created);
                }

                Transacao::where('fatura_id', $fatura->id)
                    ->where('importada_pdf', true)
                    ->whereNotIn('id', $keptImportIds)
                    ->delete();

                $previousFaturaTotal = self::resolvePreviousFaturaTotal($fatura);
                $calculatedTotal = self::calculateValorTotal($parsed['transactions'], $previousFaturaTotal);
                $headerTotal = isset($parsed['valor_fatura']) && $parsed['valor_fatura'] !== null
                    ? round((float) $parsed['valor_fatura'], 2)
                    : null;
                $conferencia = $parsed['conferencia'] ?? null;

                // Cabeçalho do PDF é a fonte de verdade quando bate com a soma das transações.
                // Em divergência (ex.: Inter lendo limite no lugar do total), prevalece o calculado.
                if ($headerTotal !== null && abs($headerTotal - $calculatedTotal) < 0.05) {
                    $valorTotal = $headerTotal;
                } else {
                    if ($headerTotal !== null && abs($headerTotal - $calculatedTotal) >= 0.05) {
                        Log::warning('Total do cabeçalho diverge da soma das transações; usando soma', [
                            'fatura_id' => $fatura->id,
                            'valor_cabecalho' => $conferencia['valor_cabecalho'] ?? $headerTotal,
                            'soma_transacoes' => $calculatedTotal,
                            'diferenca' => round(($conferencia['valor_cabecalho'] ?? $headerTotal) - $calculatedTotal, 2),
                        ]);
                    }
                    $valorTotal = $calculatedTotal;
                }

                $fatura->update([
                    'status' => 'processada',
                    'valor_total' => $valorTotal,
                    'processado_em' => now(),
                    'erro_mensagem' => null,
                ]);

                $faturaService = new FaturaService();

                // Corrige valor_total stale da fatura anterior (ex.: residual de stub pendente).
                $previousFatura = self::findPreviousFatura($fatura);
                if ($previousFatura && $previousFatura->status === 'processada') {
                    $faturaService->recalculateValorTotal((int) $previousFatura->id);
                    // Anterior pode ter mudado o residual → recalcula a atual.
                    $faturaService->recalculateValorTotal((int) $fatura->id);
                }

                // Se a seguinte já foi processada sem anterior, reaplica pagamentos corretamente.
                $nextFatura = self::findNextFatura($fatura);
                if ($nextFatura && $nextFatura->status === 'processada') {
                    $faturaService->recalculateValorTotal((int) $nextFatura->id);
                }
            });
        } catch (PdfPasswordException $e) {
            Log::warning('PDF da fatura protegido por senha', [
                'fatura_id' => $this->faturaId,
                'motivo' => $e->motivo,
            ]);

            $fatura->update([
                'status' => 'erro',
                'erro_mensagem' => $e->getMessage(),
                'erro_codigo' => $e->codigo(),
            ]);

            throw $e;
        } catch (Exception $e) {
            Log::error('Erro ao processar PDF da fatura', [
                'fatura_id' => $this->faturaId,
                'error' => $e->getMessage(),
            ]);

            $fatura->update([
                'status' => 'erro',
                'erro_mensagem' => $e->getMessage(),
                'erro_codigo' => null,
            ]);

            // Evita manter valor_total stale do último PDF bem-sucedido.
            try {
                (new FaturaService())->recalculateValorTotal((int) $fatura->id);
            } catch (Exception $recalcException) {
                Log::warning('Falha ao recalcular valor_total após erro no PDF', [
                    'fatura_id' => $this->faturaId,
                    'error' => $recalcException->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function resolveSenhaPdf(?Cartao $cartao): ?string
    {
        if (filled($this->senhaPdf)) {
            return (string) $this->senhaPdf;
        }

        if ($cartao && $cartao->temSenhaPdf()) {
            return (string) $cartao->senha_pdf;
        }

        return null;
    }

    private function resolveAbsolutePath(Fatura $fatura): string
    {
        $preferido = $this->arquivoPreferido;
        $candidates = [];

        if ($preferido === 'csv') {
            $candidates[] = $fatura->arquivo_csv;
        } elseif ($preferido === 'pdf') {
            $candidates[] = $fatura->arquivo_pdf;
        } else {
            // Reprocessar: PDF primeiro (cabeçalho com valor oficial), senão CSV.
            $candidates[] = $fatura->arquivo_pdf;
            $candidates[] = $fatura->arquivo_csv;
        }

        foreach ($candidates as $relative) {
            if (!$relative) {
                continue;
            }
            if (Storage::disk('local')->exists($relative)) {
                return Storage::disk('local')->path($relative);
            }
        }

        throw new Exception('Fatura sem arquivo anexado para processar');
    }

    /**
     * Calcula o valor da fatura com saldo corrido do extrato.
     *
     * Regras (Nubank e similares):
     * - Compras e antecipações de saque aumentam o saldo.
     * - Estornos/créditos (refund) reduzem o saldo.
     * - Pagamentos abatem primeiro o saldo da fatura anterior (parcial ou total,
     *   em um ou mais lançamentos). O que sobrar abate o ciclo atual (antecipado).
     * - Se a fatura anterior não foi quitada por completo, o residual entra no total.
     * - Sem fatura anterior processada (`null`): pagamentos NÃO antecipam o ciclo
     *   atual — eles são da competência desconhecida e zerariam indevidamente o total
     *   (ex.: importar só o CSV de maio sem abril processada).
     *
     * @param array<int, array<string, mixed>> $transactions
     */
    public static function calculateValorTotal(array $transactions, ?float $previousFaturaTotal = null): float
    {
        $balance = 0.0;
        $hasPrevious = $previousFaturaTotal !== null;
        $previousRemaining = max((float) ($previousFaturaTotal ?? 0), 0);

        foreach ($transactions as $item) {
            $valor = (float) ($item['valor'] ?? 0);
            $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;

            if ($tipo === Transacao::TIPO_PAYMENT) {
                if (!$hasPrevious) {
                    continue;
                }
                $appliedToPrevious = min($valor, $previousRemaining);
                $previousRemaining -= $appliedToPrevious;
                $balance -= ($valor - $appliedToPrevious);
                continue;
            }

            if (
                $tipo === Transacao::TIPO_PURCHASE
                || $tipo === Transacao::TIPO_ADVANCE
                || $tipo === Transacao::TIPO_FEE
            ) {
                $balance += $valor;
                continue;
            }

            if ($tipo === Transacao::TIPO_REFUND) {
                $balance -= $valor;
            }
        }

        $balance += $previousRemaining;

        return round(max($balance, 0), 2);
    }

    /**
     * Fatura da competência imediatamente anterior (mês/ano - 1) da mesma bandeira.
     * Não usa faturas antigas com gap — evita residual errado (ex.: 2019 em 2026).
     */
    public static function findPreviousFatura(Fatura $fatura): ?Fatura
    {
        [$prevMes, $prevAno] = self::previousCompetencia((int) $fatura->mes, (int) $fatura->ano);

        return self::findFaturaByCompetencia($fatura, $prevMes, $prevAno);
    }

    /**
     * Fatura da competência imediatamente seguinte (mês/ano + 1) da mesma bandeira.
     */
    public static function findNextFatura(Fatura $fatura): ?Fatura
    {
        [$nextMes, $nextAno] = self::nextCompetencia((int) $fatura->mes, (int) $fatura->ano);

        return self::findFaturaByCompetencia($fatura, $nextMes, $nextAno);
    }

    private static function findFaturaByCompetencia(Fatura $fatura, int $mes, int $ano): ?Fatura
    {
        $query = Fatura::query()
            ->where('user_id', $fatura->user_id)
            ->where('mes', $mes)
            ->where('ano', $ano);

        if ($fatura->cartao_bandeira_id) {
            $query->where('cartao_bandeira_id', $fatura->cartao_bandeira_id);
        } else {
            $query->where('cartao_id', $fatura->cartao_id);
        }

        return $query->first();
    }

    /**
     * Total da competência imediatamente anterior (mês/ano - 1) da mesma bandeira.
     * Só considera fatura anterior já fechada (`processada`). Stubs `pendente`
     * criados por materialização de parcelas NÃO entram como residual — senão o
     * valor_total da fatura processada infla e a quitação por pagamentos de F+1 falha.
     */
    public static function resolvePreviousFaturaTotal(Fatura $fatura): ?float
    {
        $previousFatura = self::findPreviousFatura($fatura);

        if (!$previousFatura || $previousFatura->status !== 'processada') {
            return null;
        }

        return (float) $previousFatura->valor_total;
    }

    /**
     * @return array{0: int, 1: int} [mes, ano]
     */
    public static function previousCompetencia(int $mes, int $ano): array
    {
        if ($mes === 1) {
            return [12, $ano - 1];
        }

        return [$mes - 1, $ano];
    }

    /**
     * @return array{0: int, 1: int} [mes, ano]
     */
    public static function nextCompetencia(int $mes, int $ano): array
    {
        if ($mes === 12) {
            return [1, $ano + 1];
        }

        return [$mes + 1, $ano];
    }

    /**
     * Aloca o total de pagamentos: primeiro quita a fatura anterior; o excedente
     * antecipa o ciclo atual.
     *
     * @return array{applied_to_previous: float, applied_to_current: float}
     */
    public static function allocatePayments(float $paymentsTotal, float $previousTotal): array
    {
        $paymentsTotal = max($paymentsTotal, 0.0);
        $previousTotal = max($previousTotal, 0.0);
        $appliedToPrevious = min($paymentsTotal, $previousTotal);

        return [
            'applied_to_previous' => round($appliedToPrevious, 2),
            'applied_to_current' => round($paymentsTotal - $appliedToPrevious, 2),
        ];
    }

    /**
     * Status de quitação da fatura com base nos pagamentos da competência seguinte.
     * Pagamentos na fatura N quitam N-1; o que sobrar antecipa N.
     * Logo, a fatura F é paga pelos lançamentos `tipo=payment` da fatura F+1.
     *
     * @return array{pago: bool, valor_pago: float, valor_restante: float}
     */
    public static function buildPagamentoStatus(float $valorTotal, float $pagamentosCompetenciaSeguinte): array
    {
        $valorTotal = max(round($valorTotal, 2), 0.0);
        $allocation = self::allocatePayments($pagamentosCompetenciaSeguinte, $valorTotal);
        $valorPago = $allocation['applied_to_previous'];
        $valorRestante = round(max($valorTotal - $valorPago, 0.0), 2);

        return [
            'pago' => $valorRestante <= 0.0,
            'valor_pago' => $valorPago,
            'valor_restante' => $valorRestante,
        ];
    }

    /**
     * Garante bandeira na fatura: usa a existente, a única do cartão, ou cria a partir do PDF.
     */
    private function ensureFaturaBandeira(Fatura $fatura, ?string $pdfText = null): void
    {
        if ($fatura->cartao_bandeira_id) {
            return;
        }

        $cartaoId = (int) $fatura->cartao_id;
        $bandeiras = CartaoBandeira::where('cartao_id', $cartaoId)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->orderBy('id')
            ->get(['id', 'bandeira']);

        if ($bandeiras->count() === 1) {
            $bandeiraId = (int) $bandeiras->first()->id;
        } elseif ($bandeiras->isEmpty()) {
            $bandeira = CartaoBandeira::create([
                'cartao_id' => $cartaoId,
                'bandeira' => $this->detectBandeiraNameFromPdf($pdfText),
                'ativo' => true,
            ]);
            $bandeiraId = (int) $bandeira->id;
        } else {
            // Várias bandeiras e fatura sem seleção: não dá para inferir com segurança.
            return;
        }

        $fatura->cartao_bandeira_id = $bandeiraId;
        $fatura->save();
    }

    /**
     * Detecta Visa/Mastercard/… no texto do PDF; fallback "Outra".
     */
    private function detectBandeiraNameFromPdf(?string $pdfText): string
    {
        if ($pdfText === null || trim($pdfText) === '') {
            return 'Outra';
        }

        $normalized = mb_strtolower($pdfText);
        $known = [
            'mastercard' => 'Mastercard',
            'visa' => 'Visa',
            'hipercard' => 'Hipercard',
            'american express' => 'Amex',
            'amex' => 'Amex',
            'elo' => 'Elo',
        ];

        foreach ($known as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return 'Outra';
    }

    /**
     * Resolve/cria o final do cartão na mesma bandeira da fatura.
     * Finais detectados no PDF nunca cruzam para outra bandeira do grupo.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveCartaoNumeroIdFromParsed(Fatura $fatura, array $item): ?int
    {
        $digitos = isset($item['ultimos_digitos']) ? trim((string) $item['ultimos_digitos']) : '';
        if (!preg_match('/^\d{4}$/', $digitos)) {
            return null;
        }

        $bandeiraId = $fatura->cartao_bandeira_id ? (int) $fatura->cartao_bandeira_id : null;
        if ($bandeiraId === null) {
            return null;
        }

        $nomeNoCartao = isset($item['nome_no_cartao']) ? trim((string) $item['nome_no_cartao']) : null;
        if ($nomeNoCartao === '') {
            $nomeNoCartao = null;
        }

        $tipoNumero = isset($item['tipo_numero']) ? trim((string) $item['tipo_numero']) : null;
        if (!in_array($tipoNumero, ['fisico', 'virtual', 'adicional'], true)) {
            $tipoNumero = null;
        }

        $numero = CartaoNumero::withTrashed()
            ->where('cartao_bandeira_id', $bandeiraId)
            ->where('ultimos_digitos', $digitos)
            ->first();

        if ($numero) {
            if ($numero->trashed()) {
                $numero->restore();
            }

            $dirty = false;
            if (!$numero->ativo) {
                $numero->ativo = true;
                $dirty = true;
            }
            if ($nomeNoCartao !== null && empty($numero->nome_no_cartao)) {
                $numero->nome_no_cartao = $nomeNoCartao;
                $dirty = true;
            }
            if ($tipoNumero !== null && empty($numero->tipo)) {
                $numero->tipo = $tipoNumero;
                $dirty = true;
            }
            if ($dirty) {
                $numero->save();
            }

            return (int) $numero->id;
        }

        $numero = CartaoNumero::create([
            'cartao_bandeira_id' => $bandeiraId,
            'ultimos_digitos' => $digitos,
            'tipo' => $tipoNumero,
            'nome_no_cartao' => $nomeNoCartao,
            'ativo' => true,
        ]);

        return (int) $numero->id;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Transacao> $existing
     * @param array<int, int> $matchedIds
     */
    private function findMatchingTransacao(
        $existing,
        array $matchedIds,
        int $estabelecimentoId,
        float $valor,
        mixed $parcelaAtual,
        mixed $parcelasTotal
    ): ?Transacao {
        foreach ($existing as $transacao) {
            if (in_array($transacao->id, $matchedIds, true)) {
                continue;
            }

            if ((int) $transacao->estabelecimento_id !== $estabelecimentoId) {
                continue;
            }

            if (abs((float) $transacao->valor - $valor) > 0.01) {
                continue;
            }

            if ((int) ($transacao->parcela_atual ?? 0) !== (int) ($parcelaAtual ?? 0)) {
                continue;
            }

            if ((int) ($transacao->parcelas_total ?? 0) !== (int) ($parcelasTotal ?? 0)) {
                continue;
            }

            return $transacao;
        }

        return null;
    }
}
