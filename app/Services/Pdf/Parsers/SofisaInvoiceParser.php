<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Sofisa Direto (Visa/Mastercard).
 *
 * Visa (após normalizar espaços):
 *   LEONARDO S FERREIRA
 *   4563**.******.0236
 *   08/01/26 SHOPEE*SHOPEE*MA Parc.5/10 427,95
 *
 * Mastercard (data sem ano; parcela colada no nome):
 *   Despesas Cartão - 0217
 *   15/11 ComercialDe 10/12 13,54
 *   06/12 CASA MAE CAMARAGIB09/10 148,50
 *   10/08 PAGAMENTO DE FATURA -167,84
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
        // "Vencimento: 10/06/2026" (Visa) ou rótulo e data em linhas vizinhas (Mastercard).
        if (preg_match('/vencimento:\s*(\d{2})\/(\d{2})\/(20\d{2})/iu', $text, $m)) {
            return ['mes' => (int) $m[2], 'ano' => (int) $m[3]];
        }

        if (preg_match('/vencimento\b[\s\S]{0,160}?(\d{2})\/(\d{2})\/(20\d{2})/iu', $text, $m)) {
            return ['mes' => (int) $m[2], 'ano' => (int) $m[3]];
        }

        // "feitos até 05/06/2026" — a data pode cair na linha seguinte no -layout.
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
        $period = $this->extractPeriod($text);

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^nome do titular\s+(.+)$/iu', $line, $holder)) {
                $pendingNomeNoCartao = trim($holder[1]);
                continue;
            }

            if (preg_match('/^detalhamento da fatura\b/iu', $line)
                || preg_match('/^lan[cç]amentos da fatura\b/iu', $line)
            ) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            if ($this->isEndOfLancamentos($line)) {
                break;
            }

            if (preg_match(
                '/^(data\s+descricao|data\s+transa|saldo total consolidado|demais encargos|informa[cç][oõ]es importantes)/iu',
                $line
            )) {
                continue;
            }

            $cardFromDespesas = $this->matchDespesasCartao($line);
            if ($cardFromDespesas !== null) {
                $currentUltimosDigitos = $cardFromDespesas;
                $currentNomeNoCartao = $pendingNomeNoCartao;
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

            $parsedLine = $this->parseTransactionLine($line, $period);
            if ($parsedLine === null) {
                continue;
            }

            $transactions[] = $this->makeTransaction(
                $parsedLine['data'],
                $parsedLine['estabelecimento'],
                $parsedLine['valor'],
                $parsedLine['parcela_atual'],
                $parsedLine['parcelas_total'],
                null,
                $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao)
            );
        }

        return $transactions;
    }

    /**
     * @param  array{mes: int, ano: int}|null  $period
     * @return array{
     *     data: ?string,
     *     estabelecimento: string,
     *     valor: float,
     *     parcela_atual: ?int,
     *     parcelas_total: ?int
     * }|null
     */
    private function parseTransactionLine(string $line, ?array $period = null): ?array
    {
        if (! preg_match(
            '/^(?<data>\d{2}\/\d{2}(?:\/(?:\d{4}|\d{2}))?)\s+(?<resto>.+)$/u',
            $line,
            $m
        )) {
            return null;
        }

        if (! preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}/', $m['resto'], $valores) || $valores[0] === []) {
            return null;
        }

        $valorStr = $valores[0][count($valores[0]) - 1];
        $resto = trim((string) preg_replace('/'.preg_quote($valorStr, '/').'\s*$/u', '', $m['resto']));
        $resto = trim((string) preg_replace('/\s+(?:-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})/', '', $resto));
        $resto = $this->normalizeEstablishment($resto);
        if ($resto === '') {
            return null;
        }

        [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
        $defaultYear = preg_match('/^\d{2}\/\d{2}$/', $m['data']) === 1
            ? $this->yearForShortDate($m['data'], $period)
            : null;

        return [
            'data' => $this->parseDate($m['data'], $defaultYear),
            'estabelecimento' => $resto,
            'valor' => $this->parseMoney($valorStr),
            'parcela_atual' => $parcelaAtual,
            'parcelas_total' => $parcelasTotal,
        ];
    }

    /**
     * dd/mm sem ano: mês depois do vencimento/fechamento pertence ao ano anterior.
     *
     * @param  array{mes: int, ano: int}|null  $period
     */
    private function yearForShortDate(string $date, ?array $period): int
    {
        $ano = $period['ano'] ?? (int) date('Y');
        $mesRef = $period['mes'] ?? (int) date('n');
        if ($mesRef < 1 || $mesRef > 12) {
            $mesRef = (int) date('n');
        }

        if (! preg_match('/^\d{2}\/(\d{2})$/', $date, $m)) {
            return $ano;
        }

        $txMonth = (int) $m[1];
        if ($txMonth < 1 || $txMonth > 12) {
            return $ano;
        }

        return $txMonth > $mesRef ? $ano - 1 : $ano;
    }

    private function isEndOfLancamentos(string $line): bool
    {
        return (bool) preg_match(
            '/^(valor total da fatura\b|aten[cç][aã]o\b|recibo do pagador|benefici[aá]rio\b|local de pagamento|parcelamento da fatura:|encargos:|iof:|consulta do seu contrato|perda ou roubo)/iu',
            $line
        );
    }

    /**
     * "Despesas Cartão - 0217" → "0217"
     */
    private function matchDespesasCartao(string $line): ?string
    {
        if (! preg_match('/^despesas\s+cart[aã]o\s*[-–—]\s*(\d{4})\b/iu', $line, $m)) {
            return null;
        }

        return $m[1];
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
        // Mastercard cola a parcela no fim do nome: CAMARAGIB09/10
        $name = preg_replace('/(?<=\p{L})(\d{1,2}\/\d{1,2})\b/u', ' $1', $name) ?? $name;

        return trim($name);
    }
}
