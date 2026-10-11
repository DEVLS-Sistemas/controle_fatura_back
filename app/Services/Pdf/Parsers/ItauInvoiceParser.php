<?php

namespace App\Services\Pdf\Parsers;

/**
 * Parser para faturas Itaú (Click / Unibanco).
 *
 * Lê só o ciclo atual, pelos títulos:
 *   - "Pagamentos efetuados"     → payments
 *   - "Lançamentos: compras e saques" → purchases
 *
 * A fatura antiga (2022) não tem a seção "Pagamentos efetuados". O pagamento
 * da fatura anterior está no resumo do início ("Pagamento efetuado em
 * 03/10/2022"). Se o pagamento não cobre o total anterior, o que sobra vira
 * saldo financiado (carryover). A seção do Click, quando existe, prevalece
 * para não lançar o mesmo pagamento duas vezes.
 *
 * Encerra compras em "Lançamentos no cartão" / "Total dos lançamentos atuais"
 * (e em "Compras parceladas" / "Limites de crédito" — fora do ciclo).
 *
 * Click com compras nas duas colunas: a direita abre em "Lançamentos: compras
 * e saques" na mesma linha de "Pagamentos efetuados". "Lançamentos no cartão"
 * nessa coluna é só o subtotal da direita — a esquerda continua. Parcelas
 * futuras ("Compras parceladas") ficam na direita e não entram no ciclo.
 *
 * Linha de lançamento (com ou sem coluna direita de encargos):
 *   12/08 PAGAMENTO -1.200,00
 *   28/11 PERNAMBUCO MOT 10/10 1.200,00
 *   25/08 PARK.ME ESTACIONAMENTOU 10,00
 * A linha seguinte sem data ("outros PAULISTA") continua o nome.
 *
 * O 1º valor em R$ da linha é o lançamento. Encargos à direita (Juros/IOF)
 * são lidos à parte. Quantias menores (10,00) não podem ser cortadas pela coluna.
 *
 * Fatura antiga (2022) repete o bloco por cartão ("NOME (final 2944)") e
 * põe a categoria na linha de baixo. Ela entra no nome para a compra
 * continuar rastreável: "EMERSON FERREIRA D VEÍCULOS .OLINDA",
 * "ALIEXPRESS - TURISMO E ENTRETENIM.SAO PAULO". A parcela pode vir
 * colada no nome ("D06/06"). "Lançamentos no cartão (final NNNN)" é subtotal
 * daquele cartão, não o fim do ciclo.
 */
class ItauInvoiceParser extends AbstractInvoiceParser
{
    private const COLUMN_SPLIT_FALLBACK = 90;

    private const MONEY = '-?\s*\d{1,3}(?:\.\d{3})*,\d{2}|-?\s*\d+,\d{2}';

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
        $splitCompras = $this->splitColunaComprasDireita($text);
        if ($splitCompras !== null) {
            return $this->parseComprasEmDuasColunas($text, $splitCompras);
        }

        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $columnSplit = $this->detectColumnSplit($text);
        $section = null; // payments | purchases
        $lastPurchaseIndex = null;
        $currentUltimosDigitos = null;
        $currentNomeNoCartao = null;
        $totalAnterior = null;
        $saldoFinanciadoImpresso = null;
        $pagamentosResumo = [];
        $lendoEncargos = false;

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

            // Subtotal de um cartão. Com "(final NNNN)" ainda vêm compras do
            // próximo cartão no mesmo ciclo. Sem isso, encerra o ciclo (Click).
            if (preg_match('/^lan[cç]amentos no cart/iu', $collapsed)) {
                $lastPurchaseIndex = null;
                if (!preg_match('/\(final\s+\d{4}\)/iu', $collapsed)) {
                    $section = null;
                }
                continue;
            }

            // Fim do ciclo atual: totais, parcelas futuras e limites.
            if (preg_match(
                '/^(compras parceladas|limites de cr[eé]dito|l\s+total dos lan[cç]amentos|total dos lan[cç]amentos)\b/iu',
                $collapsed
            )) {
                $section = null;
                $lastPurchaseIndex = null;
                continue;
            }

            $holderBlock = $this->matchCardHolderBlock($collapsed);
            if ($holderBlock !== null) {
                $currentUltimosDigitos = $holderBlock['digitos'];
                $nomeBloco = $holderBlock['nome'];
                if ($currentNomeNoCartao === null
                    || !str_starts_with(mb_strtoupper($currentNomeNoCartao), mb_strtoupper($nomeBloco))
                ) {
                    $currentNomeNoCartao = $nomeBloco;
                }
                $lastPurchaseIndex = null;
                continue;
            }

            if ($section === null && preg_match('/^encargos cobrados\b/iu', $collapsed)) {
                $lendoEncargos = true;
                continue;
            }

            if ($section === null && $lendoEncargos) {
                if ($this->encerraBlocoEncargos($collapsed)) {
                    $lendoEncargos = false;
                } else {
                    // O encargo cobrado fica à esquerda. À direita há simulação
                    // (limite, "Valor do IOF") que não é lançamento desta fatura.
                    // Linha vazia à esquerda não pode cair no texto da direita.
                    $esquerda = $this->collapseSpaces(mb_substr($rawLine, 0, $columnSplit));
                    $chargeFora = $this->parseChargeLine($esquerda);
                    if ($chargeFora !== null) {
                        $transactions[] = $this->makeTransaction(
                            null,
                            $chargeFora['estabelecimento'],
                            $chargeFora['valor'],
                            null,
                            null,
                            'fee'
                        );
                    }
                    continue;
                }
            }

            if ($section === null) {
                $this->acumularResumo(
                    $collapsed,
                    $closingMonth,
                    $closingYear,
                    $totalAnterior,
                    $saldoFinanciadoImpresso,
                    $pagamentosResumo
                );
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
                && !$this->shouldIgnorePurchaseContinuation($continuation)
            ) {
                $current = $transactions[$lastPurchaseIndex]['estabelecimento'];
                $categoria = $this->categoriaEstabelecimento($continuation);
                if ($categoria !== null) {
                    $sep = preg_match('/^\S+\s+\./u', $categoria) === 1 ? ' ' : ' - ';
                    $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current.$sep.$categoria);
                } else {
                    $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current.' '.$continuation);
                }
            }
        }

        return $this->anexarOperacionaisDoResumo(
            $transactions,
            $totalAnterior,
            $saldoFinanciadoImpresso,
            $pagamentosResumo
        );
    }

    /**
     * Resumo do início da fatura (antes de "Lançamentos"). Não é compra.
     *
     * @param  list<array{data: string, valor: float}>  $pagamentosResumo
     */
    private function acumularResumo(
        string $collapsed,
        int $closingMonth,
        int $closingYear,
        ?float &$totalAnterior,
        ?float &$saldoFinanciadoImpresso,
        array &$pagamentosResumo
    ): void {
        if ($totalAnterior === null && preg_match(
            '/total da fatura anterior\s+(?<valor>'.self::MONEY.')/iu',
            $collapsed,
            $m
        )) {
            $totalAnterior = abs($this->parseMoney($m['valor']));

            return;
        }

        if (preg_match(
            '/pagamento efetuado em\s+(?<data>\d{2}\/\d{2}\/\d{4})\s+(?<valor>'.self::MONEY.')/iu',
            $collapsed,
            $m
        )) {
            $data = $this->resolveTransactionDate($m['data'], $closingMonth, $closingYear);
            $valor = abs($this->parseMoney($m['valor']));
            if ($data === null || $valor <= 0) {
                return;
            }
            foreach ($pagamentosResumo as $existente) {
                if ($existente['data'] === $data && abs($existente['valor'] - $valor) < 0.01) {
                    return;
                }
            }
            $pagamentosResumo[] = ['data' => $data, 'valor' => $valor];

            return;
        }

        if ($saldoFinanciadoImpresso === null && preg_match(
            '/\bsaldo financiado\s+(?<valor>'.self::MONEY.')/iu',
            $collapsed,
            $m
        )) {
            $saldoFinanciadoImpresso = abs($this->parseMoney($m['valor']));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @param  list<array{data: string, valor: float}>  $pagamentosResumo
     * @return list<array<string, mixed>>
     */
    private function anexarOperacionaisDoResumo(
        array $transactions,
        ?float $totalAnterior,
        ?float $saldoFinanciadoImpresso,
        array $pagamentosResumo
    ): array {
        foreach ($pagamentosResumo as $pagamento) {
            if ($this->jaTemPagamento($transactions, $pagamento['data'], $pagamento['valor'])) {
                continue;
            }
            $transactions[] = $this->makeTransaction(
                $pagamento['data'],
                'Pagamento efetuado',
                $pagamento['valor'],
                null,
                null,
                'payment'
            );
        }

        $restante = null;
        if ($totalAnterior !== null) {
            $pago = 0.0;
            if ($pagamentosResumo !== []) {
                foreach ($pagamentosResumo as $pagamento) {
                    $pago += $pagamento['valor'];
                }
            } else {
                foreach ($transactions as $tx) {
                    if (($tx['tipo'] ?? '') === 'payment') {
                        $pago += (float) $tx['valor'];
                    }
                }
            }
            $restante = round($totalAnterior - $pago, 2);
        } elseif ($saldoFinanciadoImpresso !== null) {
            $restante = $saldoFinanciadoImpresso;
        }

        if ($restante !== null && $restante > 0.009) {
            $transactions[] = $this->makeTransaction(
                $pagamentosResumo[0]['data'] ?? null,
                'Saldo financiado',
                $restante,
                null,
                null,
                'carryover'
            );
        }

        return $transactions;
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     */
    private function jaTemPagamento(array $transactions, string $data, float $valor): bool
    {
        foreach ($transactions as $tx) {
            if (($tx['tipo'] ?? '') !== 'payment') {
                continue;
            }
            if (($tx['data'] ?? null) !== $data) {
                continue;
            }
            if (abs((float) $tx['valor'] - $valor) < 0.01) {
                return true;
            }
        }

        return false;
    }

    private function encerraBlocoEncargos(string $line): bool
    {
        return (bool) preg_match(
            '/^(fique atento|demais taxas|limites de cr|simula[cç]|compras parceladas|lan[cç]amentos)\b/iu',
            $line
        );
    }

    /**
     * Click: "Pagamentos efetuados" à esquerda e "Lançamentos: compras e saques"
     * à direita, na mesma linha. O corte é o início desse título.
     */
    private function splitColunaComprasDireita(string $text): ?int
    {
        foreach ($this->rawLines($text) as $rawLine) {
            if (!preg_match('/lan[cç]amentos:\s*compras/iu', $rawLine, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $pos = mb_strlen(substr($rawLine, 0, $m[0][1]));
            if ($pos < 60) {
                continue;
            }

            $left = $this->collapseSpaces(mb_substr($rawLine, 0, $pos));
            if (preg_match('/^pagamentos efetuados\b/iu', $left)) {
                return $pos;
            }
        }

        return null;
    }

    /**
     * Lê pagamentos à esquerda e compras nas duas colunas. O subtotal
     * "Lançamentos no cartão" e as parcelas futuras só fecham a coluna
     * em que aparecem.
     *
     * @return list<array<string, mixed>>
     */
    private function parseComprasEmDuasColunas(string $text, int $columnSplit): array
    {
        $transactions = [];
        [$closingMonth, $closingYear] = $this->resolveClosingPeriod($text);
        $leftSection = null;
        $rightSection = null;
        $lastLeft = null;
        $lastRight = null;
        $currentUltimosDigitos = null;
        $currentNomeNoCartao = null;
        $totalAnterior = null;
        $saldoFinanciadoImpresso = null;
        $pagamentosResumo = [];
        $lendoEncargos = false;

        foreach ($this->rawLines($text) as $rawLine) {
            $collapsed = $this->collapseSpaces($rawLine);
            $left = $this->collapseSpaces(mb_substr($rawLine, 0, $columnSplit));
            $right = mb_strlen($rawLine) > $columnSplit
                ? $this->collapseSpaces(mb_substr($rawLine, $columnSplit))
                : '';

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

            if (preg_match('/^encargos cobrados\b/iu', $left)) {
                $leftSection = null;
                $rightSection = null;
                $lastLeft = null;
                $lastRight = null;
                $lendoEncargos = true;
                continue;
            }

            if ($lendoEncargos) {
                if ($this->encerraBlocoEncargos($left !== '' ? $left : $collapsed)) {
                    $lendoEncargos = false;
                } else {
                    $chargeFora = $this->parseChargeLine($left);
                    if ($chargeFora !== null) {
                        $transactions[] = $this->makeTransaction(
                            null,
                            $chargeFora['estabelecimento'],
                            $chargeFora['valor'],
                            null,
                            null,
                            'fee',
                            $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao)
                        );
                    }
                }
                continue;
            }

            $leftSection = $this->avancarSecaoColuna($leftSection, $left, 'left');
            $rightSection = $this->avancarSecaoColuna($rightSection, $right, 'right');
            if ($leftSection !== 'purchases' || preg_match('/^lan[cç]amentos:\s*compras/iu', $left)) {
                $lastLeft = null;
            }
            if ($rightSection !== 'purchases' || preg_match('/^lan[cç]amentos:\s*compras/iu', $right)) {
                $lastRight = null;
            }

            if ($leftSection === null && $rightSection === null) {
                $this->acumularResumo(
                    $collapsed,
                    $closingMonth,
                    $closingYear,
                    $totalAnterior,
                    $saldoFinanciadoImpresso,
                    $pagamentosResumo
                );
                continue;
            }

            $extras = $this->cardExtras($currentUltimosDigitos, $currentNomeNoCartao);
            if (!$this->ehMarcadorDeSecao($left)) {
                $this->consumirColuna(
                    $transactions,
                    $leftSection,
                    $left,
                    $closingMonth,
                    $closingYear,
                    $extras,
                    $lastLeft
                );
            }
            if (!$this->ehMarcadorDeSecao($right)) {
                $this->consumirColuna(
                    $transactions,
                    $rightSection,
                    $right,
                    $closingMonth,
                    $closingYear,
                    $extras,
                    $lastRight
                );
            }
        }

        return $this->anexarOperacionaisDoResumo(
            $transactions,
            $totalAnterior,
            $saldoFinanciadoImpresso,
            $pagamentosResumo
        );
    }

    private function avancarSecaoColuna(?string $section, string $text, string $lado): ?string
    {
        if ($text === '') {
            return $section;
        }

        if ($lado === 'left' && preg_match('/^pagamentos efetuados\b/iu', $text)) {
            return 'payments';
        }

        if (preg_match('/^lan[cç]amentos:\s*compras/iu', $text)) {
            return 'purchases';
        }

        if (preg_match('/^lan[cç]amentos no cart/iu', $text)) {
            if (preg_match('/\(final\s+\d{4}\)/iu', $text)) {
                return $section;
            }

            return null;
        }

        if (preg_match(
            '/^(compras parceladas|limites de cr[eé]dito|l\s+total dos lan[cç]amentos|total dos lan[cç]amentos|pr[oó]xima fatura|demais faturas|total para pr[oó]ximas)\b/iu',
            $text
        )) {
            return null;
        }

        return $section;
    }

    private function ehMarcadorDeSecao(string $text): bool
    {
        return (bool) preg_match(
            '/^(pagamentos efetuados|lan[cç]amentos:\s*compras|lan[cç]amentos no cart|compras parceladas|limites de cr|l\s+total dos lan|total dos lan|encargos cobrados|data\b|p\s+total|e\s+total|pr[oó]xima fatura|demais faturas|total para pr)/iu',
            $text
        );
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @param  array{ultimos_digitos?: string, nome_no_cartao?: string}  $extras
     */
    private function consumirColuna(
        array &$transactions,
        ?string $section,
        string $text,
        int $closingMonth,
        int $closingYear,
        array $extras,
        ?int &$lastPurchaseIndex
    ): void {
        if ($section === null || $text === '' || $this->ehRuidoDeColuna($text)) {
            return;
        }

        $dated = $this->parseDatedLine($text, $closingMonth, $closingYear);

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

            return;
        }

        if ($dated !== null) {
            if ($this->isNoiseLabel($dated['estabelecimento'])) {
                return;
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

            return;
        }

        if (
            $lastPurchaseIndex === null
            || preg_match('/^\d{2}\/\d{2}/', $text)
            || preg_match('/\b(?:'.self::MONEY.')$/u', $text)
            || $this->ignorarContinuacaoNestaColuna($text, $extras['nome_no_cartao'] ?? null)
        ) {
            return;
        }

        $current = $transactions[$lastPurchaseIndex]['estabelecimento'];
        $categoria = $this->categoriaEstabelecimento($text);
        if ($categoria !== null) {
            $sep = preg_match('/^\S+\s+\./u', $categoria) === 1 ? ' ' : ' - ';
            $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current.$sep.$categoria);
        } else {
            $transactions[$lastPurchaseIndex]['estabelecimento'] = trim($current.' '.$text);
        }
    }

    private function ehRuidoDeColuna(string $text): bool
    {
        return (bool) preg_match('/^[A-Za-zÁÉÍÓÚÂÊÔÃÕÇ]$/u', $text)
            || !preg_match('/\p{L}/u', $text);
    }

    /**
     * "LEONARDO DA SILVA FERREIR" debaixo do título não é estabelecimento.
     * Quebra de nome em maiúsculas ("MONEY SAO PAULO") continua a compra.
     */
    private function ignorarContinuacaoNestaColuna(string $line, ?string $nomeNoCartao): bool
    {
        if ($this->isNoiseLabel($line) || $this->matchCardHolderBlock($line) !== null) {
            return true;
        }

        if (!$this->looksLikeHolderLine($line)) {
            return false;
        }

        if ($nomeNoCartao === null || $nomeNoCartao === '') {
            return true;
        }

        $prefixo = mb_substr(mb_strtoupper($nomeNoCartao), 0, 15);

        return str_starts_with(mb_strtoupper($line), $prefixo);
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

        // Fatura antiga: a coluna direita (CET, Parcelas fixas, Valor da fatura)
        // começa antes do fallback 90 e vaza no nome ("CET do", "Parcel", "Juros").
        $votes = [];
        foreach ($this->rawLines($text) as $rawLine) {
            $pos = $this->rightColumnMarkerPos($rawLine);
            if ($pos !== null) {
                $votes[$pos] = ($votes[$pos] ?? 0) + 1;
            }
        }

        if ($votes !== []) {
            arsort($votes);
            $bestPos = (int) array_key_first($votes);
            if ($votes[$bestPos] >= 2) {
                return $bestPos;
            }
        }

        return self::COLUMN_SPLIT_FALLBACK;
    }

    private function rightColumnMarkerPos(string $line): ?int
    {
        $lower = mb_strtolower($line);
        $found = null;
        foreach ([
            'encargos em caso',
            'cet do',
            'parcelas fixas',
            'juros do parcelamento',
            'pagamento mínimo',
            'pagamento minimo',
            'valor da fatura atual',
        ] as $marker) {
            $pos = mb_strpos($lower, $marker);
            if ($pos === false || $pos < 70 || $pos > 120) {
                continue;
            }
            if ($found === null || $pos < $found) {
                $found = $pos;
            }
        }

        return $found;
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

        // "Valor do IOF" é simulação da coluna de limite, não encargo da fatura.
        if ($nome === '' || preg_match('/^valor do iof\b/iu', $nome) || !$this->looksLikeChargeName($nome)) {
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
            '/^(data\b|p\s+total|l\s+total|e\s+total|lan[cç]amentos no cart|total dos|valor\b|pr[oó]xima fatura|demais faturas)/iu',
            $text
        );
    }

    /**
     * "LEONARDO DA SILVA F (final 2944)" — troca o cartão, não é estabelecimento.
     *
     * @return array{nome: string, digitos: string}|null
     */
    private function matchCardHolderBlock(string $line): ?array
    {
        if (!preg_match(
            '/^(?<nome>[A-ZÁÉÍÓÚÂÊÔÃÕÇ ]{5,})\s*\(final\s+(?<digitos>\d{4})\)/u',
            $line,
            $m
        )) {
            return null;
        }

        $nome = trim($m['nome']);
        if ($nome === '') {
            return null;
        }

        return [
            'nome' => $nome,
            'digitos' => $m['digitos'],
        ];
    }

    /**
     * Categoria impressa na fatura antiga, já sem o vazamento da coluna direita.
     * "VEÍCULOS .OLINDA" junta com espaço; frase longa ("TURISMO E ENTRETENIM.SAO PAULO")
     * junta com " - ". O Click ("outros PAULISTA") não entra aqui.
     */
    private function categoriaEstabelecimento(string $line): ?string
    {
        $line = trim(preg_replace('/\s+(?:cet|parcelas?|juros|valor)\b.*$/iu', '', $line) ?? $line);
        if ($line === '' || !preg_match(
            '/^[A-ZÁÉÍÓÚÂÊÔÃÕÇ0-9][A-ZÁÉÍÓÚÂÊÔÃÕÇ0-9 ]*\.\s*[A-ZÁÉÍÓÚÂÊÔÃÕÇ]/u',
            $line
        )) {
            return null;
        }

        return $line;
    }

    private function shouldIgnorePurchaseContinuation(string $line): bool
    {
        return $this->isNoiseLabel($line)
            || $this->looksLikeHolderLine($line)
            || $this->matchCardHolderBlock($line) !== null;
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
