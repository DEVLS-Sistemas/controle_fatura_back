<?php

namespace App\Services\Cartao;

/**
 * Cores oficiais (fundo + texto) dos cartões/bancos mais comuns no Brasil.
 * Usado no cadastro (auto-aplicar) e nos lookups do formulário (swatches).
 */
class CartaoCoresPreset
{
    public const COR_PADRAO_FUNDO = '#e5e7eb';

    public const COR_PADRAO_TEXTO = '#111827';

    public const COR_PADRAO_LABEL = 'Padrão';

    public const CHAVE_PADRAO = 'padrao';

    /**
     * @return array<int, array{
     *   chave: string,
     *   label: string,
     *   aliases: array<int, string>,
     *   cor_fundo: string,
     *   cor_texto: string
     * }>
     */
    public static function all(): array
    {
        return [
            self::preset('nubank', 'Nubank', '#820ad1', '#ffffff', ['nubank', 'nu bank', 'nu pagamentos', 'roxinho']),
            self::preset('inter', 'Inter', '#ff7a00', '#ffffff', ['inter', 'banco inter', 'inter bank']),
            self::preset('c6', 'C6 Bank', '#111111', '#ffffff', ['c6', 'c6 bank', 'c6bank']),
            self::preset('sofisa', 'Sofisa', '#008f5a', '#ffffff', ['sofisa', 'banco sofisa']),
            self::preset('itau', 'Itaú', '#003b70', '#ffffff', ['itau', 'banco itau', 'itau unibanco']),
            self::preset('santander', 'Santander', '#ec0000', '#ffffff', ['santander']),
            self::preset('bradesco', 'Bradesco', '#cc092f', '#ffffff', ['bradesco']),
            self::preset('bb', 'Banco do Brasil', '#f8d117', '#003da5', ['banco do brasil', 'banco brasil', 'bb']),
            self::preset('caixa', 'Caixa', '#005ca9', '#ffffff', ['caixa', 'caixa economica', 'cef']),
            self::preset('picpay', 'PicPay', '#21c25e', '#000000', ['picpay', 'pic pay']),
            self::preset('mercadopago', 'Mercado Pago', '#009ee3', '#ffffff', ['mercado pago', 'mercadopago', 'mercado livre']),
            self::preset('neon', 'Neon', '#00e676', '#000000', ['neon']),
            self::preset('btg', 'BTG Pactual', '#001e62', '#ffffff', ['btg', 'btg pactual']),
            self::preset('xp', 'XP', '#111111', '#ffffff', ['xp', 'xp investimentos']),
            self::preset('pagbank', 'PagBank', '#ffb800', '#000000', ['pagbank', 'pag bank', 'pagseguro']),
            self::preset('pan', 'PAN', '#00aeef', '#ffffff', ['banco pan', 'pan']),
            self::preset('will', 'Will Bank', '#6c2bd9', '#ffffff', ['will bank', 'willbank', 'will']),
            self::preset('original', 'Original', '#00a859', '#ffffff', ['banco original', 'original']),
            self::preset('next', 'Next', '#00a859', '#ffffff', ['next']),
            self::preset('amazon', 'Amazon Card', '#146eb4', '#ffffff', ['amazon card', 'amazon visa', 'amazon']),
            self::preset('sams', "Sam's Club", '#0067a0', '#ffffff', ['sams club', 'sams', "sam's club"]),
            self::preset('paodeacucar', 'Pão de Açúcar', '#00843d', '#ffffff', ['pao de acucar', 'pao de acucar card']),
            self::preset('carrefour', 'Carrefour', '#004b93', '#ffffff', ['carrefour']),
            self::preset('magalu', 'Magalu', '#0086ff', '#ffffff', ['magazine luiza', 'magalu', 'luiza']),
            self::preset('renner', 'Renner', '#d71920', '#ffffff', ['renner']),
            self::preset('riachuelo', 'Riachuelo', '#e30613', '#ffffff', ['riachuelo']),
            self::preset('americanas', 'Americanas', '#e60012', '#ffffff', ['americanas']),
            self::preset('shopee', 'Shopee', '#ee4d2d', '#ffffff', ['shopee']),
        ];
    }

    /**
     * @return array{chave: string, label: string, cor_fundo: string, cor_texto: string, padrao: bool}
     */
    public static function padrao(): array
    {
        return [
            'chave' => self::CHAVE_PADRAO,
            'label' => self::COR_PADRAO_LABEL,
            'cor_fundo' => self::COR_PADRAO_FUNDO,
            'cor_texto' => self::COR_PADRAO_TEXTO,
            'padrao' => true,
        ];
    }

    /**
     * Resolve o par de cores a partir do nome e/ou banco do cartão.
     * Sem match → cinza claro padrão.
     *
     * @return array{chave: string, label: string, cor_fundo: string, cor_texto: string, padrao: bool}
     */
    public static function resolver(?string $nome, ?string $banco = null): array
    {
        $haystack = self::normalize(trim(($nome ?? '') . ' ' . ($banco ?? '')));
        if ($haystack === '') {
            return self::padrao();
        }

        $melhor = null;
        $melhorLen = -1;

        foreach (self::all() as $preset) {
            foreach ($preset['aliases'] as $alias) {
                $aliasNorm = self::normalize($alias);
                if ($aliasNorm === '') {
                    continue;
                }

                if (!self::matches($haystack, $aliasNorm)) {
                    continue;
                }

                $len = mb_strlen($aliasNorm);
                if ($len > $melhorLen) {
                    $melhor = $preset;
                    $melhorLen = $len;
                }
            }
        }

        if ($melhor === null) {
            return self::padrao();
        }

        return [
            'chave' => $melhor['chave'],
            'label' => $melhor['label'],
            'cor_fundo' => $melhor['cor_fundo'],
            'cor_texto' => $melhor['cor_texto'],
            'padrao' => false,
        ];
    }

    /**
     * Swatches do formulário: padrão + um par por banco (mesmo se HEX repetir).
     *
     * @return array<int, array{chave: string, label: string, cor_fundo: string, cor_texto: string, padrao: bool}>
     */
    public static function paresParaLookups(): array
    {
        $pares = [self::padrao()];

        foreach (self::all() as $preset) {
            $pares[] = [
                'chave' => $preset['chave'],
                'label' => $preset['label'],
                'cor_fundo' => $preset['cor_fundo'],
                'cor_texto' => $preset['cor_texto'],
                'padrao' => false,
            ];
        }

        return $pares;
    }

    /**
     * @return array<int, string>
     */
    public static function coresFundo(): array
    {
        $cores = [self::COR_PADRAO_FUNDO];

        foreach (self::all() as $preset) {
            $cores[] = $preset['cor_fundo'];
        }

        return array_values(array_unique($cores));
    }

    /**
     * @return array<int, string>
     */
    public static function coresTexto(): array
    {
        $cores = [self::COR_PADRAO_TEXTO];

        foreach (self::all() as $preset) {
            $cores[] = $preset['cor_texto'];
        }

        return array_values(array_unique($cores));
    }

    /**
     * @return array<int, array{
     *   chave: string,
     *   label: string,
     *   aliases: array<int, string>,
     *   cor_fundo: string,
     *   cor_texto: string
     * }>
     */
    public static function presetsParaLookups(): array
    {
        return array_map(static function (array $preset) {
            return [
                'chave' => $preset['chave'],
                'label' => $preset['label'],
                'aliases' => $preset['aliases'],
                'cor_fundo' => $preset['cor_fundo'],
                'cor_texto' => $preset['cor_texto'],
            ];
        }, self::all());
    }

    /**
     * @param array<int, string> $aliases
     * @return array{chave: string, label: string, aliases: array<int, string>, cor_fundo: string, cor_texto: string}
     */
    private static function preset(
        string $chave,
        string $label,
        string $corFundo,
        string $corTexto,
        array $aliases
    ): array {
        return [
            'chave' => $chave,
            'label' => $label,
            'aliases' => $aliases,
            'cor_fundo' => strtolower($corFundo),
            'cor_texto' => strtolower($corTexto),
        ];
    }

    public static function matches(string $haystack, string $alias): bool
    {
        if ($haystack === $alias) {
            return true;
        }

        $quoted = preg_quote($alias, '/');

        if (mb_strlen($alias) <= 3) {
            return (bool) preg_match('/\b' . $quoted . '\b/u', $haystack);
        }

        return str_contains($haystack, $alias)
            || (bool) preg_match('/\b' . $quoted . '\b/u', $haystack);
    }

    public static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
            "'" => '', '’' => '',
        ]);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
