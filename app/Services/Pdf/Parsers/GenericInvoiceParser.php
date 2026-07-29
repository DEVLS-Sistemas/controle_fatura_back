<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser genérico para faturas brasileiras.
 * Cobertura ampla de padrões comuns: "DD/MM DESCRICAO VALOR" e parcelas.
 */
class GenericInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'generico';
    }

    public function supports(string $text): bool
    {
        return true;
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $year = $this->detectYear($text);

        foreach ($this->lines($text) as $line) {
            $parsed = $this->parseLine($line, $year);
            if ($parsed) {
                $transactions[] = $parsed;
            }
        }

        return $transactions;
    }

    protected function detectYear(string $text): int
    {
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return (int) $m[1];
        }

        return (int) date('Y');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseLine(string $line, int $year): ?array
    {
        // Ex: 15/03 SUPERMERCADO XYZ 1.234,56
        // Ex: 15/03/2026 LOJA ABC 03/12 150,00
        // Ex: 15/03 PAGAMENTO RECEBIDO -500,00
        $pattern = '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}|-?\d+\.\d{2})$/u';

        if (!preg_match($pattern, $line, $m)) {
            return null;
        }

        $date = $this->parseDate($m['data'], $year);
        $resto = trim($m['resto']);
        $valor = $this->parseMoney($m['valor']);

        [$parcelaAtual, $parcelasTotal] = [null, null];

        if (preg_match('/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/', $resto, $parcelaMatch)) {
            $parcelaAtual = (int) $parcelaMatch[1];
            $parcelasTotal = (int) $parcelaMatch[2];
            $resto = trim(str_replace($parcelaMatch[0], '', $resto));
        }

        if ($resto === '') {
            return null;
        }

        return $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
    }
}
