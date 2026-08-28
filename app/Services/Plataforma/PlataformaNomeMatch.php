<?php

namespace App\Services\Plataforma;

/**
 * Infere a plataforma de compra a partir do nome da maquininha
 * (ex.: Mercadolivre*Mercadol → Mercado Livre, Shopee *Raceplast → Shopee).
 */
class PlataformaNomeMatch
{
    /** Compactos genéricos demais para auto-vincular. */
    public const IGNORAR = [
        'lojafisica',
        'sitedaloja',
        'outros',
    ];

    /**
     * Apelidos conhecidos, chave = nome compacto da plataforma padrão.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        'mercadolivre' => ['mercadolivre', 'mercadol'],
        'shopee' => ['shopee'],
        'amazon' => ['amazon', 'amzn'],
        'aliexpress' => ['aliexpress', 'aliexpre'],
        'ifood' => ['ifood'],
        'magalu' => ['magalu', 'magazineluiza'],
        'shein' => ['shein'],
        'americanas' => ['americanas'],
        'rappi' => ['rappi'],
        'temu' => ['temu'],
        'magazineluiza' => ['magalu', 'magazineluiza'],
    ];

    public static function compact(string $nome): string
    {
        $nome = mb_strtolower(trim($nome), 'UTF-8');
        $nome = self::semAcentos($nome);
        $nome = preg_replace('/[^a-z0-9]/', '', $nome) ?? $nome;

        return $nome;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $nome): array
    {
        $nome = mb_strtolower(trim($nome), 'UTF-8');
        $nome = self::semAcentos($nome);
        $parts = preg_split('/[^a-z0-9]+/', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) >= 4) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  iterable<mixed>  $plataformas  itens com `id` e `nome`
     */
    public static function inferirId(string $estabelecimentoNome, iterable $plataformas): ?int
    {
        $tokens = self::tokens($estabelecimentoNome);
        $haystack = self::compact($estabelecimentoNome);
        if ($haystack === '' && $tokens === []) {
            return null;
        }

        $melhorId = null;
        $melhorLen = 0;

        foreach ($plataformas as $plataforma) {
            $id = self::idDe($plataforma);
            $nome = self::nomeDe($plataforma);
            if ($id === null || $nome === '') {
                continue;
            }

            $compacto = self::compact($nome);
            if ($compacto === '' || in_array($compacto, self::IGNORAR, true)) {
                continue;
            }

            $needles = array_values(array_unique(array_merge(
                [$compacto],
                self::ALIASES[$compacto] ?? []
            )));

            foreach ($needles as $needle) {
                $len = strlen($needle);
                if ($len < 4 || $len <= $melhorLen) {
                    continue;
                }
                if (self::casa($tokens, $haystack, $needle)) {
                    $melhorId = $id;
                    $melhorLen = $len;
                }
            }
        }

        return $melhorId;
    }

    /**
     * @param  list<string>  $tokens
     */
    public static function casa(array $tokens, string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        foreach ($tokens as $token) {
            if (self::tokenCasaNeedle($token, $needle)) {
                return true;
            }
        }

        // "MercadolivreMercadol" sem separador: só needles longos (evita amazon ⊂ amazonas).
        if (strlen($needle) >= 8 && $haystack !== '' && str_contains($haystack, $needle)) {
            return true;
        }

        return false;
    }

    public static function tokenCasaNeedle(string $token, string $needle): bool
    {
        if ($token === '' || $needle === '') {
            return false;
        }

        if ($token === $needle) {
            return true;
        }

        // Truncamento da maquininha: Mercadol ⊂ MercadoLivre
        if (strlen($token) >= 6 && strlen($token) < strlen($needle) && str_starts_with($needle, $token)) {
            return true;
        }

        // Prefixo da plataforma + dígitos (Shopee123)
        if (strlen($needle) >= 5 && str_starts_with($token, $needle)) {
            $resto = substr($token, strlen($needle));

            return $resto === '' || ctype_digit($resto);
        }

        return false;
    }

    /**
     * @param  mixed  $plataforma
     */
    private static function idDe(mixed $plataforma): ?int
    {
        if (is_array($plataforma)) {
            $id = $plataforma['id'] ?? null;
        } elseif (is_object($plataforma)) {
            $id = $plataforma->id ?? null;
        } else {
            return null;
        }

        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param  mixed  $plataforma
     */
    private static function nomeDe(mixed $plataforma): string
    {
        if (is_array($plataforma)) {
            return trim((string) ($plataforma['nome'] ?? ''));
        }
        if (is_object($plataforma)) {
            return trim((string) ($plataforma->nome ?? ''));
        }

        return '';
    }

    private static function semAcentos(string $texto): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        return strtr($texto, $map);
    }
}
