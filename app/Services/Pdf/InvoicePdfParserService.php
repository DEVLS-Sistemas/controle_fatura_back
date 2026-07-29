<?php

namespace App\Services\Pdf;

use App\Services\Pdf\Parsers\AbstractInvoiceParser;
use App\Services\Pdf\Parsers\C6InvoiceParser;
use App\Services\Pdf\Parsers\GenericInvoiceParser;
use App\Services\Pdf\Parsers\InterInvoiceParser;
use App\Services\Pdf\Parsers\InvoiceParserInterface;
use App\Services\Pdf\Parsers\ItauInvoiceParser;
use App\Services\Pdf\Parsers\NubankInvoiceParser;
use Exception;
use Spatie\PdfToText\Pdf;

class InvoicePdfParserService
{
    /** @var array<int, InvoiceParserInterface> */
    private array $parsers;

    public function __construct(?array $parsers = null)
    {
        // Ordem importa: específicos primeiro, genérico por último.
        $this->parsers = $parsers ?? [
            new NubankInvoiceParser(),
            new ItauInvoiceParser(),
            new InterInvoiceParser(),
            new C6InvoiceParser(),
            new GenericInvoiceParser(),
        ];
    }

    /**
     * Extrai transações de PDF, CSV ou XML.
     *
     * @return array{parser: string, text: string, transactions: array<int, array<string, mixed>>}
     */
    public function parseFile(string $absolutePath): array
    {
        if (!file_exists($absolutePath)) {
            throw new Exception('Arquivo da fatura não encontrado', 404);
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->parseCsv($absolutePath),
            'xml' => $this->parseXml($absolutePath),
            'pdf' => $this->parsePdf($absolutePath),
            default => throw new Exception('Formato de arquivo não suportado para processamento. Use PDF, CSV ou XML.', 422),
        };
    }

    private function parsePdf(string $absolutePath): array
    {
        $text = Pdf::getText($absolutePath);

        if (trim($text) === '') {
            throw new Exception('Não foi possível extrair texto do PDF. Verifique se o arquivo não é imagem escaneada.', 422);
        }

        $parser = $this->resolveParser($text);
        $transactions = $parser->parse($text);

        return [
            'parser' => $parser->name(),
            'text' => $text,
            'transactions' => $transactions,
        ];
    }

    /**
     * CSV esperado (cabeçalho flexível):
     * data;estabelecimento;valor;tipo;parcela_atual;parcelas_total
     * ou separado por vírgula.
     * Aceita aliases da Nubank: date,title,amount
     */
    private function parseCsv(string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);
        if ($content === false || trim($content) === '') {
            throw new Exception('Arquivo CSV vazio ou ilegível', 422);
        }

        // Remove BOM UTF-8
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            throw new Exception('CSV precisa de cabeçalho e ao menos uma linha de dados', 422);
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $headers = array_map(
            static fn ($h) => mb_strtolower(trim($h, " \t\"'")),
            str_getcsv($lines[0], $delimiter)
        );

        $map = $this->mapCsvHeaders($headers);
        $helper = new class extends AbstractInvoiceParser {
            public function name(): string
            {
                return 'csv';
            }

            public function supports(string $text): bool
            {
                return true;
            }

            public function parse(string $text): array
            {
                return [];
            }

            public function build(
                ?string $date,
                string $establishment,
                float $amount,
                ?int $parcelaAtual = null,
                ?int $parcelasTotal = null,
                ?string $tipo = null
            ): array {
                return $this->makeTransaction($date, $establishment, $amount, $parcelaAtual, $parcelasTotal, $tipo);
            }

            public function money(string $value): float
            {
                return $this->parseMoney($value);
            }

            public function date(string $value, ?int $year = null): ?string
            {
                return $this->parseDate($value, $year);
            }

            /** @return array{0: int|null, 1: int|null} */
            public function installment(?string $text): array
            {
                return $this->parseInstallment($text);
            }
        };

        $transactions = [];
        for ($i = 1; $i < count($lines); $i++) {
            $cols = str_getcsv($lines[$i], $delimiter);
            if (count($cols) === 1 && trim((string) $cols[0]) === '') {
                continue;
            }

            $estabelecimento = trim((string) ($cols[$map['estabelecimento']] ?? ''));
            $valorRaw = trim((string) ($cols[$map['valor']] ?? ''));
            if ($estabelecimento === '' || $valorRaw === '') {
                continue;
            }

            $dataRaw = isset($map['data']) ? trim((string) ($cols[$map['data']] ?? '')) : '';
            $date = $dataRaw !== ''
                ? ($helper->date($dataRaw) ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw) ? $dataRaw : null))
                : null;

            $tipo = isset($map['tipo']) ? trim((string) ($cols[$map['tipo']] ?? '')) : '';
            $tipo = $tipo !== '' ? mb_strtolower($tipo) : null;
            if ($tipo && !in_array($tipo, ['purchase', 'payment', 'refund', 'advance'], true)) {
                $tipo = null;
            }

            $parcelaAtual = isset($map['parcela_atual'], $cols[$map['parcela_atual']]) && $cols[$map['parcela_atual']] !== ''
                ? (int) $cols[$map['parcela_atual']]
                : null;
            $parcelasTotal = isset($map['parcelas_total'], $cols[$map['parcelas_total']]) && $cols[$map['parcelas_total']] !== ''
                ? (int) $cols[$map['parcelas_total']]
                : null;

            if ($parcelaAtual === null && $parcelasTotal === null) {
                [$parcelaAtual, $parcelasTotal] = $helper->installment($estabelecimento);
            }

            $transactions[] = $helper->build(
                $date,
                $estabelecimento,
                $helper->money($valorRaw),
                $parcelaAtual,
                $parcelasTotal,
                $tipo
            );
        }

        return [
            'parser' => 'csv',
            'text' => $content,
            'transactions' => $transactions,
        ];
    }

    /**
     * XML esperado (exemplo):
     * <fatura><transacao><data>..</data><estabelecimento>..</estabelecimento><valor>..</valor></transacao></fatura>
     */
    private function parseXml(string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);
        if ($content === false || trim($content) === '') {
            throw new Exception('Arquivo XML vazio ou ilegível', 422);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new Exception('XML inválido', 422);
        }

        $helper = new class extends AbstractInvoiceParser {
            public function name(): string
            {
                return 'xml';
            }

            public function supports(string $text): bool
            {
                return true;
            }

            public function parse(string $text): array
            {
                return [];
            }

            public function build(
                ?string $date,
                string $establishment,
                float $amount,
                ?int $parcelaAtual = null,
                ?int $parcelasTotal = null,
                ?string $tipo = null
            ): array {
                return $this->makeTransaction($date, $establishment, $amount, $parcelaAtual, $parcelasTotal, $tipo);
            }

            public function money(string $value): float
            {
                return $this->parseMoney($value);
            }

            public function date(string $value, ?int $year = null): ?string
            {
                return $this->parseDate($value, $year);
            }
        };

        $nodes = $xml->xpath('//transacao|//transaction|//lancamento|//item') ?: [];
        if (count($nodes) === 0) {
            // fallback: filhos diretos do root
            $nodes = [];
            foreach ($xml->children() as $child) {
                $nodes[] = $child;
            }
        }

        $transactions = [];
        foreach ($nodes as $node) {
            $estabelecimento = trim((string) (
                $node->estabelecimento
                ?? $node->descricao
                ?? $node->merchant
                ?? $node->description
                ?? ''
            ));
            $valorRaw = trim((string) ($node->valor ?? $node->amount ?? $node->value ?? ''));
            if ($estabelecimento === '' || $valorRaw === '') {
                continue;
            }

            $dataRaw = trim((string) ($node->data ?? $node->date ?? $node->data_compra ?? ''));
            $date = $dataRaw !== ''
                ? ($helper->date($dataRaw) ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw) ? $dataRaw : null))
                : null;

            $tipo = trim(mb_strtolower((string) ($node->tipo ?? $node->type ?? '')));
            $tipo = in_array($tipo, ['purchase', 'payment', 'refund', 'advance'], true) ? $tipo : null;

            $parcelaAtual = isset($node->parcela_atual) ? (int) $node->parcela_atual : null;
            $parcelasTotal = isset($node->parcelas_total) ? (int) $node->parcelas_total : null;

            $transactions[] = $helper->build(
                $date,
                $estabelecimento,
                $helper->money($valorRaw),
                $parcelaAtual,
                $parcelasTotal,
                $tipo
            );
        }

        return [
            'parser' => 'xml',
            'text' => $content,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function mapCsvHeaders(array $headers): array
    {
        $aliases = [
            'data' => ['data', 'date', 'data_compra', 'dt'],
            'estabelecimento' => ['estabelecimento', 'descricao', 'description', 'title', 'merchant', 'loja'],
            'valor' => ['valor', 'amount', 'value', 'vlr'],
            'tipo' => ['tipo', 'type'],
            'parcela_atual' => ['parcela_atual', 'parcela', 'installment'],
            'parcelas_total' => ['parcelas_total', 'parcelas', 'total_parcelas', 'installments'],
        ];

        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($headers as $idx => $header) {
                if (in_array($header, $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        if (!isset($map['estabelecimento']) || !isset($map['valor'])) {
            throw new Exception(
                'CSV inválido. Cabeçalhos obrigatórios: estabelecimento (ou descricao/title) e valor (ou amount). Opcional: data/date, tipo, parcela_atual, parcelas_total.',
                422
            );
        }

        return $map;
    }

    private function resolveParser(string $text): InvoiceParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($text)) {
                return $parser;
            }
        }

        return new GenericInvoiceParser();
    }
}
