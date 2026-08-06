<?php

namespace App\Services\Pdf;

/**
 * Catálogo de regras de senha de PDF de fatura por emissor.
 * O cartão guarda o código (`senha_pdf_regra`); o front usa `orientacao` no modal.
 */
class PdfSenhaRegra
{
    public const CPF_CNPJ_6_DIGITOS = 'cpf_cnpj_6_digitos';

    public const CODIGO_SENHA_NECESSARIA = 'pdf_senha_necessaria';

    public const CODIGO_SENHA_INCORRETA = 'pdf_senha_incorreta';

    /**
     * @return array<int, array{
     *   value: string,
     *   label: string,
     *   orientacao: string,
     *   bancos_sugeridos: array<int, string>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'value' => self::CPF_CNPJ_6_DIGITOS,
                'label' => '6 primeiros dígitos do CPF/CNPJ',
                'orientacao' => 'Informe os 6 primeiros dígitos do CPF ou CNPJ do titular. Essa é a senha usada nas faturas do C6 Bank.',
                'bancos_sugeridos' => ['C6', 'C6 Bank', 'C6Bank'],
            ],
        ];
    }

    public static function isValid(?string $codigo): bool
    {
        if ($codigo === null || $codigo === '') {
            return true;
        }

        return collect(self::all())->contains(fn (array $r) => $r['value'] === $codigo);
    }

    public static function label(?string $codigo): ?string
    {
        return self::find($codigo)['label'] ?? null;
    }

    public static function orientacao(?string $codigo): ?string
    {
        return self::find($codigo)['orientacao'] ?? null;
    }

    /**
     * Sugere regra a partir do nome do banco do cartão (ex.: "C6", "C6 Bank").
     */
    public static function sugerirPorBanco(?string $banco): ?string
    {
        if ($banco === null || trim($banco) === '') {
            return null;
        }

        $normalizado = self::normalizeBanco($banco);

        foreach (self::all() as $regra) {
            foreach ($regra['bancos_sugeridos'] as $sugerido) {
                if (self::normalizeBanco($sugerido) === $normalizado) {
                    return $regra['value'];
                }
                // Match parcial: "c6 bank cartão" contém "c6"
                if (str_contains($normalizado, self::normalizeBanco($sugerido))) {
                    return $regra['value'];
                }
            }
        }

        // C6 costuma vir só como "C6" / "c6bank"
        if (preg_match('/\bc6\b/', $normalizado) || str_contains($normalizado, 'c6bank')) {
            return self::CPF_CNPJ_6_DIGITOS;
        }

        return null;
    }

    /**
     * @return array{value: string, label: string, orientacao: string, bancos_sugeridos: array<int, string>}|null
     */
    public static function find(?string $codigo): ?array
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        foreach (self::all() as $regra) {
            if ($regra['value'] === $codigo) {
                return $regra;
            }
        }

        return null;
    }

    private static function normalizeBanco(string $banco): string
    {
        $banco = mb_strtolower(trim($banco));
        $banco = preg_replace('/\s+/', ' ', $banco) ?? $banco;

        return $banco;
    }
}
