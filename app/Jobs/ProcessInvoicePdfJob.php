<?php

namespace App\Jobs;

use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\Pdf\InvoicePdfParserService;
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
                Transacao::where('fatura_id', $fatura->id)->delete();

                foreach ($parsed['transactions'] as $item) {
                    $valor = (float) ($item['valor'] ?? 0);
                    $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;

                    Transacao::create([
                        'user_id' => $fatura->user_id,
                        'fatura_id' => $fatura->id,
                        'data' => $item['data'] ?? null,
                        'estabelecimento' => $item['estabelecimento'] ?? 'Desconhecido',
                        'valor' => $valor,
                        'parcelas_total' => $item['parcelas_total'] ?? null,
                        'parcela_atual' => $item['parcela_atual'] ?? null,
                        'valor_parcela' => $item['valor_parcela'] ?? null,
                        'tipo' => $tipo,
                    ]);
                }

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
}
