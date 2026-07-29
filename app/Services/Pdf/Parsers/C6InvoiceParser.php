<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser de exemplo para faturas C6 Bank.
 *
 * Adapte as regex conforme o layout real do PDF do C6.
 * Layout comum observado em amostras:
 *   "15/03 Compra no débito SUPERMERCADO 123,45"
 *   "15 MAR Estabelecimento 1/3 50,00"
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
            || (str_contains($normalized, 'c6') && str_contains($normalized, 'fatura'));
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $year = (int) date('Y');

        if (preg_match('/fatura\s+.*?(20\d{2})/iu', $text, $m)) {
            $year = (int) $m[1];
        }

        foreach ($this->lines($text) as $line) {
            if (preg_match(
                '/^(?<dia>\d{2})\s+(?<mes>[A-Z]{3})\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/iu',
                $line,
                $m
            )) {
                $mes = $this->monthToNumber($m['mes']);
                if (!$mes) {
                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $mes, (int) $m['dia']);
                $resto = trim($m['resto']);
                $valor = $this->parseMoney($m['valor']);
                [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);

                if ($parcelaAtual) {
                    $resto = trim(preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $resto) ?? $resto);
                }

                $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
                continue;
            }

            if (preg_match(
                '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
                $line,
                $m
            )) {
                $date = $this->parseDate($m['data'], $year);
                $resto = trim($m['resto']);
                $valor = $this->parseMoney($m['valor']);
                [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);

                if ($parcelaAtual) {
                    $resto = trim(preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $resto) ?? $resto);
                }

                $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
            }
        }

        return $transactions;
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
