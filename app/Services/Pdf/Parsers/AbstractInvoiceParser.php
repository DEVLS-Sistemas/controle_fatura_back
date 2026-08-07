<?php

namespace App\Services\Pdf\Parsers;

abstract class AbstractInvoiceParser implements InvoiceParserInterface
{
    /**
     * Converte valor monetário BR (1.234,56) ou US (1,234.56) para float.
     */
    protected function parseMoney(string $value): float
    {
        $value = trim($value);
        $value = str_replace(['R$', ' '], '', $value);
        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? $value;

        if (str_contains($value, ',') && str_contains($value, '.')) {
            // 1.234,56
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            // 1234,56
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    /**
     * Converte data DD/MM, DD/MM/YY ou DD/MM/YYYY para Y-m-d.
     */
    protected function parseDate(string $date, ?int $defaultYear = null): ?string
    {
        $date = trim($date);
        $year = $defaultYear ?? (int) date('Y');

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // Sofisa e similares: 08/01/26
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $date, $m)) {
            $yy = (int) $m[3];
            $fullYear = $yy >= 70 ? 1900 + $yy : 2000 + $yy;

            return sprintf('%04d-%02d-%02d', $fullYear, (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{2})\/(\d{2})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * Extrai parcela atual/total de textos como "03/12", "Parc 3/12", "3 de 12".
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function parseInstallment(?string $text): array
    {
        if (!$text) {
            return [null, null];
        }

        // "Parc.5/10", "Parc 3/12", "(Parcela 01 de 06)"
        if (preg_match('/\(?\s*PARC(?:ELA)?\.?\s*(\d{1,2})\s*(?:\/|de)\s*(\d{1,2})\s*\)?/iu', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('/\b(\d{1,2})\s+de\s+(\d{1,2})\b/i', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /**
     * Remove marcadores de parcela do nome do estabelecimento.
     * Ex.: "Atacado dos Presentes 1/3" → "Atacado dos Presentes"
     * Ex.: "RI HAPPY (Parcela 01 de 06)" → "RI HAPPY"
     *
     * A parcela fica só em parcela_atual / parcelas_total na transação.
     */
    protected function stripInstallmentFromName(string $name): string
    {
        $name = preg_replace('/\(?\s*PARC(?:ELA)?\.?\s*\d{1,2}\s*(?:\/|de)\s*\d{1,2}\s*\)?/iu', '', $name) ?? $name;
        $name = preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $name) ?? $name;
        $name = preg_replace('/\b\d{1,2}\s+de\s+\d{1,2}\b/iu', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        // "Mercadolivre - Parcela 2/10" → "Mercadolivre -" → "Mercadolivre"
        $name = trim(preg_replace('/\s*[-–—]\s*$/u', '', $name) ?? $name);

        return $name !== '' ? $name : 'Desconhecido';
    }

    /**
     * Infere o tipo da transação a partir do estabelecimento/descrição.
     *
     * Importante: valor negativo sozinho NÃO é pagamento. No Nubank, créditos
     * (variação cambial, descontos, estornos) vêm negativos e reduzem a fatura.
     * Só "Pagamento recebido" (e equivalentes) são payment.
     * Juros/multa/IOF/encargos são `fee` (operacionais), não compra.
     */
    protected function detectType(string $establishment, float $amount): string
    {
        $text = mb_strtolower($establishment);

        if (
            str_contains($text, 'pagamento recebido') ||
            str_contains($text, 'pagamento de fatura') ||
            preg_match('/\bpagto\b/u', $text) ||
            preg_match('/\bpagamento\b/u', $text)
        ) {
            return 'payment';
        }

        if (
            str_contains($text, 'estorno') ||
            str_contains($text, 'cancelamento') ||
            str_contains($text, 'devolucao') ||
            str_contains($text, 'devolução') ||
            str_contains($text, 'crédito de') ||
            str_contains($text, 'credito de') ||
            str_contains($text, 'desconto antecip') ||
            str_contains($text, 'desconto de antecip')
        ) {
            return 'refund';
        }

        if (
            str_contains($text, 'antecipacao') ||
            str_contains($text, 'antecipação') ||
            str_contains($text, 'antecip')
        ) {
            return 'advance';
        }

        if ($this->looksLikeFeeName($text)) {
            return 'fee';
        }

        // Crédito genérico (ex.: variação cambial negativa) reduz a fatura.
        if ($amount < 0) {
            return 'refund';
        }

        return 'purchase';
    }

    /**
     * Encargos da fatura (juros, multa, IOF, mora, rotativo, financiamento…).
     */
    protected function looksLikeFeeName(string $name): bool
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

    /**
     * Normaliza espaços e remove linhas vazias.
     *
     * @return array<int, string>
     */
    protected function lines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalized);

        return array_values(array_filter(array_map(static function ($line) {
            return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        }, $lines)));
    }

    /**
     * Monta o array padronizado de uma transação.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array{ultimos_digitos?: string|null, nome_no_cartao?: string|null, tipo_numero?: string|null}  $extras
     * @return array<string, mixed>
     */
    protected function makeTransaction(
        ?string $date,
        string $establishment,
        float $amount,
        ?int $parcelaAtual = null,
        ?int $parcelasTotal = null,
        ?string $tipo = null,
        array $extras = []
    ): array {
        $amountAbs = abs($amount);

        if ($parcelaAtual === null && $parcelasTotal === null) {
            [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($establishment);
        }

        // Parcela nunca faz parte do cadastro de estabelecimento.
        $establishmentClean = $this->stripInstallmentFromName($establishment);
        $tipoFinal = $tipo ?? $this->detectType($establishmentClean, $amount);

        $transaction = [
            'data' => $date,
            'estabelecimento' => $establishmentClean,
            'valor' => round($amountAbs, 2),
            'parcelas_total' => $parcelasTotal,
            'parcela_atual' => $parcelaAtual,
            'valor_parcela' => $parcelasTotal ? round($amountAbs, 2) : null,
            'tipo' => $tipoFinal,
        ];

        if (!empty($extras['ultimos_digitos'])) {
            $transaction['ultimos_digitos'] = (string) $extras['ultimos_digitos'];
        }

        if (!empty($extras['nome_no_cartao'])) {
            $transaction['nome_no_cartao'] = (string) $extras['nome_no_cartao'];
        }

        if (!empty($extras['tipo_numero'])
            && in_array($extras['tipo_numero'], ['fisico', 'virtual', 'adicional'], true)
        ) {
            $transaction['tipo_numero'] = (string) $extras['tipo_numero'];
        }

        return $transaction;
    }
}
