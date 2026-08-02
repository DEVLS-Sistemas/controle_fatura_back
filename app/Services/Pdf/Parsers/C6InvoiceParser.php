<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas C6 Bank (PDF layout atual).
 *
 * Linha típica (após normalizar espaços), na seção "Transações do cartão":
 *   10 jun Inclusao de Pagamento 157,92
 *   06 nov AMAZON RETAIL CPI - Parcela 8/12 68,03
 *   11 jun CLARO FLEX 59,99
 *
 * Fechamento (para ano): "fechamento desta fatura em 03/07/26"
 */
class C6InvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'c6';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'c6 bank')
            || str_contains($normalized, 'c6bank')
            || str_contains($normalized, 'banco c6')
            || (
                str_contains($normalized, 'cartão c6')
                && str_contains($normalized, 'transações do cartão')
            )
            || (
                str_contains($normalized, 'cartao c6')
                && str_contains($normalized, 'transacoes do cartao')
            );
    }

    public function parse(string $text): array
    {
        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^transa[cç][oõ]es do cart[aã]o\b/iu', $line)) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            // Fim da seção de lançamentos (boleto / formas de pagamento).
            if (preg_match('/^(formas de pagamento|pague com pix|n[uú]mero do cart[aã]o)\b/iu', $line)) {
                break;
            }

            // Cabeçalhos de cartão / totais
            if (preg_match(
                '/^(cart[aã]o c6|valores em reais|subtotal deste cart[aã]o|lembrando:)/iu',
                $line
            )) {
                continue;
            }

            if (!preg_match(
                '/^(?<dia>\d{1,2})\s+(?<mes>[a-zç]{3})\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/iu',
                $line,
                $m
            )) {
                continue;
            }

            $mes = $this->monthToNumber($m['mes']);
            if (!$mes) {
                continue;
            }

            $date = $this->resolveTransactionDate((int) $m['dia'], $mes, $closingMonth, $closingYear);
            $resto = trim($m['resto']);
            $valor = $this->parseMoney($m['valor']);

            if ($resto === '') {
                continue;
            }

            $transactions[] = $this->makeTransaction($date, $resto, $valor);
        }

        return $transactions;
    }

    /**
     * @return array{0: int, 1: int} mês e ano do fechamento
     */
    private function resolveClosingPeriod(string $text): array
    {
        // "fechamento desta fatura em 03/07/26" ou "até 03/07/26"
        if (preg_match(
            '/fechamento(?:\s+desta\s+fatura)?\s+em\s+(\d{2})\/(\d{2})\/(\d{2,4})/iu',
            $text,
            $m
        )) {
            return $this->normalizeYearMonth((int) $m[2], (int) $m[3]);
        }

        if (preg_match(
            '/transa[cç][oõ]es feitas at[eé]\s+(\d{2})\/(\d{2})\/(\d{2,4})/iu',
            $text,
            $m
        )) {
            return $this->normalizeYearMonth((int) $m[2], (int) $m[3]);
        }

        // "Vencimento: 10/07/2026" — aproximação (mês do vencimento ≈ ciclo)
        if (preg_match('/vencimento:\s*\d{1,2}\/\d{2}\/(20\d{2})/iu', $text, $m)) {
            if (preg_match('/vencimento:\s*\d{1,2}\/(\d{2})\/20\d{2}/iu', $text, $vm)) {
                return [(int) $vm[1], (int) $m[1]];
            }
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return [(int) date('n'), (int) $m[1]];
        }

        return [(int) date('n'), (int) date('Y')];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function normalizeYearMonth(int $month, int $yearOrYy): array
    {
        $year = $yearOrYy >= 100
            ? $yearOrYy
            : ($yearOrYy >= 70 ? 1900 + $yearOrYy : 2000 + $yearOrYy);

        return [$month, $year];
    }

    private function resolveTransactionDate(int $day, int $month, int $closingMonth, int $closingYear): string
    {
        // Compra em mês posterior ao fechamento pertence ao ciclo anterior (ano - 1).
        $year = $month > $closingMonth ? $closingYear - 1 : $closingYear;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function monthToNumber(string $month): ?int
    {
        $map = [
            'JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4,
            'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8,
            'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12,
        ];

        $key = mb_strtoupper(substr($month, 0, 3));

        return $map[$key] ?? null;
    }
}
