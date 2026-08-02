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
            new InterInvoiceParser(),
            new ItauInvoiceParser(),
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

        // .txt costuma ser CSV exportado (Inter/Excel com MIME text/plain).
        if ($extension === 'txt' && $this->looksLikeCsv($absolutePath)) {
            $extension = 'csv';
        }

        return match ($extension) {
            'csv' => $this->parseCsv($absolutePath),
            'xml' => $this->parseXml($absolutePath),
            'pdf' => $this->parsePdf($absolutePath),
            default => throw new Exception('Formato de arquivo não suportado para processamento. Use PDF, CSV ou XML.', 422),
        };
    }

    private function parsePdf(string $absolutePath): array
    {
        // -layout preserva colunas do extrato (data/descrição/valor na mesma linha).
        $text = Pdf::getText($absolutePath, null, ['layout']);

        if (trim($text) === '') {
            throw new Exception('Não foi possível extrair texto do PDF. Verifique se o arquivo não é imagem escaneada.', 422);
        }

        $parser = $this->resolveParser($text);
        $transactions = $parser->parse($text);

        return [
            'parser' => $parser->name(),
            'text' => $text,
            'transactions' => $transactions,
            'valor_fatura' => $this->extractValorFaturaHeader($text),
        ];
    }

    /**
     * Total oficial do cabeçalho.
     * Nubank: "maio, no valor de R$ 899,02"
     * Inter: "Fatura atual R$ 6.137,69"
     */
    private function extractValorFaturaHeader(string $text): ?float
    {
        $patterns = [
            '/no valor de\s+R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            // Inter: mesma linha apenas (no quadro resumo, "FATURA ATUAL" fica acima de outro R$).
            '/Fatura atual[^\n\r]{0,120}R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->parseHeaderMoney($m[1]);
            }
        }

        return null;
    }

    private function parseHeaderMoney(string $value): float
    {
        $helper = new class extends AbstractInvoiceParser {
            public function name(): string
            {
                return 'header';
            }

            public function supports(string $text): bool
            {
                return true;
            }

            public function parse(string $text): array
            {
                return [];
            }

            public function money(string $value): float
            {
                return $this->parseMoney($value);
            }
        };

        return $helper->money($value);
    }

    /**
     * CSV esperado (cabeçalho flexível):
     * data;estabelecimento;valor;tipo;parcela_atual;parcelas_total
     * ou separado por vírgula.
     * Aceita aliases da Nubank: date,title,amount
     * Aceita CSV do Inter com metadados nas primeiras linhas.
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

        $delimiter = $this->detectCsvDelimiter($lines);
        [$headerIndex, $map] = $this->findCsvHeaderMap($lines, $delimiter);

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

        $isInter = $this->isInterCsv($lines, $delimiter);
        $transactions = [];

        for ($i = $headerIndex + 1; $i < count($lines); $i++) {
            $cols = str_getcsv($lines[$i], $delimiter, '"', '');
            if (count($cols) === 1 && trim((string) $cols[0]) === '') {
                continue;
            }

            $estabelecimento = trim((string) ($cols[$map['estabelecimento']] ?? ''));
            $valorRaw = trim((string) ($cols[$map['valor']] ?? ''));
            if ($estabelecimento === '' || $valorRaw === '') {
                continue;
            }

            // Linhas de metadados / totais sem data de lançamento
            $dataRaw = isset($map['data']) ? trim((string) ($cols[$map['data']] ?? '')) : '';
            if ($dataRaw === '' && $isInter) {
                continue;
            }

            $date = $dataRaw !== ''
                ? ($helper->date($dataRaw) ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw) ? $dataRaw : null))
                : null;

            $tipoColRaw = isset($map['tipo']) ? trim((string) ($cols[$map['tipo']] ?? '')) : '';
            $tipo = $tipoColRaw !== '' ? mb_strtolower($tipoColRaw) : null;
            if ($tipo && !in_array($tipo, ['purchase', 'payment', 'refund', 'advance'], true)) {
                // No Inter, "Tipo da Transacao" traz "Parcela 1/1", não o tipo do sistema.
                $tipo = null;
            }

            $parcelaAtual = isset($map['parcela_atual'], $cols[$map['parcela_atual']]) && $cols[$map['parcela_atual']] !== ''
                ? (int) $cols[$map['parcela_atual']]
                : null;
            $parcelasTotal = isset($map['parcelas_total'], $cols[$map['parcelas_total']]) && $cols[$map['parcelas_total']] !== ''
                ? (int) $cols[$map['parcelas_total']]
                : null;

            if ($parcelaAtual === null && $parcelasTotal === null) {
                [$parcelaAtual, $parcelasTotal] = $helper->installment(
                    trim($tipoColRaw . ' ' . $estabelecimento)
                );
            }

            // Inter: positivo = compra; negativo = entrada (pagamento/reembolso).
            // detectType() em makeTransaction já trata sinal + palavras-chave.
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
            'parser' => $isInter ? 'inter-csv' : 'csv',
            'text' => $content,
            'transactions' => $transactions,
            'valor_fatura' => null,
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
            'valor_fatura' => null,
        ];
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: int, 1: array<string, int>}
     */
    private function findCsvHeaderMap(array $lines, string $delimiter): array
    {
        $lastException = null;

        // Bancos (Inter) colocam metadados antes do cabeçalho real.
        foreach ($lines as $idx => $line) {
            $headers = array_map(
                static fn ($h) => mb_strtolower(trim($h, " \t\"'")),
                str_getcsv($line, $delimiter, '"', '')
            );

            try {
                return [$idx, $this->mapCsvHeaders($headers)];
            } catch (Exception $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new Exception(
            'CSV inválido. Cabeçalhos obrigatórios: estabelecimento (ou descricao/title) e valor (ou amount).',
            422
        );
    }

    /**
     * @param array<int, string> $lines
     */
    private function detectCsvDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 15));

        return substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
    }

    /**
     * @param array<int, string> $lines
     */
    private function isInterCsv(array $lines, string $delimiter): bool
    {
        $sample = mb_strtolower(implode("\n", array_slice($lines, 0, 12)));

        $hasInterHeader = str_contains($sample, 'data da transacao')
            || str_contains($sample, 'data da transação');

        $hasInterMeta = (str_contains($sample, 'fatura') || str_contains($sample, 'cartao') || str_contains($sample, 'cartão'))
            && str_contains($sample, 'estabelecimento')
            && (str_contains($sample, 'tipo da transacao') || str_contains($sample, 'tipo da transação'));

        if ($hasInterHeader || $hasInterMeta) {
            return true;
        }

        // Fallback: cabeçalho típico Inter já localizado.
        foreach (array_slice($lines, 0, 12) as $line) {
            $headers = array_map(
                static fn ($h) => mb_strtolower(trim($h, " \t\"'")),
                str_getcsv($line, $delimiter, '"', '')
            );
            if (
                in_array('data da transacao', $headers, true)
                || in_array('data da transação', $headers, true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCsv(string $absolutePath): bool
    {
        $content = file_get_contents($absolutePath);
        if ($content === false || trim($content) === '') {
            return false;
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            return false;
        }

        try {
            $delimiter = $this->detectCsvDelimiter($lines);
            $this->findCsvHeaderMap($lines, $delimiter);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function mapCsvHeaders(array $headers): array
    {
        $aliases = [
            'data' => [
                'data',
                'date',
                'data_compra',
                'dt',
                'data da transacao',
                'data da transação',
                'data da compra',
            ],
            'estabelecimento' => [
                'estabelecimento',
                'descricao',
                'descrição',
                'description',
                'title',
                'merchant',
                'loja',
            ],
            'valor' => ['valor', 'amount', 'value', 'vlr'],
            'tipo' => [
                'tipo',
                'type',
                'tipo da transacao',
                'tipo da transação',
            ],
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
