<?php

namespace App\Jobs;

use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\Estabelecimento\EstabelecimentoService;
use App\Services\Pdf\InvoicePdfParserService;
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

    public function __construct(public int $faturaId)
    {
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
            ]);

            if (!$fatura->arquivo_pdf) {
                throw new Exception('Fatura sem arquivo PDF anexado');
            }

            $absolutePath = Storage::disk('local')->path($fatura->arquivo_pdf);
            $parsed = $parserService->parseFile($absolutePath);

            DB::transaction(function () use ($fatura, $parsed) {
                $estabelecimentoService = new EstabelecimentoService();
                $transacaoService = new TransacaoService($estabelecimentoService);
                $responsavelId = $transacaoService->resolveDefaultResponsavelId((int) $fatura->user_id);

                $existing = Transacao::where('fatura_id', $fatura->id)->get();
                $keptImportIds = [];

                foreach ($parsed['transactions'] as $item) {
                    $valor = (float) ($item['valor'] ?? 0);
                    $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;
                    $nomeEstabelecimento = (string) ($item['estabelecimento'] ?? 'Desconhecido');

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
                        $match->update([
                            'data' => $item['data'] ?? $match->data,
                            'valor' => $valor,
                            'parcelas_total' => $item['parcelas_total'] ?? null,
                            'parcela_atual' => $item['parcela_atual'] ?? null,
                            'valor_parcela' => $item['valor_parcela'] ?? null,
                            'tipo' => $tipo,
                            'importada_pdf' => true,
                        ]);
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
                }

                Transacao::where('fatura_id', $fatura->id)
                    ->where('importada_pdf', true)
                    ->whereNotIn('id', $keptImportIds)
                    ->delete();

                $previousFaturaTotal = self::resolvePreviousFaturaTotal($fatura);

                $fatura->update([
                    'status' => 'processada',
                    'valor_total' => self::calculateValorTotal($parsed['transactions'], $previousFaturaTotal),
                    'processado_em' => now(),
                    'erro_mensagem' => null,
                ]);
            });
        } catch (Exception $e) {
            Log::error('Erro ao processar PDF da fatura', [
                'fatura_id' => $this->faturaId,
                'error' => $e->getMessage(),
            ]);

            $fatura->update([
                'status' => 'erro',
                'erro_mensagem' => $e->getMessage(),
            ]);

            throw $e;
        }
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
     *
     * @param array<int, array<string, mixed>> $transactions
     */
    public static function calculateValorTotal(array $transactions, ?float $previousFaturaTotal = null): float
    {
        $balance = 0.0;
        $previousRemaining = max((float) ($previousFaturaTotal ?? 0), 0);

        foreach ($transactions as $item) {
            $valor = (float) ($item['valor'] ?? 0);
            $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;

            if ($tipo === Transacao::TIPO_PAYMENT) {
                $appliedToPrevious = min($valor, $previousRemaining);
                $previousRemaining -= $appliedToPrevious;
                $balance -= ($valor - $appliedToPrevious);
                continue;
            }

            if ($tipo === Transacao::TIPO_PURCHASE || $tipo === Transacao::TIPO_ADVANCE) {
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

    private static function resolvePreviousFaturaTotal(Fatura $fatura): ?float
    {
        $previousFatura = Fatura::query()
            ->where('user_id', $fatura->user_id)
            ->where('cartao_id', $fatura->cartao_id)
            ->where('id', '!=', $fatura->id)
            ->where(function ($query) use ($fatura) {
                $query->where('ano', '<', $fatura->ano)
                    ->orWhere(function ($nested) use ($fatura) {
                        $nested->where('ano', $fatura->ano)
                            ->where('mes', '<', $fatura->mes);
                    });
            })
            ->orderByDesc('ano')
            ->orderByDesc('mes')
            ->value('valor_total');

        return $previousFatura !== null ? (float) $previousFatura : null;
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
