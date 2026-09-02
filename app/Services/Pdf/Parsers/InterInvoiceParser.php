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
 *
 * O PDF costuma trazer ao lado (ou abaixo) o quadro "Próxima fatura":
 * duas colunas (Movimentação / Valor), sem data de lançamento — é só um
 * resumo das parcelas seguintes. Não cadastrar essas linhas.
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
        $viuLayoutAtual = false;
        $currentUltimosDigitos = null;

        foreach ($this->lines($text) as $rawLine) {
            if (preg_match('/despesas da fatura\b/iu', $rawLine)) {
                $inSection = true;
            }

            // Quadro informativo (abaixo ou à direita). Não é despesa desta fatura.
            if ($this->isProximaFaturaSectionStart($rawLine)
                && !preg_match('/despesas da fatura\b/iu', $rawLine)
            ) {
                $inSection = false;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            $line = $this->stripProximaFaturaColumn($rawLine);
            $line = trim(preg_replace('/^despesas da fatura\s*/iu', '', $line) ?? $line);

            if ($line === '') {
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

            // Totais de cartão / cabeçalhos (4 colunas ou residual da coluna 2)
            if (preg_match('/^(total\s+cart|data\s+moviment|movimenta[cç][aã]o\s+valor)/iu', $line)) {
                continue;
            }

            if ($this->isProximaFaturaPreviewLine($line)) {
                continue;
            }

            $extras = $currentUltimosDigitos !== null
                ? ['ultimos_digitos' => $currentUltimosDigitos]
                : [];

            // Primeiro R$ da linha = despesa da fatura. O 2º (se houver) é a coluna
            // "Próxima fatura" colada pelo pdftotext -layout.
            if (!preg_match(
                '/^(?<dia>\d{1,2})\s+de\s+(?<mes>[a-zç]{3})\.?\s+(?<ano>20\d{2})\s+(?<resto>.+?)\s+(?<credito>\+)?\s*R\$\s*(?<valor>\d{1,3}(?:\.\d{3})*,\d{2})/iu',
                $line,
                $m
            )) {
                // Fallback legado: DD/MM DESCRICAO VALOR — só em PDF antigo.
                // No layout atual a data de corte (05/08/2026) não pode virar lançamento.
                if ($viuLayoutAtual) {
                    continue;
                }

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

            $viuLayoutAtual = true;

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

        return $this->omitirComprasParceladasEstornadas($transactions);
    }

    /**
     * Inter lista 1/N…N/N da mesma compra e um crédito do valor total quando
     * a compra é cancelada. Não cadastrar (nem projetar) essas parcelas.
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return list<array<string, mixed>>
     */
    private function omitirComprasParceladasEstornadas(array $transactions): array
    {
        $byKey = [];
        foreach ($transactions as $i => $tx) {
            $nome = mb_strtolower(trim((string) ($tx['estabelecimento'] ?? '')));
            $data = (string) ($tx['data'] ?? '');
            if ($nome === '' || $data === '') {
                continue;
            }
            $byKey[$nome . '|' . $data][] = $i;
        }

        $drop = [];
        foreach ($byKey as $idxs) {
            $purchaseIdxs = [];
            $refundIdxs = [];
            $purchaseSum = 0.0;
            $refundSum = 0.0;
            $parcelaNums = [];
            $parcelasTotal = null;

            foreach ($idxs as $i) {
                $tx = $transactions[$i];
                $tipo = $tx['tipo'] ?? 'purchase';
                $valor = (float) ($tx['valor'] ?? 0);

                if ($tipo === 'refund') {
                    $refundSum += $valor;
                    $refundIdxs[] = $i;
                    continue;
                }

                if ($tipo === 'purchase' && (int) ($tx['parcelas_total'] ?? 0) > 1) {
                    $purchaseIdxs[] = $i;
                    $purchaseSum += $valor;
                    $parcelaNums[] = (int) ($tx['parcela_atual'] ?? 0);
                    $parcelasTotal = (int) $tx['parcelas_total'];
                }
            }

            if ($purchaseIdxs === [] || $refundIdxs === [] || $parcelasTotal === null || $parcelasTotal < 2) {
                continue;
            }

            $unicas = array_values(array_unique($parcelaNums));
            sort($unicas);
            if ($unicas !== range(1, $parcelasTotal)) {
                continue;
            }

            if (abs($purchaseSum - $refundSum) >= 0.05) {
                continue;
            }

            foreach (array_merge($purchaseIdxs, $refundIdxs) as $i) {
                $drop[$i] = true;
            }
        }

        if ($drop === []) {
            return $transactions;
        }

        return array_values(array_filter(
            $transactions,
            static fn ($_, $i) => !isset($drop[$i]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * Início do quadro "Próxima fatura" (seção própria, não a coluna ao lado).
     */
    private function isProximaFaturaSectionStart(string $line): bool
    {
        return (bool) preg_match(
            '/^(pr[oó]xima fatura|data de corte|essas s[aã]o as compras parceladas)\b/iu',
            $line
        );
    }

    /**
     * Remove o quadro da direita colado na mesma linha pelo -layout.
     */
    private function stripProximaFaturaColumn(string $line): string
    {
        $line = preg_replace('/\bpr[oó]xima fatura\b.*$/iu', '', $line) ?? $line;
        $line = preg_replace('/\bdata de corte\b.*$/iu', '', $line) ?? $line;
        $line = preg_replace('/\bessas s[aã]o as compras parceladas\b.*$/iu', '', $line) ?? $line;

        return trim($line);
    }

    /**
     * Linha só do resumo da próxima fatura: "MOBILE HUB (Parcela 03 de 06) R$ 583,33"
     * (sem data de lançamento no formato Inter).
     */
    private function isProximaFaturaPreviewLine(string $line): bool
    {
        if (preg_match('/^\d{1,2}\s+de\s+[a-zç]{3}\.?\s+20\d{2}\b/iu', $line)) {
            return false;
        }

        return (bool) preg_match(
            '/\(Parcela\s+\d{1,2}\s+de\s+\d{1,2}\).{0,40}R\$\s*\d/iu',
            $line
        );
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
