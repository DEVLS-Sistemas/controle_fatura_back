<?php

namespace App\Services\Pdf;

/**
 * Bancos cuja leitura de PDF/CSV foi testada com fatura real.
 * Ter cor no cadastro NÃO significa que o parser está homologado.
 */
class FaturaParserHomologacao
{
    /**
     * @var array<string, array{chave: string, label: string, nota: ?string}>
     */
    private const HOMOLOGADOS = [
        'nubank' => [
            'chave' => 'nubank',
            'label' => 'Nubank',
            'nota' => null,
        ],
        'inter' => [
            'chave' => 'inter',
            'label' => 'Inter',
            'nota' => null,
        ],
        'c6' => [
            'chave' => 'c6',
            'label' => 'C6 Bank',
            'nota' => null,
        ],
        'sofisa' => [
            'chave' => 'sofisa',
            'label' => 'Sofisa',
            'nota' => null,
        ],
        'picpay' => [
            'chave' => 'picpay',
            'label' => 'PicPay',
            'nota' => null,
        ],
        'itau' => [
            'chave' => 'itau',
            'label' => 'Itaú',
            'nota' => 'Homologado com fatura Itaú Click',
        ],
    ];

    public const AVISO = 'A leitura automática desta fatura ainda não está homologada. Os valores extraídos do arquivo podem não ser os corretos.';

    /**
     * @return list<array{chave: string, label: string, nota: ?string}>
     */
    public static function all(): array
    {
        return array_values(self::HOMOLOGADOS);
    }

    public static function isChaveHomologada(?string $chave): bool
    {
        if ($chave === null || $chave === '') {
            return false;
        }

        return isset(self::HOMOLOGADOS[strtolower($chave)]);
    }

    public static function isParserHomologado(?string $parser): bool
    {
        return self::findByParser($parser) !== null;
    }

    /**
     * @return array{chave: string, label: string, nota: ?string}|null
     */
    public static function find(?string $chave): ?array
    {
        if ($chave === null || $chave === '') {
            return null;
        }

        return self::HOMOLOGADOS[strtolower($chave)] ?? null;
    }

    /**
     * `inter-csv` → Inter homologado; `generico` / `csv` / `xml` → não.
     *
     * @return array{chave: string, label: string, nota: ?string}|null
     */
    public static function findByParser(?string $parser): ?array
    {
        $chave = self::chaveDoParser($parser);

        return $chave !== null ? self::find($chave) : null;
    }

    public static function chaveDoParser(?string $parser): ?string
    {
        if ($parser === null || $parser === '') {
            return null;
        }

        $base = strtolower(explode('-', $parser)[0]);

        if ($base === '' || $base === 'generico' || $base === 'csv' || $base === 'xml') {
            return null;
        }

        return $base;
    }

    /**
     * Flags para anexar em lookups de cor / chip de banco.
     *
     * @return array{importacao_pdf_homologada: bool, parser_homologado: array{chave: string, label: string, nota: ?string}|null}
     */
    public static function anexarChave(?string $chave): array
    {
        $info = self::find($chave);

        return [
            'importacao_pdf_homologada' => $info !== null,
            'parser_homologado' => $info,
        ];
    }

    /**
     * Flags a partir do nome/banco do cartão cadastrado.
     *
     * @return array{importacao_pdf_homologada: bool, parser_homologado: array{chave: string, label: string, nota: ?string}|null}
     */
    public static function anexarCartao(?string $nome, ?string $banco = null): array
    {
        $preset = \App\Services\Cartao\CartaoCoresPreset::resolver($nome, $banco);
        $chave = !empty($preset['padrao']) ? null : ($preset['chave'] ?? null);

        return self::anexarChave($chave);
    }

    /**
     * Flags a partir do parser que leu o arquivo.
     *
     * @return array{
     *     importacao_pdf_homologada: bool,
     *     parser_homologado: array{chave: string, label: string, nota: ?string}|null,
     *     aviso_parser: ?string
     * }
     */
    public static function anexarParser(?string $parser): array
    {
        $info = self::findByParser($parser);
        $homologada = $info !== null;

        return [
            'importacao_pdf_homologada' => $homologada,
            'parser_homologado' => $info,
            'aviso_parser' => $homologada ? null : self::AVISO,
        ];
    }
}
