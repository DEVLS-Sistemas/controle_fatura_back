<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Itaú (Click / Unibanco).
 *
 * Lê só o ciclo atual, pelos títulos:
 *   - "Pagamentos efetuados"     → payments
 *   - "Lançamentos: compras e saques" → purchases
 *
 * Encerra compras em "Lançamentos no cartão" / "Total dos lançamentos atuais"
 * (e em "Compras parceladas" / "Limites de crédito" — fora do ciclo).
 *
 * Linha de lançamento (com ou sem coluna direita de encargos):
 *   12/08 PAGAMENTO -1.200,00
 *   28/11 PERNAMBUCO MOT 10/10 1.200,00
 *   25/08 PARK.ME ESTACIONAMENTOU 10,00
 * A linha seguinte sem data ("outros PAULISTA") continua o nome.
 *
 * O 1º valor em R$ da linha é o lançamento. Encargos à direita (Juros/IOF)
 * são lidos à parte. Quantias menores (10,00) não podem ser cortadas pela coluna.
 */
class ItauInvoiceParser extends AbstractInvoiceParser
{
    private const COLUMN_SPLIT_FALLBACK = 90;

    private const MONEY = '-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}';

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
        $columnSplit = $this->detectColumnSplit($text);
        $section = null; // payments | purchases
        $lastPurchaseIndex = null;
        $currentUltimosDigitos = null;
        $currentNomeNoCartao = null;

        foreach ($this->rawLines($text) as $rawLine) {
            $collapsed = $this->collapseSpaces($rawLine);

            if (preg_match('/^titular\s+(.+)$/iu', $collapsed, $holderMatch)) {
                $nome = trim($holderMatch[1]);
                if ($nome !== '') {
                    $currentNomeNoCartao = $nome;
                }
                continue;
            }

            $cardDigits = $this->matchCartaoUltimosDigitos($collapsed);
            if ($cardDigits !== null) {
                $currentUltimosDigitos = $cardDigits;
                continue;
            }

            if (preg_match('/^pagamentos efetuados\b/iu', $collapsed)) {
                $section = 'payments';
                continue;
            }

            if (preg_match('/^lan[cç]amentos:\s*compras/iu', $collapsed)) {
                $section = 'purchases';
                $lastPurchaseIndex = null;
                continue;
            }

            // Fim do ciclo atual: totais do cartão, parcelas futuras e limites.
            if (preg_match(
                '/^(compras parceladas|limites de cr[eé]dito|lan[cç]amentos no cart|l\s+total dos lan[cç]amentos|total dos lan[cç]amentos)\b/iu',
                $collapsed
            )) {
                $section = null;
                $lastPurchaseIndex = null;
                continue;
            }

            if ($section === null) {
                continue;
            }

            $left = $this->collapseSpaces(mb_substr($rawLine, 0, $columnSplit));
            $right = mb_strlen($rawLine) > $columnSplit
                ? $this->collapseSpaces(mb_substr($rawLine, $columnSplit))
                : '';
            $extras = $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao);

            // Texto linear (sem colunas) ou linha com encargos à direita: o 1º R$ é o lançamento.
            $dated = $this->parseDatedLine($collapsed, $closingMonth, $closingYear)
                ?? $this->parseDatedLine($left, $closingMonth, $closingYear);

            if ($section === 'payments') {
                if ($dated !== null && !$this->isNoiseLabel($dated['estabelecimento'])) {
                    $transactions[] = $this->makeTransaction(
                        $dated['data'],
                        $dated['estabelecimento'],
                        $dated['valor'],
                        null,
                        null,
                        null,
                        $extras
                    );
                }

                $charge = $this->parseChargeLine($right);
                if ($charge !== null) {
                    $transactions[] = $this->makeTransaction(
                        null,
                        $charge['estabelecimento'],
                        $charge['valor'],
                        null,
                        null,
                        null,
                        $extras
                    );
                }

                continue;
            }

            // purchases
            if ($dated !== null) {
                if ($this->isNoiseLabel($dated['estabelecimento'])) {
                    continue;
                }

                $transactions[] = $this->makeTransaction(
                    $dated['data'],
                    $dated['estabelecimento'],
                    $dated['valor'],
                    null,
                    null,
                    null,
                    $extras
                );
                $lastPurchaseIndex = array_key_last($transactions);
                continue;
            }

            $continuation = $left;
            if ($continuation === '' && $right === '') {
                $continuation = $collapsed;
            }
            if (
                $lastPurchaseIndex !== null
                && $continuation !== ''
                && !preg_match('/^\d{2}\/\d{2}/', $continuation)
                && !preg_match('/\b(?:'.self::MONEY.')$/u', $continuation)
                && !$this->isNoiseLabel($continuation)
                && !$this->looksLikeHolderLine($continuation)
            ) {
                $current = $transactions[$lastPurchaseIndex]['estabelecimento'];
                $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current.' '.$continuation);
            }
        }

        return $transactions;
    }

    /**
     * Início da coluna direita (encargos / textos). Sem o rótulo, 90 — os
     * valores da esquerda (até ~coluna 87) cabem; "Juros do rotativo" começa em 90.
     */
    private function detectColumnSplit(string $text): int
    {
        foreach ($this->rawLines($text) as $rawLine) {
            $pos = mb_stripos($rawLine, 'encargos cobrados');
            if ($pos !== false && $pos >= 70 && $pos <= 120) {
                return $pos;
            }
        }

        return self::COLUMN_SPLIT_FALLBACK;
    }

    /**
     * "Cartão 4705.XXXX.XXXX.8201" → "8201"
     */
    private function matchCartaoUltimosDigitos(string $line): ?string
    {
        if (!preg_match(
            '/^cart[aã]o\s+\d{4}[.\s]+[Xx*]{4}[.\s]+[Xx*]{4}[.\s]+(\d{4})\b/iu',
            $line,
            $m
        )) {
            return null;
        }

        return $m[1];
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

    /**
     * DD/MM + descrição + 1º valor em R$ (ignora lixo da coluna direita depois).
     *
     * @return array{data: string|null, estabelecimento: string, valor: float}|null
     */
    private function parseDatedLine(string $line, int $closingMonth, int $closingYear): ?array
    {
        if (!preg_match(
            '/^(?<data>\d{2}\/\d{2}(?:\/\d{4})?)\s+(?<resto>.+?)\s+(?<valor>'.self::MONEY.')(?:\s|$)/u',
            $line,
            $m
        )) {
            return null;
        }

        $resto = trim($m['resto']);
        if ($resto === '' || $this->isNoiseLabel($resto)) {
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
            '/^(?<nome>.+?)\s+(?<valor>'.self::MONEY.')$/u',
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
        return $this->looksLikeFeeName($name);
    }

    private function isNoiseLabel(string $text): bool
    {
        return (bool) preg_match(
            '/^(data\b|p\s+total|l\s+total|e\s+total|lan[cç]amentos no cart|total dos|valor em r\$|pr[oó]xima fatura|demais faturas)/iu',
            $text
        );
    }

    /**
     * Nome do titular logo abaixo de "Lançamentos: compras e saques" (não é estabelecimento).
     */
    private function looksLikeHolderLine(string $line): bool
    {
        return (bool) preg_match('/^[A-ZÁÉÍÓÚÃÕÂÊÇÜ ]{10,}$/u', $line)
            && !preg_match('/\d/', $line);
    }

    /**
     * @return array{mes: int, ano: int}|null
     */
    public function extractPeriod(string $text): ?array
    {
        [$mes, $ano] = $this->resolveClosingPeriod($text);

        if ($mes < 1 || $mes > 12 || $ano < 2000) {
            return null;
        }

        return ['mes' => $mes, 'ano' => $ano];
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
