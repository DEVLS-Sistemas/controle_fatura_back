<?php

namespace App\Services\Categoria;

use Exception;

/**
 * Paleta tema do cadastro de categoria e coalesce de cor nos gráficos.
 * `temas[].variacoes` = preview de 5 tons mais claros (etapa 2).
 */
class CategoriaCoresTema
{
    public const COR_PADRAO = '#000000';

    public const COR_SEM_CATEGORIA = '#9ca3af';

    public const CHAVE_PADRAO = 'preto';

    /**
     * @return array<int, array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            self::tema('preto', 'Preto', '#000000', true),
            self::tema('vermelho', 'Vermelho', '#ef4444'),
            self::tema('laranja', 'Laranja', '#f59e0b'),
            self::tema('verde', 'Verde', '#22c55e'),
            self::tema('azul', 'Azul', '#3b82f6'),
            self::tema('roxo', 'Roxo', '#8b5cf6'),
            self::tema('rosa', 'Rosa', '#ec4899'),
            self::tema('cinza', 'Cinza', '#6b7280'),
            self::tema('teal', 'Teal', '#14b8a6'),
        ];
    }

    /**
     * @return array{cor_padrao: string, cores: array<int, string>, temas: array<int, array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}>}
     */
    public static function lookups(): array
    {
        $temas = self::all();

        return [
            'cor_padrao' => self::COR_PADRAO,
            'cores' => array_column($temas, 'hex'),
            'temas' => $temas,
        ];
    }

    /**
     * HEX válido ou null (não defaulta preto). Usado no pivot / fallback de sub.
     */
    public static function hexValido(mixed $hex): ?string
    {
        return self::tryParse($hex);
    }

    /**
     * Leitura: sempre um HEX válido. Vazio ou lixo legado → preto.
     */
    public static function normalizar(mixed $hex): string
    {
        return self::hexValido($hex) ?? self::COR_PADRAO;
    }

    /**
     * Escrita: vazio/omitido → preto. HEX inválido → 422.
     */
    public static function parseParaGravar(mixed $hex): string
    {
        if ($hex === null || $hex === '') {
            return self::COR_PADRAO;
        }

        if (is_string($hex) && trim($hex) === '') {
            return self::COR_PADRAO;
        }

        $parseado = self::tryParse($hex);
        if ($parseado === null) {
            throw new Exception('Cor deve ser um hexadecimal válido (ex.: #3b82f6)', 422);
        }

        return $parseado;
    }

    /**
     * Cor de cadastro (estabelecimento padrão, lookup): sem categoria → null; sem HEX → preto.
     */
    public static function corCadastroOuNull(mixed $hex, mixed $categoriaId): ?string
    {
        if ($categoriaId === null || $categoriaId === '' || (int) $categoriaId === 0) {
            return null;
        }

        return self::normalizar($hex);
    }

    /**
     * Pinta `cor` em models/arrays de categoria `{id, nome, cor}`.
     *
     * @param iterable<mixed> $categorias
     * @return iterable<mixed>
     */
    public static function pintarLookups(iterable $categorias): iterable
    {
        foreach ($categorias as $categoria) {
            if (is_array($categoria)) {
                $categoria['cor'] = self::normalizar($categoria['cor'] ?? null);
            } elseif (is_object($categoria)) {
                $categoria->cor = self::normalizar($categoria->cor ?? null);
            }
        }

        return $categorias;
    }

    /**
     * Cor da fatia/bolinha: categoria cadastrada usa o tema (preto se vazio);
     * bucket sintético "Sem categoria" usa cinza.
     */
    public static function corParaGrafico(mixed $hex, int|string|null $categoriaId): string
    {
        if ($categoriaId === null || (int) $categoriaId === 0) {
            return self::COR_SEM_CATEGORIA;
        }

        return self::normalizar($hex);
    }

    /**
     * @return array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}
     */
    private static function tema(string $chave, string $label, string $hex, bool $padrao = false): array
    {
        $hex = strtolower($hex);

        return [
            'chave' => $chave,
            'label' => $label,
            'hex' => $hex,
            'padrao' => $padrao,
            'variacoes' => CategoriaCorVariacao::variacoes($hex, CategoriaCorVariacao::PREVIEW),
        ];
    }

    private static function tryParse(mixed $hex): ?string
    {
        if (!is_string($hex) && !is_int($hex)) {
            return null;
        }

        $cor = strtolower(trim((string) $hex));
        if ($cor === '') {
            return null;
        }

        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $cor)) {
            return null;
        }

        if (strlen($cor) === 4) {
            return '#' . $cor[1] . $cor[1] . $cor[2] . $cor[2] . $cor[3] . $cor[3];
        }

        return $cor;
    }
}
