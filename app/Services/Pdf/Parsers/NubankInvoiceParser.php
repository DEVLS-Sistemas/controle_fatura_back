<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser específico para faturas Nubank.
 *
 * Preferir texto extraído com `pdftotext -layout` (valores alinhados na mesma linha).
 *
 * Layout atual (após normalizar espaços):
 *   05 ABR •••• 6921 Jim.Com* Emerson Ferr - Parcela 2/5 R$ 692,41
 *   08 ABR Estorno de "Mercadolivre*Paulista" −R$ 19,98
 *   08 ABR Pagamento em 08 ABR −R$ 2.260,97
 */
class NubankInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'nubank';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'nubank')
            || str_contains($normalized, 'nu pagamentos');
    }

    public function parse(string $text): array
    {
        $year = $this->resolveYear($text);
        $dueMonth = $this->resolveDueMonth($text);

        $layout = $this->parseLayoutSingleLine($text, $year, $dueMonth);
        if (count($layout) > 0) {
            return $layout;
        }

        $multiline = $this->parseMultilineLayout($text, $year, $dueMonth);
        if (count($multiline) > 0) {
            return $multiline;
        }

        return $this->parseLegacySingleLineLayout($text, $year, $dueMonth);
    }

    /**
     * Linhas do pdftotext -layout: data + (cartão) + descrição + valor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseLayoutSingleLine(string $text, int $year, ?int $dueMonth): array
    {
        $transactions = [];
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^transa[cç][oõ]es\b/iu', $line)) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            if (preg_match('/^(em cumprimento|como assegurado)\b/iu', $line)) {
                break;
            }

            if (
                !preg_match(
                    '/^(?<dia>\d{2})\s+(?<mes>[A-Z]{3})\s+(?:[•*\.]{2,}\s*\d{4}\s+)?(?<resto>.+?)\s+(?<valor>[-−]?R\$\s*\d{1,3}(?:\.\d{3})*,\d{2})$/iu',
                    $line,
                    $m
                )
            ) {
                continue;
            }

            $mes = $this->monthToNumber($m['mes']);
            if (!$mes) {
                continue;
            }

            $resto = trim($m['resto']);
            if ($resto === '' || preg_match('/^pagamentos$/iu', $resto)) {
                continue;
            }

            $date = $this->buildDate($year, $mes, (int) $m['dia'], $dueMonth);
            $valor = $this->parseMoney(str_replace('−', '-', $m['valor']));
            $transactions[] = $this->makeTransaction($date, $resto, $valor);
        }

        return $this->dedupeConsecutivePayments($transactions);
    }

    /**
     * Fallback quando o PDF veio sem -layout (valores em linhas separadas).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseMultilineLayout(string $text, int $year, ?int $dueMonth): array
    {
        $lines = $this->lines($text);
        $start = $this->findTransactionsSectionStart($lines);
        if ($start === null) {
            return [];
        }

        $transactions = [];
        $count = count($lines);
        /** @var array<int, array{date: string, descricao: string}> $pending */
        $pending = [];

        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^(em cumprimento|como assegurado)\b/iu', $line)) {
                break;
            }

            if ($this->isNoiseLine($line)) {
                continue;
            }

            $dateParts = $this->matchDayMonth($line);
            if ($dateParts) {
                [$day, $month] = $dateParts;
                $date = $this->buildDate($year, $month, $day, $dueMonth);

                $j = $i + 1;
                while ($j < $count && $this->isCardMask($lines[$j])) {
                    $j++;
                }

                if ($j >= $count) {
                    break;
                }

                // data → valor (pagamento antigo) ou data → descrição
                if ($this->isMoneyLine($lines[$j])) {
                    $this->flushPendingWithAmounts($pending, $transactions, [$this->parseMoneyLine($lines[$j])]);
                    $valor = $this->parseMoneyLine($lines[$j]);
                    $descricao = 'Pagamento';
                    $k = $j + 1;
                    while ($k < $count && ($lines[$k] === '' || $this->isNoiseLine($lines[$k]))) {
                        $k++;
                    }
                    if ($k < $count && preg_match('/pagamento/iu', $lines[$k])) {
                        $descricao = $lines[$k];
                        $k++;
                        while ($k < $count && ($this->isMoneyLine($lines[$k]) || $this->isNoiseLine($lines[$k]))) {
                            $k++;
                        }
                        $i = $k - 1;
                    } else {
                        $i = $j;
                    }
                    $transactions[] = $this->makeTransaction($date, $descricao, $valor);
                    continue;
                }

                $descricao = $lines[$j];
                if ($this->isNoiseLine($descricao) || $this->matchDayMonth($descricao) || $this->isMoneyLine($descricao)) {
                    continue;
                }

                $k = $j + 1;
                while ($k < $count && !$this->isMoneyLine($lines[$k]) && !$this->matchDayMonth($lines[$k])) {
                    if (
                        $lines[$k] !== ''
                        && !$this->isNoiseLine($lines[$k])
                        && !$this->isCardMask($lines[$k])
                        && !preg_match('/^estorno referente/iu', $lines[$k])
                    ) {
                        $descricao .= ' ' . $lines[$k];
                    }
                    $k++;
                }

                $descricao = trim($descricao);
                $amounts = [];
                while ($k < $count && ($this->isMoneyLine($lines[$k]) || $this->isNoiseLine($lines[$k]))) {
                    if ($this->isMoneyLine($lines[$k])) {
                        $amounts[] = $this->parseMoneyLine($lines[$k]);
                    }
                    $k++;
                }

                $isCatchUp = count($amounts) > 1 && $pending !== [];
                if ($isCatchUp) {
                    $pending[] = ['date' => $date, 'descricao' => $descricao];
                    $this->flushPendingWithAmounts($pending, $transactions, $amounts);
                } elseif (count($amounts) >= 1) {
                    // Um valor alinhado pertence à descrição atual; sobras (totais) vão à fila.
                    $transactions[] = $this->makeTransaction($date, $descricao, $amounts[0]);
                    $rest = array_slice($amounts, 1);
                    if ($rest !== []) {
                        $this->flushPendingWithAmounts($pending, $transactions, $rest);
                    }
                } else {
                    $pending[] = ['date' => $date, 'descricao' => $descricao];
                }

                $i = max($j, $k - 1);
                continue;
            }

            if ($this->isMoneyLine($line)) {
                $amounts = [$this->parseMoneyLine($line)];
                $k = $i + 1;
                while ($k < $count && $this->isMoneyLine($lines[$k])) {
                    $amounts[] = $this->parseMoneyLine($lines[$k]);
                    $k++;
                }
                $this->flushPendingWithAmounts($pending, $transactions, $amounts);
                $i = $k - 1;
            }
        }

        return $this->dedupeConsecutivePayments($transactions);
    }

    /**
     * @param array<int, array{date: string, descricao: string}> $pending
     * @param array<int, array<string, mixed>> $transactions
     * @param array<int, float> $amounts
     */
    private function flushPendingWithAmounts(array &$pending, array &$transactions, array $amounts): void
    {
        while ($pending !== [] && $amounts !== []) {
            $item = array_shift($pending);
            $valor = array_shift($amounts);
            $transactions[] = $this->makeTransaction($item['date'], $item['descricao'], $valor);
        }
        // Sobras de valor (totais da seção Pagamentos etc.) são ignoradas.
    }

    /**
     * Layout legado: tudo em uma linha sem R$.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseLegacySingleLineLayout(string $text, int $year, ?int $dueMonth): array
    {
        $transactions = [];

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^(?<dia>\d{2})\s+(?<mes>[A-Z]{3})\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/iu', $line, $m)) {
                $mes = $this->monthToNumber($m['mes']);
                if (!$mes) {
                    continue;
                }

                $date = $this->buildDate($year, $mes, (int) $m['dia'], $dueMonth);
                $resto = trim($m['resto']);
                $valor = $this->parseMoney($m['valor']);

                [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
                $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
                continue;
            }

            if (preg_match('/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u', $line, $m)) {
                $date = $this->parseDate($m['data'], $year);
                $resto = trim($m['resto']);
                $valor = $this->parseMoney($m['valor']);
                [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
                $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
            }
        }

        return $transactions;
    }

    /**
     * @param array<int, string> $lines
     */
    private function findTransactionsSectionStart(array $lines): ?int
    {
        foreach ($lines as $idx => $line) {
            if (preg_match('/^transa[cç][oõ]es\b/iu', $line)) {
                return $idx + 1;
            }
        }

        foreach ($lines as $idx => $line) {
            if ($this->matchDayMonth($line) && isset($lines[$idx + 1]) && $this->isCardMask($lines[$idx + 1])) {
                return $idx;
            }
        }

        return null;
    }

    private function resolveYear(string $text): int
    {
        if (preg_match('/data\s+de\s+vencimento\s*:?\s*\d{1,2}\s+[A-Z]{3}\s+(20\d{2})/iu', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\bFATURA\s+\d{1,2}\s+[A-Z]{3}\s+(20\d{2})\b/iu', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/fatura\s+de\s+\w+\s+de\s+(20\d{2})/iu', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b(?:JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(20\d{2})\b/iu', $text, $m)) {
            return (int) $m[1];
        }

        return (int) date('Y');
    }

    private function resolveDueMonth(string $text): ?int
    {
        if (preg_match('/data\s+de\s+vencimento\s*:?\s*\d{1,2}\s+([A-Z]{3})\s+20\d{2}/iu', $text, $m)) {
            return $this->monthToNumber($m[1]);
        }

        if (preg_match('/\bFATURA\s+\d{1,2}\s+([A-Z]{3})\s+20\d{2}\b/iu', $text, $m)) {
            return $this->monthToNumber($m[1]);
        }

        return null;
    }

    private function buildDate(int $year, int $month, int $day, ?int $dueMonth): string
    {
        if ($dueMonth !== null && $month > $dueMonth) {
            $year--;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function matchDayMonth(string $line): ?array
    {
        if (!preg_match('/^(?<dia>\d{2})\s+(?<mes>[A-Z]{3})$/iu', $line, $m)) {
            return null;
        }

        $month = $this->monthToNumber($m['mes']);
        if (!$month) {
            return null;
        }

        return [(int) $m['dia'], $month];
    }

    private function isCardMask(string $line): bool
    {
        return (bool) preg_match('/^[•*\.]{2,}\s*\d{4}$/u', $line);
    }

    private function isMoneyLine(string $line): bool
    {
        return (bool) preg_match('/^[-−]?\s*R\$\s*-?\s*\d{1,3}(?:\.\d{3})*,\d{2}$/u', $line)
            || (bool) preg_match('/^[-−]\s*\d{1,3}(?:\.\d{3})*,\d{2}$/u', $line);
    }

    private function parseMoneyLine(string $line): float
    {
        return $this->parseMoney(str_replace('−', '-', $line));
    }

    private function isNoiseLine(string $line): bool
    {
        $normalized = mb_strtolower($line);

        if ($line === '' || preg_match('/^\d+\s+de\s+\d+$/u', $normalized)) {
            return true;
        }

        return (bool) preg_match(
            '/^(emiss[aã]o e envio|de \d{2}\s+[a-z]{3}\s+a\s+\d{2}|pagamentos|transa[cç][oõ]es|total de compras|como assegurado|em cumprimento|esta[aá]o elas|financiamentos|estorno referente)/iu',
            $normalized
        );
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array<int, array<string, mixed>>
     */
    private function dedupeConsecutivePayments(array $transactions): array
    {
        $result = [];
        foreach ($transactions as $tx) {
            $prev = end($result);
            if (
                $prev !== false
                && ($prev['tipo'] ?? null) === 'payment'
                && ($tx['tipo'] ?? null) === 'payment'
                && ($prev['data'] ?? null) === ($tx['data'] ?? null)
                && abs((float) $prev['valor'] - (float) $tx['valor']) < 0.01
            ) {
                continue;
            }
            $result[] = $tx;
        }

        return $result;
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
