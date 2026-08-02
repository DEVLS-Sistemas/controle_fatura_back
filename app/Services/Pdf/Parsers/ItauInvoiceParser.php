<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Itaú (PDF layout em duas colunas).
 *
 * Coluna esquerda: pagamentos e lançamentos (compras/saques).
 * Coluna direita: encargos (na área de pagamentos) e textos informativos.
 *
 * Exemplos (após recorte da coluna esquerda):
 *   17/06 PAGAMENTO -1.200,00
 *   28/11 PERNAMBUCO MOT 08/10 1.200,00
 */
class ItauInvoiceParser extends AbstractInvoiceParser
{
    private const COLUMN_SPLIT = 85;

    public function name(): string
    {
        return 'itau';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        // Evita falso positivo em lançamentos como "BOLETO CRED PARC ITAU".
        return str_contains($normalized, 'banco itaú')
            || str_contains($normalized, 'banco itau')
            || str_contains($normalized, 'itaú unibanco')
            || str_contains($normalized, 'itau unibanco')
            || (str_contains($normalized, 'itaú') && str_contains($normalized, 'fatura de'));
    }

    public function parse(string $text): array
    {
        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $section = null; // payments | purchases
        $lastPurchaseIndex = null;

        foreach ($this->rawLines($text) as $rawLine) {
            $collapsed = $this->collapseSpaces($rawLine);

            if (preg_match('/^pagamentos efetuados\b/iu', $collapsed)) {
                $section = 'payments';
                continue;
            }

            if (preg_match('/^lan[cç]amentos:\s*compras/iu', $collapsed)) {
                $section = 'purchases';
                $lastPurchaseIndex = null;
                continue;
            }

            // Parcelas futuras e limites — fora do ciclo atual.
            if (preg_match('/^(compras parceladas|limites de cr[eé]dito)\b/iu', $collapsed)) {
                $section = null;
                $lastPurchaseIndex = null;
                continue;
            }

            if ($section === null) {
                continue;
            }

            $left = $this->collapseSpaces(mb_substr($rawLine, 0, self::COLUMN_SPLIT));
            $right = mb_strlen($rawLine) > self::COLUMN_SPLIT
                ? $this->collapseSpaces(mb_substr($rawLine, self::COLUMN_SPLIT))
                : '';

            if ($section === 'payments') {
                $payment = $this->parseDatedLine($left, $closingMonth, $closingYear);
                if ($payment !== null && !$this->isNoiseLabel($payment['estabelecimento'])) {
                    $transactions[] = $this->makeTransaction(
                        $payment['data'],
                        $payment['estabelecimento'],
                        $payment['valor']
                    );
                }

                $charge = $this->parseChargeLine($right);
                if ($charge !== null) {
                    $transactions[] = $this->makeTransaction(
                        null,
                        $charge['estabelecimento'],
                        $charge['valor']
                    );
                }

                continue;
            }

            // purchases
            if ($left === '') {
                continue;
            }

            $purchase = $this->parseDatedLine($left, $closingMonth, $closingYear);
            if ($purchase !== null) {
                if ($this->isNoiseLabel($purchase['estabelecimento'])) {
                    continue;
                }

                $transactions[] = $this->makeTransaction(
                    $purchase['data'],
                    $purchase['estabelecimento'],
                    $purchase['valor']
                );
                $lastPurchaseIndex = array_key_last($transactions);
                continue;
            }

            // Continuação do nome do estabelecimento na linha seguinte.
            if (
                $lastPurchaseIndex !== null
                && !preg_match('/^\d{2}\/\d{2}/', $left)
                && !preg_match('/\b\d{1,3}(?:\.\d{3})*,\d{2}$/u', $left)
                && !$this->isNoiseLabel($left)
            ) {
                $current = $transactions[$lastPurchaseIndex]['estabelecimento'];
                $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current . ' ' . $left);
            }
        }

        return $transactions;
    }

    /**
     * @return array{data: string|null, estabelecimento: string, valor: float}|null
     */
    private function parseDatedLine(string $line, int $closingMonth, int $closingYear): ?array
    {
        if (!preg_match(
            '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
            $line,
            $m
        )) {
            return null;
        }

        $resto = trim($m['resto']);
        if ($resto === '') {
            return null;
        }

        return [
            'data' => $this->resolveTransactionDate($m['data'], $closingMonth, $closingYear),
            'estabelecimento' => $resto,
            'valor' => $this->parseMoney($m['valor']),
        ];
    }

    /**
     * @return array{estabelecimento: string, valor: float}|null
     */
    private function parseChargeLine(string $line): ?array
    {
        if ($line === '' || preg_match('/\btotal\b/iu', $line)) {
            return null;
        }

        // Encargos tipicamente terminam com o valor em R$ (último money da linha).
        if (!preg_match(
            '/^(?<nome>.+?)\s+(?<valor>\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
            $line,
            $m
        )) {
            return null;
        }

        $nome = trim($m['nome']);
        // Remove taxas residuais: "(0,38 % + ...)", "15,10 %", "1,00 % am"
        $nome = trim(preg_replace('/\s*\(.*$/u', '', $nome) ?? $nome);
        $nome = trim(preg_replace('/\s+\d{1,3}(?:,\d+)?\s*%.*$/u', '', $nome) ?? $nome);

        if ($nome === '' || !$this->looksLikeChargeName($nome)) {
            return null;
        }

        $valor = $this->parseMoney($m['valor']);
        if ($valor <= 0) {
            return null;
        }

        return [
            'estabelecimento' => $nome,
            'valor' => $valor,
        ];
    }

    private function looksLikeChargeName(string $name): bool
    {
        $text = mb_strtolower($name);

        return str_contains($text, 'juros')
            || str_contains($text, 'multa')
            || str_contains($text, 'iof')
            || str_contains($text, 'encargo')
            || str_contains($text, 'mora')
            || str_contains($text, 'rotativo')
            || str_contains($text, 'financiamento');
    }

    private function isNoiseLabel(string $text): bool
    {
        return (bool) preg_match(
            '/^(data\b|p\s+total|l\s+total|e\s+total|lan[cç]amentos no cart|total dos|valor em r\$)/iu',
            $text
        );
    }

    /**
     * @return array{0: int, 1: int} mês e ano do fechamento/emissão
     */
    private function resolveClosingPeriod(string $text): array
    {
        // Preferir emissão/postagem (fechamento do ciclo).
        foreach ([
            '/emiss[aã]o:\s*(\d{2})\/(\d{2})\/(20\d{2})/iu',
            '/postagem:\s*(\d{2})\/(\d{2})\/(20\d{2})/iu',
            '/vencimento:\s*(\d{2})\/(\d{2})\/(20\d{2})/iu',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return [(int) $m[2], (int) $m[3]];
            }
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return [(int) date('n'), (int) $m[1]];
        }

        return [(int) date('n'), (int) date('Y')];
    }

    private function resolveTransactionDate(string $ddMm, int $closingMonth, int $closingYear): ?string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $ddMm, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (!preg_match('/^(\d{2})\/(\d{2})$/', $ddMm, $m)) {
            return $this->parseDate($ddMm, $closingYear);
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = $month > $closingMonth ? $closingYear - 1 : $closingYear;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return array<int, string>
     */
    private function rawLines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", $normalized);
    }

    private function collapseSpaces(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }
}
