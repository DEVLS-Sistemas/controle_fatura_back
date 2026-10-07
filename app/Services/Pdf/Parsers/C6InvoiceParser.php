<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas C6 Bank (PDF layout atual).
 *
 * Cabeçalho por cartão (vários na mesma fatura):
 *   Cartão C6 Final 0264 - LEONARDO S FERREIRA
 *   Cartão C6 Virtual Final 2399 - LEONARDO S Cartão Virtual
 *
 * Linha típica (após normalizar espaços), na seção "Transações do cartão":
 *   10 jun Inclusao de Pagamento 157,92
 *   06 nov AMAZON RETAIL CPI - Parcela 8/12 68,03
 *   11 jun CLARO FLEX 59,99
 *
 * Fechamento (para ano): "fechamento desta fatura em 03/07/26"
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
            || str_contains($normalized, 'banco c6')
            || (
                str_contains($normalized, 'cartão c6')
                && str_contains($normalized, 'transações do cartão')
            )
            || (
                str_contains($normalized, 'cartao c6')
                && str_contains($normalized, 'transacoes do cartao')
            );
    }

    public function parse(string $text): array
    {
        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $inSection = false;
        $currentUltimosDigitos = null;
        $currentNomeNoCartao = null;
        $currentTipoNumero = null;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^transa[cç][oõ]es do cart[aã]o\b/iu', $line)) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            // Fim da seção de lançamentos (boleto / formas de pagamento).
            if (preg_match('/^(formas de pagamento|pague com pix|n[uú]mero do cart[aã]o)\b/iu', $line)) {
                break;
            }

            $cardHeader = $this->matchCartaoHeader($line);
            if ($cardHeader !== null) {
                $currentUltimosDigitos = $cardHeader['ultimos_digitos'];
                $currentNomeNoCartao = $cardHeader['nome_no_cartao'];
                $currentTipoNumero = $cardHeader['tipo_numero'];
                continue;
            }

            // Totais / legendas (sem final do cartão)
            if (preg_match(
                '/^(valores em reais|subtotal deste cart[aã]o|lembrando:)/iu',
                $line
            )) {
                continue;
            }

            if (!preg_match(
                '/^(?<dia>\d{1,2})\s+(?<mes>[a-zç]{3})\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/iu',
                $line,
                $m
            )) {
                continue;
            }

            $mes = $this->monthToNumber($m['mes']);
            if (!$mes) {
                continue;
            }

            $date = $this->resolveTransactionDate((int) $m['dia'], $mes, $closingMonth, $closingYear);
            $resto = trim($m['resto']);
            $valor = $this->parseMoney($m['valor']);

            if ($resto === '') {
                continue;
            }

            $transactions[] = $this->makeTransaction(
                $date,
                $resto,
                $valor,
                null,
                null,
                null,
                $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao, $currentTipoNumero)
            );
        }

        return $transactions;
    }

    /**
     * "Cartão C6 Final 0264 - LEONARDO S FERREIRA …"
     * "Cartão C6 Virtual Final 2399 - LEONARDO S Cartão Virtual …"
     *
     * @return array{ultimos_digitos: string, nome_no_cartao: ?string, tipo_numero: ?string}|null
     */
    private function matchCartaoHeader(string $line): ?array
    {
        if (!preg_match(
            '/^cart[aã]o\s+c6(?<virtual>\s+virtual)?\s+final\s+(?<digitos>\d{4})\s*[-–—]\s*(?<resto>.+)$/iu',
            $line,
            $m
        )) {
            return null;
        }

        $nome = trim($m['resto']);
        // Remove sufixos de layout (subtotal / rótulo "Cartão Virtual" após o nome).
        $nome = preg_replace('/\s+subtotal\b.*$/iu', '', $nome) ?? $nome;
        $nome = preg_replace('/\s+cart[aã]o\s+virtual\s*$/iu', '', $nome) ?? $nome;
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? $nome);
        if ($nome === '') {
            $nome = null;
        }

        $isVirtual = trim((string) ($m['virtual'] ?? '')) !== '';

        return [
            'ultimos_digitos' => $m['digitos'],
            'nome_no_cartao' => $nome,
            'tipo_numero' => $isVirtual ? 'virtual' : 'fisico',
        ];
    }

    /**
     * @return array{ultimos_digitos?: string, nome_no_cartao?: string, tipo_numero?: string}
     */
    private function cardExtras(
        ?string $ultimosDigitos,
        ?string $nomeNoCartao,
        ?string $tipoNumero
    ): array {
        $extras = [];
        if ($ultimosDigitos === null) {
            return $extras;
        }

        $extras['ultimos_digitos'] = $ultimosDigitos;
        if ($nomeNoCartao !== null) {
            $extras['nome_no_cartao'] = $nomeNoCartao;
        }
        if ($tipoNumero !== null) {
            $extras['tipo_numero'] = $tipoNumero;
        }

        return $extras;
    }

    /**
     * Competência = mês do vencimento. O fechamento (ex.: 29/08) encerra as
     * compras da fatura que vence no mês seguinte (05 de setembro → 09).
     *
     * @return array{mes: int, ano: int}|null
     */
    public function extractPeriod(string $text): ?array
    {
        $closing = $this->matchClosingDate($text);
        $due = $this->matchDueDate($text);
        if ($due !== null) {
            $period = $this->competenciaDoVencimento($due, $closing);
            if ($period !== null) {
                return $period;
            }
        }

        if ($closing !== null) {
            return $closing;
        }

        [$mes, $ano] = $this->resolveClosingPeriod($text);

        if ($mes < 1 || $mes > 12 || $ano < 2000) {
            return null;
        }

        return ['mes' => $mes, 'ano' => $ano];
    }

    /**
     * @return array{mes: int, ano: int}|null
     */
    private function matchClosingDate(string $text): ?array
    {
        if (preg_match(
            '/fechamento(?:\s+desta\s+fatura)?\s+em\s+(\d{2})\/(\d{2})\/(\d{2,4})/iu',
            $text,
            $m
        )) {
            return $this->periodFromParts((int) $m[2], (int) $m[3]);
        }

        if (preg_match(
            '/transa[cç][oõ]es feitas at[eé]\s+(\d{2})\/(\d{2})\/(\d{2,4})/iu',
            $text,
            $m
        )) {
            return $this->periodFromParts((int) $m[2], (int) $m[3]);
        }

        return null;
    }

    /**
     * @return array{mes: int, ano: ?int}|null
     */
    private function matchDueDate(string $text): ?array
    {
        if (preg_match(
            '/data\s+do\s+vencimento\s*:?\s*(\d{1,2})\s+de\s+([a-zà-ÿ]+)(?:\s+de\s+(\d{2,4}))?/iu',
            $text,
            $m
        )) {
            $mes = $this->monthToNumber($m[2]);
            if ($mes !== null) {
                $ano = isset($m[3]) && $m[3] !== '' ? $this->expandYear((int) $m[3]) : null;

                return ['mes' => $mes, 'ano' => $ano];
            }
        }

        if (preg_match(
            '/vencimento\s*:?\s*(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/iu',
            $text,
            $m
        )) {
            $mes = (int) $m[2];
            if ($mes >= 1 && $mes <= 12) {
                return ['mes' => $mes, 'ano' => $this->expandYear((int) $m[3])];
            }
        }

        if (preg_match(
            '/vencimento\s+em\s+([a-zà-ÿ]+)(?:\s+de\s+(20\d{2}))?/iu',
            $text,
            $m
        )) {
            $mes = $this->monthToNumber($m[1]);
            if ($mes !== null) {
                $ano = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

                return ['mes' => $mes, 'ano' => $ano];
            }
        }

        return null;
    }

    /**
     * @param  array{mes: int, ano: ?int}  $due
     * @param  array{mes: int, ano: int}|null  $closing
     * @return array{mes: int, ano: int}|null
     */
    private function competenciaDoVencimento(array $due, ?array $closing): ?array
    {
        $mes = $due['mes'];
        $ano = $due['ano'];
        if ($ano === null && $closing !== null) {
            $ano = $closing['ano'];
            if ($mes < $closing['mes']) {
                $ano++;
            }
        }

        if ($ano === null || $mes < 1 || $mes > 12 || $ano < 2000) {
            return null;
        }

        return ['mes' => $mes, 'ano' => $ano];
    }

    /**
     * @return array{mes: int, ano: int}|null
     */
    private function periodFromParts(int $month, int $yearOrYy): ?array
    {
        if ($month < 1 || $month > 12) {
            return null;
        }

        $year = $this->expandYear($yearOrYy);
        if ($year < 2000) {
            return null;
        }

        return ['mes' => $month, 'ano' => $year];
    }

    private function expandYear(int $yearOrYy): int
    {
        if ($yearOrYy >= 100) {
            return $yearOrYy;
        }

        return $yearOrYy >= 70 ? 1900 + $yearOrYy : 2000 + $yearOrYy;
    }

    /**
     * @return array{0: int, 1: int} mês e ano do fechamento
     */
    private function resolveClosingPeriod(string $text): array
    {
        $closing = $this->matchClosingDate($text);
        if ($closing !== null) {
            return [$closing['mes'], $closing['ano']];
        }

        // "Vencimento: 10/07/2026" — aproximação (mês do vencimento ≈ ciclo)
        if (preg_match('/vencimento:\s*\d{1,2}\/\d{2}\/(20\d{2})/iu', $text, $m)) {
            if (preg_match('/vencimento:\s*\d{1,2}\/(\d{2})\/20\d{2}/iu', $text, $vm)) {
                return [(int) $vm[1], (int) $m[1]];
            }
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return [(int) date('n'), (int) $m[1]];
        }

        return [(int) date('n'), (int) date('Y')];
    }

    private function resolveTransactionDate(int $day, int $month, int $closingMonth, int $closingYear): string
    {
        // Compra em mês posterior ao fechamento pertence ao ciclo anterior (ano - 1).
        $year = $month > $closingMonth ? $closingYear - 1 : $closingYear;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
