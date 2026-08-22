<?php

namespace App\Services\Cartao;

/**
 * Cores oficiais (principal + secundária) das bandeiras de cartão.
 * Usado no cadastro (auto-aplicar) e nos lookups do formulário (chips).
 */
class BandeiraCoresPreset
{
    public const COR_PADRAO_PRINCIPAL = '#e5e7eb';

    public const COR_PADRAO_SECUNDARIA = '#9ca3af';

    public const COR_PADRAO_LABEL = 'Outra';

    public const CHAVE_PADRAO = 'outra';

    /**
     * @return array<int, array{
     *   chave: string,
     *   label: string,
     *   aliases: array<int, string>,
     *   cor_principal: string,
     *   cor_secundaria: string
     * }>
     */
    public static function all(): array
    {
        return [
            self::preset('visa', 'Visa', '#1a1f71', '#f7b600', ['visa']),
            self::preset('mastercard', 'Mastercard', '#eb001b', '#ff5f00', ['mastercard', 'master card', 'master']),
            self::preset('elo', 'Elo', '#000000', '#ffcb05', ['elo']),
            self::preset('amex', 'American Express', '#006fcf', '#ffffff', ['american express', 'amex']),
            self::preset('hipercard', 'Hipercard', '#e31837', '#ffffff', ['hipercard', 'hiper']),
            self::preset('diners', 'Diners Club', '#0079be', '#ffffff', ['diners club', 'dinersclub', 'diners']),
            self::preset('discover', 'Discover', '#ff6000', '#000000', ['discover']),
            self::preset('jcb', 'JCB', '#00a94f', '#e31837', ['jcb']),
            self::preset('unionpay', 'UnionPay', '#d50000', '#004a99', ['unionpay', 'union pay']),
            self::preset('maestro', 'Maestro', '#ed1c24', '#0099df', ['maestro']),
            self::preset('banricompras', 'Banricompras', '#0054a6', '#e31b23', ['banricompras', 'banri']),
            self::preset('aura', 'Aura', '#0066b3', '#ffffff', ['aura']),
            self::preset('cabal', 'Cabal', '#0066a1', '#e21e2b', ['cabal']),
            self::preset('sorocred', 'Sorocred', '#0057a8', '#e30613', ['sorocred']),
        ];
    }

    /**
     * Labels aceitos no select / validação (inclui `Amex` legado e `Outra`).
     *
     * @return array<int, string>
     */
    public static function nomes(): array
    {
        $nomes = array_column(self::all(), 'label');
        $nomes[] = 'Amex';
        $nomes[] = self::COR_PADRAO_LABEL;

        return array_values(array_unique($nomes));
    }

    /**
     * Labels do select do cadastro: oficiais + Outra (sem duplicar Amex).
     *
     * @return array<int, string>
     */
    public static function nomesLookups(): array
    {
        $nomes = array_column(self::all(), 'label');
        $nomes[] = self::COR_PADRAO_LABEL;

        return $nomes;
    }

    public static function isValida(string $nome): bool
    {
        return in_array($nome, self::nomes(), true);
    }

    /**
     * @return array{
     *   chave: string,
     *   label: string,
     *   cor_principal: string,
     *   cor_secundaria: string,
     *   padrao: bool
     * }
     */
    public static function padrao(): array
    {
        return [
            'chave' => self::CHAVE_PADRAO,
            'label' => self::COR_PADRAO_LABEL,
            'cor_principal' => self::COR_PADRAO_PRINCIPAL,
            'cor_secundaria' => self::COR_PADRAO_SECUNDARIA,
            'padrao' => true,
        ];
    }

    /**
     * @return array{
     *   chave: string,
     *   label: string,
     *   cor_principal: string,
     *   cor_secundaria: string,
     *   padrao: bool
     * }
     */
    public static function resolver(?string $nome): array
    {
        $haystack = CartaoCoresPreset::normalize($nome);
        if ($haystack === '') {
            return self::padrao();
        }

        $melhor = null;
        $melhorLen = -1;

        foreach (self::all() as $preset) {
            foreach ($preset['aliases'] as $alias) {
                $aliasNorm = CartaoCoresPreset::normalize($alias);
                if ($aliasNorm === '') {
                    continue;
                }

                if (!CartaoCoresPreset::matches($haystack, $aliasNorm)) {
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
            'cor_principal' => $melhor['cor_principal'],
            'cor_secundaria' => $melhor['cor_secundaria'],
            'padrao' => false,
        ];
    }

    /**
     * Cores para resposta de API: override persistido, senão preset do nome.
     *
     * @return array{
     *   cor_principal: string,
     *   cor_secundaria: string,
     *   bandeira_chave: string,
     *   bandeira_padrao: bool
     * }
     */
    public static function anexar(
        ?string $nome,
        mixed $corPrincipal = null,
        mixed $corSecundaria = null
    ): array {
        $preset = self::resolver($nome);
        $principal = self::normalizeHex($corPrincipal);
        $secundaria = self::normalizeHex($corSecundaria);

        return [
            'cor_principal' => $principal ?? $preset['cor_principal'],
            'cor_secundaria' => $secundaria ?? $preset['cor_secundaria'],
            'bandeira_chave' => $preset['chave'],
            'bandeira_padrao' => $preset['padrao'],
        ];
    }

    /**
     * @return array<int, array{
     *   chave: string,
     *   label: string,
     *   cor_principal: string,
     *   cor_secundaria: string,
     *   padrao: bool
     * }>
     */
    public static function paresParaLookups(): array
    {
        $pares = [];

        foreach (self::all() as $preset) {
            $pares[] = [
                'chave' => $preset['chave'],
                'label' => $preset['label'],
                'cor_principal' => $preset['cor_principal'],
                'cor_secundaria' => $preset['cor_secundaria'],
                'padrao' => false,
            ];
        }

        $pares[] = self::padrao();

        return $pares;
    }

    /**
     * @return array<int, array{
     *   chave: string,
     *   label: string,
     *   aliases: array<int, string>,
     *   cor_principal: string,
     *   cor_secundaria: string
     * }>
     */
    public static function presetsParaLookups(): array
    {
        return array_map(static function (array $preset) {
            return [
                'chave' => $preset['chave'],
                'label' => $preset['label'],
                'aliases' => $preset['aliases'],
                'cor_principal' => $preset['cor_principal'],
                'cor_secundaria' => $preset['cor_secundaria'],
            ];
        }, self::all());
    }

    /**
     * @param array<int, string> $aliases
     * @return array{chave: string, label: string, aliases: array<int, string>, cor_principal: string, cor_secundaria: string}
     */
    private static function preset(
        string $chave,
        string $label,
        string $corPrincipal,
        string $corSecundaria,
        array $aliases
    ): array {
        return [
            'chave' => $chave,
            'label' => $label,
            'aliases' => $aliases,
            'cor_principal' => strtolower($corPrincipal),
            'cor_secundaria' => strtolower($corSecundaria),
        ];
    }

    private static function normalizeHex(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cor = strtolower(trim((string) $value));
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $cor)) {
            return null;
        }

        return $cor;
    }
}
