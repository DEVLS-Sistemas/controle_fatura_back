<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Sofisa Direto (Visa/Mastercard).
 *
 * Linha típica (após normalizar espaços):
 *   LEONARDO S FERREIRA
 *   4563**.******.0236
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

    /**
     * @return array{mes: int, ano: int}|null
     */
    public function extractPeriod(string $text): ?array
    {
        // "Vencimento: 10/06/2026" ou "feitos até 05/06/2026"
        if (preg_match('/vencimento:\s*(\d{2})\/(\d{2})\/(20\d{2})/iu', $text, $m)) {
            return ['mes' => (int) $m[2], 'ano' => (int) $m[3]];
        }

        if (preg_match('/feitos?\s+at[eé]\s+(\d{2})\/(\d{2})\/(20\d{2})/iu', $text, $m)) {
            return ['mes' => (int) $m[2], 'ano' => (int) $m[3]];
        }

        return parent::extractPeriod($text);
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $inSection = false;
        $currentUltimosDigitos = null;
        $currentNomeNoCartao = null;
        $pendingNomeNoCartao = null;

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

            if ($this->isHolderNameLine($line)) {
                $pendingNomeNoCartao = $line;
                continue;
            }

            // Cabeçalho de cartão (ex.: 4563**.******.0236) → últimos 4 dígitos
            $cardDigits = $this->matchCartaoUltimosDigitos($line);
            if ($cardDigits !== null) {
                $currentUltimosDigitos = $cardDigits;
                $currentNomeNoCartao = $pendingNomeNoCartao;
                $pendingNomeNoCartao = null;
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
            $transactions[] = $this->makeTransaction(
                $date,
                $resto,
                $valor,
                $parcelaAtual,
                $parcelasTotal,
                null,
                $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao)
            );
        }

        return $transactions;
    }

    /**
     * "4563**.******.0236" → "0236"
     */
    private function matchCartaoUltimosDigitos(string $line): ?string
    {
        if (!preg_match('/^\d{4}\*{2}\.?\*{4,}\.?(\d{4})\b/u', $line, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Nome impresso no cartão (ex.: LEONARDO S FERREIRA), tipicamente acima da máscara.
     */
    private function isHolderNameLine(string $line): bool
    {
        if ($line === '' || mb_strlen($line) < 3 || mb_strlen($line) > 60) {
            return false;
        }

        if (preg_match('/\d/', $line)) {
            return false;
        }

        if (preg_match(
            '/^(data\b|descricao|saldo|demais|informa|valor|total|pagamentos?|compras?|sofisa|mastercard|visa|elo|amex)/iu',
            $line
        )) {
            return false;
        }

        return (bool) preg_match(
            '/^[A-ZÁÉÍÓÚÃÕÂÊÔÇ][A-ZÁÉÍÓÚÃÕÂÊÔÇ\s.]{2,}$/u',
            $line
        );
    }

    /**
     * @return array{ultimos_digitos?: string, nome_no_cartao?: string}
     */
    private function cardExtras(?string $ultimosDigitos, ?string $nomeNoCartao): array
    {
        $extras = [];
        if ($ultimosDigitos !== null) {
            $extras['ultimos_digitos'] = $ultimosDigitos;
            if ($nomeNoCartao !== null) {
                $extras['nome_no_cartao'] = $nomeNoCartao;
            }
        }

        return $extras;
    }

    private function normalizeEstablishment(string $name): string
    {
        // Prefixo operacional do Sofisa, não faz parte do estabelecimento.
        $name = preg_replace('/^compra\s+a\s+vista\s+/iu', '', $name) ?? $name;

        return trim($name);
    }
}
