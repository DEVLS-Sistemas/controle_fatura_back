<?php

namespace App\Services\Pdf\Parsers;

interface InvoiceParserInterface
{
    /**
     * Indica se este parser consegue lidar com o texto da fatura.
     */
    public function supports(string $text): bool;

    /**
     * Nome identificador do parser (ex: nubank, itau, generico).
     */
    public function name(): string;

    /**
     * Extrai linhas de transação do texto da fatura.
     *
     * Cada item deve conter:
     * - data (Y-m-d|null)
     * - estabelecimento (string)
     * - valor (float)
     * - parcelas_total (int|null)
     * - parcela_atual (int|null)
     * - valor_parcela (float|null)
     * - tipo (purchase|payment|refund|advance|fee)
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $text): array;

    /**
     * Competência sugerida (mês/ano do ciclo) a partir do cabeçalho da fatura.
     *
     * @return array{mes: int, ano: int}|null
     */
    public function extractPeriod(string $text): ?array;
}
