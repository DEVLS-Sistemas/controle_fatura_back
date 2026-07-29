<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser específico para faturas Itaú.
 */
class ItauInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'itau';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'itaú')
            || str_contains($normalized, 'itau')
            || str_contains($normalized, 'banco itau');
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $year = (int) date('Y');

        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            $year = (int) $m[1];
        }

        foreach ($this->lines($text) as $line) {
            // Ex: 12/03 15:42 LOJA XYZ PARC 02/10 250,00
            if (!preg_match('/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)(?:\s+\d{2}:\d{2})?\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u', $line, $m)) {
                continue;
            }

            $date = $this->parseDate($m['data'], $year);
            $resto = trim($m['resto']);
            $valor = $this->parseMoney($m['valor']);

            [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
            $resto = trim(preg_replace('/\bPARC(?:ELA)?\s*\d{1,2}\s*\/\s*\d{1,2}\b/i', '', $resto) ?? $resto);
            $resto = trim(preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $resto) ?? $resto);

            if ($resto === '') {
                continue;
            }

            $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
        }

        return $transactions;
    }
}
