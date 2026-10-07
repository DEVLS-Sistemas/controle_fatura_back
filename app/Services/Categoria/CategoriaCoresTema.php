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

    /** @var array<int, array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}>|null */
    private static ?array $temasCatalogo = null;

    public const COR_SEM_CATEGORIA = '#9ca3af';

    public const CHAVE_PADRAO = 'preto';

    /**
     * @return array<int, array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}>
     */
    public static function all(): array
    {
        $temas = [self::tema('preto', 'Preto', '#000000', true)];
        $vistos = ['#000000' => true];

        foreach (self::temasCatalogo() as $tema) {
            $temas[] = $tema;
            $vistos[$tema['hex']] = true;
        }

        foreach (self::temasLegado() as [$chave, $label, $hex]) {
            $hex = strtolower($hex);
            if (isset($vistos[$hex])) {
                continue;
            }
            $temas[] = self::tema($chave, $label, $hex);
            $vistos[$hex] = true;
        }

        return $temas;
    }

    /**
     * Tons de subcategoria gravados no catálogo para esta cor tema.
     *
     * @return array<int, string>|null
     */
    public static function variacoesDoCatalogo(string $hex): ?array
    {
        $hex = self::hexValido($hex);
        if ($hex === null) {
            return null;
        }

        foreach (self::temasCatalogo() as $tema) {
            if ($tema['hex'] === $hex) {
                return $tema['variacoes'];
            }
        }

        return null;
    }

    /**
     * Uma cor tema por categoria nativa, com as cores das subcategorias como variações.
     *
     * @return array<int, array{chave: string, label: string, hex: string, padrao: bool, variacoes: array<int, string>}>
     */
    public static function temasCatalogo(): array
    {
        if (self::$temasCatalogo !== null) {
            return self::$temasCatalogo;
        }

        $temas = [];
        foreach (CatalogoCategoriasNativas::categorias() as $item) {
            $hex = self::hexValido($item['cor'] ?? null);
            if ($hex === null) {
                continue;
            }

            $variacoes = [];
            foreach ($item['subcategorias'] as $sub) {
                $subHex = self::hexValido($sub['cor'] ?? null);
                if ($subHex === null || $subHex === $hex || in_array($subHex, $variacoes, true)) {
                    continue;
                }
                $variacoes[] = $subHex;
            }

            $temas[] = [
                'chave' => self::chaveDeNome((string) $item['nome']),
                'label' => (string) $item['nome'],
                'hex' => $hex,
                'padrao' => false,
                'variacoes' => $variacoes,
            ];
        }

        self::$temasCatalogo = $temas;

        return $temas;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function temasLegado(): array
    {
        return [
            ['vermelho', 'Vermelho', '#ef4444'],
            ['laranja', 'Laranja', '#f59e0b'],
            ['verde', 'Verde', '#22c55e'],
            ['azul', 'Azul', '#3b82f6'],
            ['roxo', 'Roxo', '#8b5cf6'],
            ['rosa', 'Rosa', '#ec4899'],
            ['cinza', 'Cinza', '#6b7280'],
            ['teal', 'Teal', '#14b8a6'],
        ];
    }

    private static function chaveDeNome(string $nome): string
    {
        $mapa = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ];
        $chave = strtr(mb_strtolower(trim($nome), 'UTF-8'), $mapa);
        $chave = preg_replace('/[^a-z0-9]+/', '-', $chave) ?? $chave;

        return trim($chave, '-');
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
