<?php

namespace App\Services\Pdf;

use App\Models\Transacao;
use Symfony\Component\Process\Process;

/**
 * No PDF antigo do Nubank o crédito não tem sinal de menos: o valor fica verde
 * (#6cc10d). O pdftotext perde a cor e a linha entra como compra.
 * Ex.: "Variação cambial" 0,99 verde reduz a fatura; 5,23 preto soma.
 */
class NubankCreditoVerde
{
    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array<int, array<string, mixed>>
     */
    public function aplicar(string $absolutePath, array $transactions, ?string $senhaPdf = null): array
    {
        if ($transactions === [] || ! is_file($absolutePath)) {
            return $transactions;
        }

        $xml = $this->extrairXml($absolutePath, $senhaPdf);
        if ($xml === null || trim($xml) === '') {
            return $transactions;
        }

        return $this->aplicarCreditos($transactions, $this->creditosDoXml($xml));
    }

    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @param  list<array{dia: int, mes: int, descricao: string, valor: float}>  $creditos
     * @return array<int, array<string, mixed>>
     */
    public function aplicarCreditos(array $transactions, array $creditos): array
    {
        if ($creditos === []) {
            return $transactions;
        }

        $pool = $creditos;

        foreach ($transactions as &$tx) {
            if (($tx['tipo'] ?? Transacao::TIPO_PURCHASE) !== Transacao::TIPO_PURCHASE) {
                continue;
            }

            $partes = $this->partesDaData(isset($tx['data']) ? (string) $tx['data'] : null);
            if ($partes === null) {
                continue;
            }

            $nome = $this->normalizarNome((string) ($tx['estabelecimento'] ?? ''));
            $valor = round((float) ($tx['valor'] ?? 0), 2);

            foreach ($pool as $i => $credito) {
                if ($credito['dia'] !== $partes['dia'] || $credito['mes'] !== $partes['mes']) {
                    continue;
                }
                if ($this->normalizarNome($credito['descricao']) !== $nome) {
                    continue;
                }
                if (abs($credito['valor'] - $valor) >= 0.001) {
                    continue;
                }

                $tx['tipo'] = Transacao::TIPO_REFUND;
                unset($pool[$i]);
                break;
            }
        }
        unset($tx);

        return $transactions;
    }

    /**
     * Linhas do extrato cujo valor está em verde e têm data na mesma linha.
     * Totais do resumo (sem data) ficam de fora.
     *
     * @return list<array{dia: int, mes: int, descricao: string, valor: float}>
     */
    public function creditosDoXml(string $xml): array
    {
        $inicio = strpos($xml, '<pdf2xml');
        if ($inicio === false) {
            return [];
        }

        $xml = substr($xml, $inicio);
        $anterior = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if ($doc === false) {
            return [];
        }

        $fontes = [];
        $creditos = [];

        foreach ($doc->page as $page) {
            $textos = [];
            foreach ($page->children() as $node) {
                $tag = $node->getName();
                if ($tag === 'fontspec') {
                    $id = (string) $node['id'];
                    $fontes[$id] = strtolower((string) $node['color']);
                    continue;
                }
                if ($tag !== 'text') {
                    continue;
                }
                $valor = trim((string) $node);
                if ($valor === '') {
                    continue;
                }
                $textos[] = [
                    'top' => (float) $node['top'],
                    'left' => (float) $node['left'],
                    'font' => (string) $node['font'],
                    'text' => $valor,
                ];
            }

            foreach ($textos as $texto) {
                if (! $this->corDeCredito($fontes[$texto['font']] ?? '')) {
                    continue;
                }
                $valor = $this->parseValorBrasileiro($texto['text']);
                if ($valor === null) {
                    continue;
                }

                $data = null;
                $descricao = null;
                $descricaoLeft = -1.0;
                foreach ($textos as $vizinho) {
                    if ($vizinho === $texto) {
                        continue;
                    }
                    if (abs($vizinho['top'] - $texto['top']) > 8) {
                        continue;
                    }
                    if ($vizinho['left'] >= $texto['left']) {
                        continue;
                    }
                    $diaMes = $this->diaMes($vizinho['text']);
                    if ($diaMes !== null) {
                        $data = $diaMes;
                        continue;
                    }
                    if ($vizinho['left'] > $descricaoLeft) {
                        $descricaoLeft = $vizinho['left'];
                        $descricao = $vizinho['text'];
                    }
                }

                if ($data === null || $descricao === null || trim($descricao) === '') {
                    continue;
                }

                $creditos[] = [
                    'dia' => $data['dia'],
                    'mes' => $data['mes'],
                    'descricao' => trim($descricao),
                    'valor' => $valor,
                ];
            }
        }

        return $creditos;
    }

    private function extrairXml(string $absolutePath, ?string $senhaPdf): ?string
    {
        $binario = $this->resolverPdfToHtml();
        if ($binario === null) {
            return null;
        }

        $comando = [$binario, '-xml', '-i', '-q', '-enc', 'UTF-8', '-stdout'];
        if ($senhaPdf !== null && $senhaPdf !== '') {
            $comando[] = '-upw';
            $comando[] = $senhaPdf;
        }
        $comando[] = $absolutePath;

        $process = new Process($comando);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $saida = $process->getOutput();

        return str_contains($saida, '<pdf2xml') ? $saida : null;
    }

    private function resolverPdfToHtml(): ?string
    {
        foreach (['/usr/bin/pdftohtml', '/usr/local/bin/pdftohtml'] as $caminho) {
            if (is_executable($caminho)) {
                return $caminho;
            }
        }

        $process = new Process(['bash', '-lc', 'command -v pdftohtml']);
        $process->run();
        $caminho = trim($process->getOutput());

        return $caminho !== '' && is_executable($caminho) ? $caminho : null;
    }

    /**
     * Verde do crédito Nubank (#6cc10d). Roxo da marca e cinzas ficam de fora.
     */
    private function corDeCredito(string $hex): bool
    {
        $hex = ltrim(strtolower($hex), '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return false;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return $g >= 140 && $g > $r + 40 && $g > $b + 40;
    }

    /**
     * @return array{dia: int, mes: int}|null
     */
    private function diaMes(string $texto): ?array
    {
        if (! preg_match('/^(\d{2})\s+([A-Z]{3})$/u', trim($texto), $m)) {
            return null;
        }

        $meses = [
            'JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4,
            'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8,
            'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12,
        ];
        $mes = $meses[$m[2]] ?? null;
        if ($mes === null) {
            return null;
        }

        return ['dia' => (int) $m[1], 'mes' => $mes];
    }

    /**
     * @return array{dia: int, mes: int}|null
     */
    private function partesDaData(?string $data): ?array
    {
        if ($data === null || ! preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $data, $m)) {
            return null;
        }

        return ['dia' => (int) $m[2], 'mes' => (int) $m[1]];
    }

    private function parseValorBrasileiro(string $texto): ?float
    {
        $texto = trim($texto);
        if (! preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$/', $texto)) {
            return null;
        }

        $normalizado = str_replace('.', '', $texto);
        $normalizado = str_replace(',', '.', $normalizado);

        return round((float) $normalizado, 2);
    }

    private function normalizarNome(string $nome): string
    {
        $nome = mb_strtolower(trim($nome));
        $nome = preg_replace('/\s+/u', ' ', $nome) ?? $nome;

        return $nome;
    }
}
