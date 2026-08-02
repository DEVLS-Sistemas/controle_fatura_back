<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Sofisa Direto (Visa/Mastercard).
 *
 * Linha típica (após normalizar espaços):
 *   08/01/26 SHOPEE*SHOPEE*MA Parc.5/10 427,95
 *   11/05/26 Pagamento de Fatura -2.737,46
 *   11/05/26 Compra a Vista CLARO FLEX 39,99
 */
class SofisaInvoiceParser extends AbstractInvoiceParser
{
    public function name(): string
    {
        return 'sofisa';
    }

    public function supports(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'sofisa direto')
            || str_contains($normalized, 'banco sofisa')
            || (
                str_contains($normalized, 'sofisa')
                && str_contains($normalized, 'detalhamento da fatura')
            );
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $inSection = false;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^detalhamento da fatura\b/iu', $line)) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            if (preg_match('/^valor total da fatura\b/iu', $line)) {
                break;
            }

            if (preg_match(
                '/^(data\s+descricao|saldo total consolidado|demais encargos|informa[cç][oõ]es importantes)/iu',
                $line
            )) {
                continue;
            }

            // Cabeçalho de cartão / titular (ex.: 4563**.******.0236)
            if (preg_match('/^\d{4}\*{2}\./u', $line) || preg_match('/^[A-ZÁÉÍÓÚÃÕÂÊÔÇ][A-ZÁÉÍÓÚÃÕÂÊÔÇ\s.]{2,}$/u', $line)) {
                continue;
            }

            if (!preg_match(
                '/^(?<data>\d{2}\/\d{2}\/\d{2})\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
                $line,
                $m
            )) {
                continue;
            }

            $date = $this->parseDate($m['data']);
            $resto = $this->normalizeEstablishment(trim($m['resto']));
            $valor = $this->parseMoney($m['valor']);

            if ($resto === '') {
                continue;
            }

            [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
            $transactions[] = $this->makeTransaction($date, $resto, $valor, $parcelaAtual, $parcelasTotal);
        }

        return $transactions;
    }

    private function normalizeEstablishment(string $name): string
    {
        // Prefixo operacional do Sofisa, não faz parte do estabelecimento.
        $name = preg_replace('/^compra\s+a\s+vista\s+/iu', '', $name) ?? $name;

        return trim($name);
    }
}
