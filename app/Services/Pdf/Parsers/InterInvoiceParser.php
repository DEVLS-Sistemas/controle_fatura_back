<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser de exemplo para faturas Banco Inter.
 *
 * Adapte as regex conforme o layout real do PDF do Inter.
 * Tipicamente as linhas de lançamento seguem:
 *   "15/03 SUPERMERCADO XYZ 01/03 123,45"
 *   ou "15/03/2026 DESCRICAO 123,45"
 */
class InterInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'inter';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'banco inter')
            || str_contains($normalized, 'inter pagamentos')
            || (str_contains($normalized, 'inter') && str_contains($normalized, 'fatura'));
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $year = (int) date('Y');

        if (preg_match('/(20\d{2})/', $text, $m)) {
            $year = (int) $m[1];
        }

        foreach ($this->lines($text) as $line) {
            // DD/MM DESCRICAO VALOR  |  DD/MM/YYYY DESCRICAO VALOR
            if (!preg_match(
                '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
                $line,
                $m
            )) {
                continue;
            }

            $date = $this->parseDate($m['data'], $year);
            $resto = trim($m['resto']);
            $valor = $this->parseMoney($m['valor']);
            [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);

            if ($parcelaAtual) {
                $resto = trim(preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $resto) ?? $resto);
            }

            $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
        }

        return $transactions;
    }
}
