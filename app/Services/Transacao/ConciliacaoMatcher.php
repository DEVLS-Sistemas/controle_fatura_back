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
        if ($valorCompra > 0 && self::valoresCompativeis($valorCompra, $valorLancamento)) {
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

    public static function centavos(float $valor): int
    {
        return (int) round($valor * 100);
    }

    public static function valoresCompativeis(float $a, float $b, int $toleranciaCentavos = 1): bool
    {
        return abs(self::centavos($a) - self::centavos($b)) <= $toleranciaCentavos;
    }

    /**
     * Parcelas da mesma compra costumam variar alguns centavos entre faturas
     * (juros/arredondamento do banco). 50 centavos ou 2% do maior valor.
     */
    public static function valoresParcelasCompativeis(float $a, float $b): bool
    {
        $diff = abs(self::centavos($a) - self::centavos($b));
        $maior = max(self::centavos($a), self::centavos($b), 1);
        $tolerancia = max(50, (int) round($maior * 0.02));

        return $diff <= $tolerancia;
    }

    public function valorCompativel(float $a, float $b): bool
    {
        return self::valoresCompativeis($a, $b);
    }

    public function isSugestao(int $score): bool
    {
        return $score >= 50;
    }

    /**
     * Emparelha compras manuais e lançamentos do PDF 1:1, do maior score para o menor.
     *
     * @param list<array<string, mixed>> $compras
     * @param list<array<string, mixed>> $lancamentos
     * @return list<array{compra: int, lancamento: int, score: int}>
     */
    public function parearUnico(array $compras, array $lancamentos): array
    {
        $pares = [];
        foreach ($compras as $i => $compra) {
            foreach ($lancamentos as $j => $lancamento) {
                $score = $this->score($compra, $lancamento);
                if (!$this->isSugestao($score)) {
                    continue;
                }
                $pares[] = [
                    'compra' => (int) $i,
                    'lancamento' => (int) $j,
                    'score' => $score,
                ];
            }
        }

        usort($pares, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $usadasCompra = [];
        $usadosLancamento = [];
        $escolhidos = [];
        foreach ($pares as $par) {
            if (isset($usadasCompra[$par['compra']]) || isset($usadosLancamento[$par['lancamento']])) {
                continue;
            }
            $usadasCompra[$par['compra']] = true;
            $usadosLancamento[$par['lancamento']] = true;
            $escolhidos[] = $par;
        }

        return $escolhidos;
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
