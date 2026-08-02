<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas PicPay Card (PDF layout em duas colunas).
 *
 * Linhas típicas (após normalizar espaços):
 *   08/04 INVESTGAS LOCACAO E IN 45,99 11/11 MP *EDIFIER PARC06/10 20,81
 *   11/04 ATACADAO 152 APARC01/03 359,83
 *   19/04 PETALA PERFUMEPARC01/02 21,45
 */
class PicPayInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'picpay';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'picpay bank')
            || str_contains($normalized, 'picpay card')
            || (
                str_contains($normalized, 'picpay')
                && (str_contains($normalized, 'transações nacionais') || str_contains($normalized, 'transacoes nacionais'))
            );
    }

    public function parse(string $text): array
    {
        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (
                preg_match('/^picpay\s+card\b/iu', $line)
                || preg_match('/^transa[cç][oõ]es\s+nacionais\b/iu', $line)
            ) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            if (preg_match('/^(valores em r\$|encargos|saiba quais|limite dispon)/iu', $line)) {
                break;
            }

            if (preg_match('/^(data\s+estabelecimento|subtotal|total\s+geral|p[aá]gina\b)/iu', $line)) {
                continue;
            }

            foreach ($this->extractTransactionsFromLine($line) as $item) {
                $date = $this->resolveTransactionDate($item['data'], $closingMonth, $closingYear);
                $resto = $item['estabelecimento'];
                $valor = $this->parseMoney($item['valor']);

                // Ignora totais/resumo que escaparem do filtro de linha.
                if (preg_match('/^(subtotal|total)\b/iu', $resto)) {
                    continue;
                }

                [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
                $transactions[] = $this->makeTransaction(
                    $date,
                    $resto,
                    $valor,
                    $parcelaAtual,
                    $parcelasTotal
                );
            }
        }

        return $transactions;
    }

    /**
     * Extrai 1+ lançamentos de uma linha (layout 1 ou 2 colunas).
     *
     * @return array<int, array{data: string, estabelecimento: string, valor: string}>
     */
    private function extractTransactionsFromLine(string $line): array
    {
        // Lookahead evita cortar em PARC01/02 colado no nome; só fecha no
        // próximo lançamento (DD/MM) ou fim/resumo da linha.
        if (!preg_match_all(
            '/(?P<data>\d{2}\/\d{2})\s+(?P<estab>.+?)\s+(?P<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})(?=\s+\d{2}\/\d{2}\b|\s+Data\b|\s+Subtotal\b|\s+Total\b|\s*$)/u',
            $line,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $items = [];
        foreach ($matches as $m) {
            $estab = trim($m['estab']);
            if ($estab === '' || preg_match('/^data\b/iu', $estab)) {
                continue;
            }

            $items[] = [
                'data' => $m['data'],
                'estabelecimento' => $estab,
                'valor' => $m['valor'],
            ];
        }

        return $items;
    }

    /**
     * @return array{0: int, 1: int} mês e ano do fechamento
     */
    private function resolveClosingPeriod(string $text): array
    {
        if (preg_match('/Fechamento:\s*(\d{2})[-\/](\d{2})[-\/](20\d{2})/iu', $text, $m)) {
            return [(int) $m[2], (int) $m[3]];
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return [(int) date('n'), (int) $m[1]];
        }

        return [(int) date('n'), (int) date('Y')];
    }

    private function resolveTransactionDate(string $ddMm, int $closingMonth, int $closingYear): ?string
    {
        if (!preg_match('/^(\d{2})\/(\d{2})$/', $ddMm, $m)) {
            return $this->parseDate($ddMm, $closingYear);
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = $month > $closingMonth ? $closingYear - 1 : $closingYear;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
