<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfPasswordException;
use App\Models\Transacao;
use App\Services\Pdf\Parsers\AbstractInvoiceParser;
use App\Services\Pdf\Parsers\C6InvoiceParser;
use App\Services\Pdf\Parsers\GenericInvoiceParser;
use App\Services\Pdf\Parsers\InterInvoiceParser;
use App\Services\Pdf\Parsers\InvoiceParserInterface;
use App\Services\Pdf\Parsers\ItauInvoiceParser;
use App\Services\Pdf\Parsers\NubankInvoiceParser;
use App\Services\Pdf\Parsers\PicPayInvoiceParser;
use App\Services\Pdf\Parsers\SofisaInvoiceParser;
use Exception;
use Illuminate\Http\UploadedFile;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
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
            new PicPayInvoiceParser(),
            new SofisaInvoiceParser(),
            new GenericInvoiceParser(),
        ];
    }

    /**
     * Extrai transações de PDF, CSV ou XML.
     *
     * @return array{
     *   parser: string,
     *   text: string,
     *   transactions: array<int, array<string, mixed>>,
     *   valor_fatura: ?float,
     *   conferencia: array{
     *     valor_cabecalho: ?float,
     *     soma_transacoes: float,
     *     bate: bool,
     *     diferenca: ?float
     *   },
     *   metadata: array{
     *     mes: ?int,
     *     ano: ?int,
     *     ultimos_digitos: list<string>,
     *     bandeira_sugerida: ?string,
     *     parser: string
     *   }
     * }
     */
    /**
     * Parse de upload multipart: o temp do PHP (`/tmp/phpXXXX`) não tem extensão.
     * Usa nome original + MIME (+ magic bytes) para decidir o formato.
     */
    public function parseUploadedFile(UploadedFile $file, ?string $senhaPdf = null): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        if ($path === false || $path === '' || !is_file($path)) {
            throw new Exception('Arquivo da fatura inválido ou ilegível', 422);
        }

        $extension = $this->resolveUploadExtension(
            strtolower($file->getClientOriginalExtension() ?: ''),
            strtolower((string) $file->getMimeType()),
            $path
        );

        return $this->parseFile($path, $senhaPdf, $extension);
    }

    /**
     * @param  string|null  $extensionHint  Extensão forçada (ex.: upload sem extensão no path)
     */
    public function parseFile(string $absolutePath, ?string $senhaPdf = null, ?string $extensionHint = null): array
    {
        if (!file_exists($absolutePath)) {
            throw new Exception('Arquivo da fatura não encontrado', 404);
        }

        $extension = strtolower($extensionHint ?: pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension === '' || $extension === 'txt') {
            $extension = $this->resolveUploadExtension($extension, '', $absolutePath);
        }

        // .txt costuma ser CSV exportado (Inter/Excel com MIME text/plain).
        if ($extension === 'txt' && $this->looksLikeCsv($absolutePath)) {
            $extension = 'csv';
        }

        $result = match ($extension) {
            'csv' => $this->parseCsv($absolutePath),
            'xml' => $this->parseXml($absolutePath),
            'pdf' => $this->parsePdf($absolutePath, $senhaPdf),
            default => throw new Exception('Formato de arquivo não suportado para processamento. Use PDF, CSV ou XML.', 422),
        };

        $result['conferencia'] = $this->buildConferencia(
            $result['valor_fatura'] ?? null,
            $result['transactions'] ?? []
        );

        // Cabeçalho maior que a soma: parser provavelmente leu o limite (Inter).
        // Cabeçalho menor com diferença coberta por pagamentos: antecipação — manter.
        if (
            !$result['conferencia']['bate']
            && ($result['conferencia']['soma_transacoes'] ?? 0) > 0
            && ($result['valor_fatura'] ?? null) !== null
            && (float) $result['valor_fatura'] > (float) $result['conferencia']['soma_transacoes'] + 0.05
        ) {
            $result['valor_fatura'] = $result['conferencia']['soma_transacoes'];
        }

        $result['metadata'] = $this->buildMetadata($result);

        return $result;
    }

    /**
     * Resolve extensão a partir do nome original, MIME ou assinatura do arquivo.
     * Necessário porque uploads PHP chegam como `/tmp/phpXXXX` sem extensão.
     */
    private function resolveUploadExtension(string $extension, string $mime, string $absolutePath): string
    {
        if (in_array($extension, ['pdf', 'csv', 'xml'], true)) {
            return $extension;
        }

        if (str_contains($mime, 'pdf') || $extension === 'pdf') {
            return 'pdf';
        }

        if (str_contains($mime, 'xml') || $extension === 'xml') {
            return 'xml';
        }

        if (
            in_array($extension, ['txt', 'csv', ''], true)
            || in_array($mime, ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'], true)
        ) {
            if ($extension === 'txt' || $extension === '' || str_contains($mime, 'text/plain')) {
                if ($this->looksLikePdf($absolutePath)) {
                    return 'pdf';
                }
                if ($this->looksLikeCsv($absolutePath)) {
                    return 'csv';
                }
            }

            if ($extension === 'csv' || str_contains($mime, 'csv') || str_contains($mime, 'excel')) {
                return 'csv';
            }
        }

        if ($this->looksLikePdf($absolutePath)) {
            return 'pdf';
        }

        if ($this->looksLikeCsv($absolutePath)) {
            return 'csv';
        }

        return $extension !== '' ? $extension : 'pdf';
    }

    private function looksLikePdf(string $absolutePath): bool
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return is_string($header) && str_starts_with($header, '%PDF');
    }

    /**
     * @param  array{parser: string, text: string, transactions: array<int, array<string, mixed>>}  $parsed
     * @return array{mes: ?int, ano: ?int, ultimos_digitos: list<string>, bandeira_sugerida: ?string, parser: string}
     */
    private function buildMetadata(array $parsed): array
    {
        $text = (string) ($parsed['text'] ?? '');
        $parserName = (string) ($parsed['parser'] ?? 'generico');
        $transactions = $parsed['transactions'] ?? [];

        $mes = null;
        $ano = null;

        $parser = $this->resolveParserByName($parserName) ?? $this->resolveParser($text);
        $period = $parser->extractPeriod($text);
        if ($period !== null) {
            $mes = $period['mes'];
            $ano = $period['ano'];
        }

        if (($mes === null || $ano === null) && is_array($transactions)) {
            $fallback = $this->periodFromTransactions($transactions);
            $mes = $mes ?? $fallback['mes'];
            $ano = $ano ?? $fallback['ano'];
        }

        $digitos = [];
        foreach ($transactions as $tx) {
            $d = isset($tx['ultimos_digitos']) ? trim((string) $tx['ultimos_digitos']) : '';
            if (preg_match('/^\d{4}$/', $d)) {
                $digitos[$d] = true;
            }
        }

        return [
            'mes' => $mes,
            'ano' => $ano,
            'ultimos_digitos' => array_keys($digitos),
            'bandeira_sugerida' => $this->detectBandeiraNameFromText($text),
            'parser' => $parserName,
        ];
    }

    private function resolveParserByName(string $name): ?InvoiceParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->name() === $name) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array{mes: ?int, ano: ?int}
     */
    private function periodFromTransactions(array $transactions): array
    {
        $best = null;
        foreach ($transactions as $tx) {
            $date = isset($tx['data']) ? (string) $tx['data'] : '';
            if (!preg_match('/^(20\d{2})-(\d{2})-\d{2}$/', $date, $m)) {
                continue;
            }
            $key = ((int) $m[1] * 100) + (int) $m[2];
            if ($best === null || $key > $best['key']) {
                $best = ['mes' => (int) $m[2], 'ano' => (int) $m[1], 'key' => $key];
            }
        }

        return [
            'mes' => $best['mes'] ?? null,
            'ano' => $best['ano'] ?? null,
        ];
    }

    private function detectBandeiraNameFromText(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $normalized = mb_strtolower($text);
        $known = [
            'mastercard' => 'Mastercard',
            'maestro' => 'Maestro',
            'visa' => 'Visa',
            'hipercard' => 'Hipercard',
            'american express' => 'Amex',
            'amex' => 'Amex',
            'diners' => 'Diners Club',
            'discover' => 'Discover',
            'unionpay' => 'UnionPay',
            'union pay' => 'UnionPay',
            'banricompras' => 'Banricompras',
            'sorocred' => 'Sorocred',
            'elo' => 'Elo',
            'jcb' => 'JCB',
            'aura' => 'Aura',
            'cabal' => 'Cabal',
        ];

        foreach ($known as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private function parsePdf(string $absolutePath, ?string $senhaPdf = null): array
    {
        // -layout preserva colunas do extrato (data/descrição/valor na mesma linha).
        // -upw desbloqueia PDF com senha de usuário (ex.: C6 = 6 dígitos do CPF/CNPJ).
        $options = ['layout'];
        if ($senhaPdf !== null && $senhaPdf !== '') {
            $options[] = 'upw ' . $senhaPdf;
        }

        try {
            $text = Pdf::getText($absolutePath, null, $options);
        } catch (CouldNotExtractText $e) {
            if ($this->isPasswordError($e)) {
                throw new PdfPasswordException(
                    motivo: ($senhaPdf !== null && $senhaPdf !== '')
                        ? PdfPasswordException::MOTIVO_INCORRETA
                        : PdfPasswordException::MOTIVO_AUSENTE
                );
            }

            throw new Exception(
                'Não foi possível extrair texto do PDF: ' . $e->getMessage(),
                422
            );
        }

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
            // metadata preenchido em parseFile()
        ];
    }

    private function isPasswordError(CouldNotExtractText $e): bool
    {
        $haystack = mb_strtolower($e->getMessage() . ' ' . ($e->getProcess()->getErrorOutput() ?? ''));

        return str_contains($haystack, 'incorrect password')
            || str_contains($haystack, 'password required')
            || str_contains($haystack, 'encrypted');
    }

    /**
     * Total oficial do cabeçalho.
     * Nubank: "maio, no valor de R$ 899,02"
     * Inter: "Fatura atual R$ 6.137,69" ou "Total da sua fatura … R$ 7.512,20 … precisa pagar"
     * PicPay: "Total da fatura R$ 2.271,47" (não confundir com pagamento mínimo / limite)
     */
    private function extractValorFaturaHeader(string $text): ?float
    {
        $fromTotalDaSua = $this->extractTotalDaSuaFatura($text);
        if ($fromTotalDaSua !== null) {
            return $fromTotalDaSua;
        }

        $patterns = [
            // PicPay: evita colisão com "pagamento mínimo no valor de R$ ..."
            '/Total da fatura\s+(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            '/Valor total da fatura\s+(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            '/Total geral dos lan[cç]amentos\s+(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            // C6: "Valor da fatura: R$ 157,92" (aparece no cabeçalho de várias páginas)
            '/Valor da fatura:\s*R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            // C6 capa: "vencimento em Julho chegou no valor de R$ 157,92"
            '/chegou\s+no\s+valor\s+de\s+R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            // Inter: mesma linha (rótulo e valor podem ter ~120+ espaços no -layout).
            '/Fatura atual[^\n\r]{0,200}R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
            // Nubank: exige mês antes de "no valor de" (evita mínimo/rotativo do PicPay)
            '/(?:janeiro|fevereiro|mar[cç]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro),?\s*no valor de\s+R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->parseHeaderMoney($m[1]);
            }
        }

        return null;
    }

    /**
     * "Total da sua fatura" (Inter layout novo / PicPay).
     * Evita pegar o R$ da coluna "Limite" que o pdftotext -layout coloca perto do rótulo.
     *
     * Layout Inter real:
     *   Total da sua fatura                         Limite de crédito total
     *                                               R$ 17.560,00
     *   R$ 7.512,20                                 Data de Vencimento
     *   Este é o valor que você precisa pagar...
     */
    private function extractTotalDaSuaFatura(string $text): ?float
    {
        if (!preg_match('/Total da sua fatura/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $window = substr($text, $start, 450);

        // Inter: último R$ antes de "precisa pagar" (o 1º costuma ser o limite).
        if (preg_match('/precisa pagar/iu', $window, $pm, PREG_OFFSET_CAPTURE)) {
            $before = substr($window, 0, $pm[0][1]);
            if (preg_match_all('/R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $before, $all) && $all[1] !== []) {
                return $this->parseHeaderMoney((string) end($all[1]));
            }
        }

        // PicPay / fallback sem a frase: não arriscar se houver coluna de limite por perto.
        $lookback = substr($text, max(0, $m[0][1] - 80), 80);
        if (preg_match('/limite/iu', $lookback . $window)) {
            return null;
        }

        if (preg_match('/R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $window, $am)) {
            return $this->parseHeaderMoney($am[1]);
        }

        return null;
    }

    /**
     * Confere se a soma das transações do ciclo bate com o total do cabeçalho.
     * Pagamentos de competência anterior são ignorados na soma; se o cabeçalho
     * for menor e a diferença couber nos pagamentos, trata como antecipação (bate).
     *
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array{valor_cabecalho: ?float, soma_transacoes: float, bate: bool, diferenca: ?float}
     */
    private function buildConferencia(?float $valorCabecalho, array $transactions): array
    {
        $soma = $this->somaTransacoesCiclo($transactions);
        $bate = $valorCabecalho === null || abs($valorCabecalho - $soma) < 0.05;

        if (!$bate && $valorCabecalho !== null && $valorCabecalho <= $soma + 0.05) {
            $pagamentos = $this->somaPagamentos($transactions);
            $gap = round($soma - $valorCabecalho, 2);
            if ($gap >= 0 && $gap <= $pagamentos + 0.05) {
                $bate = true;
            }
        }

        return [
            'valor_cabecalho' => $valorCabecalho,
            'soma_transacoes' => $soma,
            'bate' => $bate,
            'diferenca' => $valorCabecalho !== null
                ? round($valorCabecalho - $soma, 2)
                : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     */
    private function somaPagamentos(array $transactions): float
    {
        $sum = 0.0;

        foreach ($transactions as $item) {
            if (($item['tipo'] ?? '') === Transacao::TIPO_PAYMENT) {
                $sum += (float) ($item['valor'] ?? 0);
            }
        }

        return round($sum, 2);
    }

    /**
     * Soma do ciclo atual: compras/encargos/antecipações − estornos.
     * Pagamentos são ignorados (são da competência anterior / quitação).
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    private function somaTransacoesCiclo(array $transactions): float
    {
        $balance = 0.0;

        foreach ($transactions as $item) {
            $valor = (float) ($item['valor'] ?? 0);
            $tipo = $item['tipo'] ?? Transacao::TIPO_PURCHASE;

            if ($tipo === Transacao::TIPO_PAYMENT) {
                continue;
            }

            if (
                $tipo === Transacao::TIPO_PURCHASE
                || $tipo === Transacao::TIPO_ADVANCE
                || $tipo === Transacao::TIPO_FEE
            ) {
                $balance += $valor;
                continue;
            }

            if ($tipo === Transacao::TIPO_REFUND) {
                $balance -= $valor;
            }
        }

        return round(max($balance, 0), 2);
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
            if ($tipo && !in_array($tipo, Transacao::TIPOS, true)) {
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
            $tipo = in_array($tipo, Transacao::TIPOS, true) ? $tipo : null;

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
