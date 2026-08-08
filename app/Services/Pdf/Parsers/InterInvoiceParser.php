<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Banco Inter (PDF layout atual).
 *
 * Cabeçalho por cartão (pode haver vários na mesma fatura):
 *   Despesas da fatura
 *   CARTÃO 5364****1668   ← final do cartão (1668)
 *
 * Linha típica (após normalizar espaços):
 *   02 de jul. 2026 RI HAPPY (Parcela 01 de 06) - R$ 193,19
 *   12 de jun. 2026 PAGTO DEBITO AUTOMATICO - + R$ 5.956,84
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
            || str_contains($normalized, 'conta do inter')
            || str_contains($normalized, 'inter pagamentos')
            || str_contains($normalized, 'clientes inter')
            || (
                str_contains($normalized, 'despesas da fatura')
                && (str_contains($normalized, 'inter digital') || str_contains($normalized, 'inter one'))
            );
    }

    /**
     * @return array{mes: int, ano: int}|null
     */
    public function extractPeriod(string $text): ?array
    {
        // Inter: competência ≈ mês da compra mais recente no extrato.
        if (preg_match_all(
            '/\b(\d{1,2})\s+de\s+([a-zç]{3})\.?\s+(20\d{2})\b/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            $best = null;
            foreach ($matches as $m) {
                $mes = $this->monthToNumber($m[2]);
                if ($mes === null) {
                    continue;
                }
                $ano = (int) $m[3];
                $key = ($ano * 100) + $mes;
                if ($best === null || $key > $best['key']) {
                    $best = ['mes' => $mes, 'ano' => $ano, 'key' => $key];
                }
            }

            if ($best !== null) {
                return ['mes' => $best['mes'], 'ano' => $best['ano']];
            }
        }

        return parent::extractPeriod($text);
    }

    public function parse(string $text): array
    {
        $transactions = [];
        $inSection = false;
        $currentUltimosDigitos = null;

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^despesas da fatura\b/iu', $line)) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            if (preg_match('/^(em cumprimento|como assegurado|autentica)/iu', $line)) {
                break;
            }

            $cardDigits = $this->matchCartaoUltimosDigitos($line);
            if ($cardDigits !== null) {
                $currentUltimosDigitos = $cardDigits;
                continue;
            }

            // Totais de cartão / cabeçalhos
            if (preg_match('/^(total\s+cart|data\s+moviment)/iu', $line)) {
                continue;
            }

            $extras = $currentUltimosDigitos !== null
                ? ['ultimos_digitos' => $currentUltimosDigitos]
                : [];

            if (!preg_match(
                '/^(?<dia>\d{1,2})\s+de\s+(?<mes>[a-zç]{3})\.?\s+(?<ano>20\d{2})\s+(?<resto>.+?)\s+(?<credito>\+)?\s*R\$\s*(?<valor>\d{1,3}(?:\.\d{3})*,\d{2})$/iu',
                $line,
                $m
            )) {
                // Fallback legado: DD/MM DESCRICAO VALOR
                if (preg_match(
                    '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2})$/u',
                    $line,
                    $legacy
                )) {
                    $year = (int) date('Y');
                    if (preg_match('/\b(20\d{2})\b/', $text, $ym)) {
                        $year = (int) $ym[1];
                    }
                    $date = $this->parseDate($legacy['data'], $year);
                    $resto = trim($legacy['resto']);
                    $valor = $this->parseMoney($legacy['valor']);
                    [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
                    $transactions[] = $this->makeTransaction(
                        $date,
                        $resto,
                        $valor,
                        $parcelaAtual,
                        $parcelasTotal,
                        null,
                        $extras
                    );
                }
                continue;
            }

            $mes = $this->monthToNumber($m['mes']);
            if (!$mes) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', (int) $m['ano'], $mes, (int) $m['dia']);
            $resto = trim($m['resto']);
            $resto = trim(preg_replace('/\s+-\s*$/', '', $resto) ?? $resto);
            // Remove coluna "Beneficiário" residual.
            $resto = trim(preg_replace('/\s+-\s+/', ' ', $resto) ?? $resto);
            // "PIX ... (Parcela 04 de 04) EIA MARIA DA S" → mantém só a movimentação.
            if (preg_match('/^(.*\(Parcela\s+\d{1,2}\s+de\s+\d{1,2}\))/iu', $resto, $cut)) {
                $resto = trim($cut[1]);
            }

            $valor = $this->parseMoney($m['valor']);
            $isCredit = ($m['credito'] ?? '') === '+';
            $tipo = $this->resolveTipo($resto, $isCredit, $valor);

            // Créditos entram como valor positivo com tipo payment/refund.
            if ($isCredit && $tipo === 'purchase') {
                $tipo = 'refund';
            }

            [$parcelaAtual, $parcelasTotal] = $this->parseInstallment($resto);
            $transactions[] = $this->makeTransaction(
                $date,
                $resto,
                $isCredit ? -$valor : $valor,
                $parcelaAtual,
                $parcelasTotal,
                $tipo,
                $extras
            );
        }

        return $transactions;
    }

    /**
     * "CARTÃO 5364****1668" → "1668"
     */
    private function matchCartaoUltimosDigitos(string $line): ?string
    {
        if (!preg_match('/^cart[aã]o\s+\d{4}\*{2,}(\d{4})\b/iu', $line, $m)) {
            return null;
        }

        return $m[1];
    }

    private function resolveTipo(string $establishment, bool $isCredit, float $amount): string
    {
        $text = mb_strtolower($establishment);
        $looksLikePaymentName = str_contains($text, 'pagto')
            || str_contains($text, 'pagamento')
            || preg_match('/\bpgto\b/u', $text)
            || str_contains($text, 'debito automatico')
            || str_contains($text, 'débito automático');

        // No Inter, quitação da fatura anterior vem como crédito (+ R$).
        // Débito com nome "PGTO BOLETO PARCEL" etc. é compra (boleto no cartão).
        if ($isCredit) {
            return $looksLikePaymentName ? 'payment' : 'refund';
        }

        if ($looksLikePaymentName) {
            return 'purchase';
        }

        return $this->detectType($establishment, $amount);
    }

    private function monthToNumber(string $month): ?int
    {
        $map = [
            'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4,
            'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
            'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
        ];

        $key = mb_strtolower(substr($month, 0, 3));

        return $map[$key] ?? null;
    }
}
