<?php

namespace App\Services\Transacao;

use Carbon\Carbon;

/**
 * Pontuação de correspondência compra ↔ lançamento da fatura.
 * Usada na conciliação manual (ordenar candidatos) e preparada para sugestão automática.
 */
class ConciliacaoMatcher
{
    public const VALOR_TOLERANCIA = 0.01;
    public const DIAS_PROXIMIDADE = 5;

    /**
     * @param array{
     *     valor?: float|int|string|null,
     *     data?: string|null,
     *     fatura_id?: int|null,
     *     cartao_numero_id?: int|null,
     *     parcela_atual?: int|null,
     *     parcelas_total?: int|null,
     *     descricao?: string|null,
     *     estabelecimento?: string|null
     * } $compra
     * @param array{
     *     valor?: float|int|string|null,
     *     data?: string|null,
     *     fatura_id?: int|null,
     *     cartao_numero_id?: int|null,
     *     parcela_atual?: int|null,
     *     parcelas_total?: int|null,
     *     descricao_fatura?: string|null,
     *     estabelecimento?: string|null
     * } $lancamento
     */
    public function score(array $compra, array $lancamento): int
    {
        $score = 0;

        $valorCompra = round((float) ($compra['valor'] ?? 0), 2);
        $valorLancamento = round((float) ($lancamento['valor'] ?? 0), 2);
        if ($valorCompra > 0 && abs($valorCompra - $valorLancamento) <= self::VALOR_TOLERANCIA) {
            $score += 40;
        } elseif ($valorCompra > 0 && $valorLancamento > 0) {
            return 0;
        }

        if (!empty($compra['fatura_id']) && (int) $compra['fatura_id'] === (int) ($lancamento['fatura_id'] ?? 0)) {
            $score += 20;
        }

        $cartaoCompra = $compra['cartao_numero_id'] ?? null;
        $cartaoLancamento = $lancamento['cartao_numero_id'] ?? null;
        if ($cartaoCompra && $cartaoLancamento && (int) $cartaoCompra === (int) $cartaoLancamento) {
            $score += 15;
        }

        $dias = $this->diferencaDias($compra['data'] ?? null, $lancamento['data'] ?? null);
        if ($dias === 0) {
            $score += 15;
        } elseif ($dias !== null && $dias <= self::DIAS_PROXIMIDADE) {
            $score += 8;
        }

        $parcelaCompra = (int) ($compra['parcela_atual'] ?? 1);
        $parcelaLancamento = (int) ($lancamento['parcela_atual'] ?? 1);
        if ($parcelaCompra === $parcelaLancamento) {
            $score += 10;
        }

        return min(100, $score);
    }

    public function valorCompativel(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) <= self::VALOR_TOLERANCIA;
    }

    public function isSugestao(int $score): bool
    {
        return $score >= 50;
    }

    private function diferencaDias(?string $a, ?string $b): ?int
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return null;
        }

        try {
            return (int) abs(Carbon::parse($a)->diffInDays(Carbon::parse($b)));
        } catch (\Throwable) {
            return null;
        }
    }
}
