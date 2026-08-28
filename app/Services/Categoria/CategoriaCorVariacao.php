<?php

namespace App\Services\Categoria;

use App\Models\Categoria;
use Illuminate\Support\Facades\DB;

/**
 * Tons mais claros que a cor tema da categoria, para pintar subcategorias.
 */
class CategoriaCorVariacao
{
    public const PREVIEW = 5;

    public const L_MAX = 0.88;

    /**
     * @return array<int, string>
     */
    public static function variacoes(string $tema, int $quantidade): array
    {
        $tema = CategoriaCoresTema::normalizar($tema);
        if ($quantidade <= 0) {
            return [];
        }

        [$h, $s, $l] = self::rgbToHsl(...self::hexToRgb($tema));
        $lTeto = min(0.92, max(self::L_MAX, $l + 0.04));
        $span = max(0.04, $lTeto - $l);
        $claro = $l >= 0.72;
        $luminanciaTema = self::luminanciaRelativa($tema);

        $resultado = [];
        $usadas = [$tema => true];

        for ($i = 1; $i <= $quantidade; $i++) {
            $lNovo = min($lTeto, $l + $span * ($i / $quantidade));
            if ($lNovo <= $l) {
                $lNovo = min(0.92, $l + 0.04 * $i);
            }

            $hNovo = $claro ? $h + (($i % 2 === 0) ? 6.0 : -6.0) : $h;
            $sNovo = $s * (1 - 0.15 * ($i / $quantidade));
            $hex = self::rgbToHex(...self::hslToRgb($hNovo, $sNovo, $lNovo));

            $tentativa = 0;
            while (
                (isset($usadas[$hex]) || self::luminanciaRelativa($hex) <= $luminanciaTema)
                && $tentativa < 25
            ) {
                $hex = self::misturarComBranco($tema, min(0.85, 0.08 * $i + 0.03 * $tentativa));
                $tentativa++;
            }

            $usadas[$hex] = true;
            $resultado[] = $hex;
        }

        return $resultado;
    }

    /**
     * Próximo tom ainda não usado nesta categoria.
     *
     * @param array<int, mixed> $coresJaUsadas
     */
    public static function proxima(string $tema, array $coresJaUsadas): string
    {
        $tema = CategoriaCoresTema::normalizar($tema);
        $usadas = [$tema => true];
        foreach ($coresJaUsadas as $cor) {
            $hex = CategoriaCoresTema::hexValido($cor);
            if ($hex !== null) {
                $usadas[$hex] = true;
            }
        }

        $n = max(8, count($coresJaUsadas) + 4);
        foreach (self::variacoes($tema, $n) as $hex) {
            if (!isset($usadas[$hex])) {
                return $hex;
            }
        }

        return self::misturarComBranco($tema, min(0.85, 0.12 + 0.05 * count($coresJaUsadas)));
    }

    /**
     * Mapa estável: menor `subcategoria_id` → 1º degrau.
     *
     * @param array<int, int|string> $subcategoriaIds
     * @return array<int, string>
     */
    public static function mapaPorIds(string $tema, array $subcategoriaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $subcategoriaIds)));
        sort($ids);
        $cores = self::variacoes($tema, count($ids));
        $mapa = [];
        foreach ($ids as $i => $id) {
            $mapa[$id] = $cores[$i] ?? self::proxima($tema, array_values($mapa));
        }

        return $mapa;
    }

    /**
     * Preenche só vínculos sem HEX. Não sobrescreve cor já gravada.
     *
     * @param array<int, array{subcategoria_id: int, cor: mixed}> $pivots ordenados por subcategoria_id
     * @return array<int, array{subcategoria_id: int, cor: string}>
     */
    public static function planoBackfillVinculos(string $tema, array $pivots): array
    {
        $tema = CategoriaCoresTema::normalizar($tema);
        $usadas = [];
        $updates = [];

        foreach ($pivots as $pivot) {
            $hex = CategoriaCoresTema::hexValido($pivot['cor'] ?? null);
            if ($hex !== null) {
                $usadas[] = $hex;
                continue;
            }

            $nova = self::proxima($tema, $usadas);
            $usadas[] = $nova;
            $updates[] = [
                'subcategoria_id' => (int) $pivot['subcategoria_id'],
                'cor' => $nova,
            ];
        }

        return $updates;
    }

    /**
     * HEX da sub na leitura: pivot ou 1ª variação do tema.
     */
    public static function corLeitura(mixed $corPivot, string $tema): string
    {
        return CategoriaCoresTema::hexValido($corPivot)
            ?? (self::variacoes($tema, 1)[0] ?? CategoriaCoresTema::normalizar($tema));
    }

    /**
     * Idempotente: categorias sem cor → preto; pivots vazios recebem variação.
     *
     * @return array{categorias: int, vinculos: int}
     */
    public static function backfill(?int $userId = null, bool $dryRun = false): array
    {
        $query = Categoria::query();
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $categorias = 0;
        $vinculos = 0;

        foreach ($query->orderBy('id')->get(['id', 'cor']) as $categoria) {
            $tema = CategoriaCoresTema::hexValido($categoria->cor);
            if ($tema === null) {
                $categorias++;
                $tema = CategoriaCoresTema::COR_PADRAO;
                if (!$dryRun) {
                    $categoria->cor = $tema;
                    $categoria->save();
                }
            }

            $pivots = DB::table('categoria_subcategoria')
                ->where('categoria_id', $categoria->id)
                ->orderBy('subcategoria_id')
                ->get(['id', 'subcategoria_id', 'cor']);

            $plano = self::planoBackfillVinculos($tema, $pivots->map(fn ($p) => [
                'subcategoria_id' => (int) $p->subcategoria_id,
                'cor' => $p->cor,
            ])->all());

            $porSub = [];
            foreach ($plano as $item) {
                $porSub[$item['subcategoria_id']] = $item['cor'];
            }

            foreach ($pivots as $pivot) {
                $subId = (int) $pivot->subcategoria_id;
                if (!isset($porSub[$subId])) {
                    continue;
                }
                $vinculos++;
                if (!$dryRun) {
                    DB::table('categoria_subcategoria')
                        ->where('id', $pivot->id)
                        ->update(['cor' => $porSub[$subId], 'updated_at' => now()]);
                }
            }
        }

        return [
            'categorias' => $categorias,
            'vinculos' => $vinculos,
        ];
    }

    public static function regenerarCategoria(int $categoriaId): void
    {
        $tema = CategoriaCoresTema::normalizar(
            Categoria::query()->where('id', $categoriaId)->value('cor')
        );
        $ids = DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->orderBy('subcategoria_id')
            ->pluck('subcategoria_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (self::mapaPorIds($tema, $ids) as $subId => $hex) {
            DB::table('categoria_subcategoria')
                ->where('categoria_id', $categoriaId)
                ->where('subcategoria_id', $subId)
                ->update(['cor' => $hex, 'updated_at' => now()]);
        }
    }

    public static function garantirVinculo(int $categoriaId, int $subcategoriaId): void
    {
        $pivot = DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->where('subcategoria_id', $subcategoriaId)
            ->first();

        if (!$pivot || CategoriaCoresTema::hexValido($pivot->cor) !== null) {
            return;
        }

        $tema = CategoriaCoresTema::normalizar(
            Categoria::query()->where('id', $categoriaId)->value('cor')
        );
        $usadas = DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->where('id', '!=', $pivot->id)
            ->whereNotNull('cor')
            ->pluck('cor')
            ->all();

        DB::table('categoria_subcategoria')
            ->where('id', $pivot->id)
            ->update([
                'cor' => self::proxima($tema, $usadas),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<int, int|string> $categoriaIds
     */
    public static function garantirVinculos(int $subcategoriaId, array $categoriaIds): void
    {
        foreach (array_unique($categoriaIds) as $categoriaId) {
            self::garantirVinculo((int) $categoriaId, $subcategoriaId);
        }
    }

    public static function luminanciaRelativa(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $canal = static function (int $value): float {
            $s = $value / 255;
            return $s <= 0.03928 ? $s / 12.92 : ((float) (($s + 0.055) / 1.055) ** 2.4);
        };

        return 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = CategoriaCoresTema::normalizar($hex);

        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b))
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;
        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d < 0.00001) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2.0 - $max - $min) : $d / ($max + $min);
        if ($max === $rf) {
            $h = ($gf - $bf) / $d + ($gf < $bf ? 6 : 0);
        } elseif ($max === $gf) {
            $h = ($bf - $rf) / $d + 2;
        } else {
            $h = ($rf - $gf) / $d + 4;
        }

        return [$h * 60, $s, $l];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod($h + 360, 360);
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        if ($s < 0.00001) {
            $v = (int) round($l * 255);

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hk = $h / 360;

        return [
            (int) round(self::hueToRgb($p, $q, $hk + 1 / 3) * 255),
            (int) round(self::hueToRgb($p, $q, $hk) * 255),
            (int) round(self::hueToRgb($p, $q, $hk - 1 / 3) * 255),
        ];
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }

    private static function misturarComBranco(string $hex, float $t): string
    {
        $t = max(0.05, min(0.85, $t));
        [$r, $g, $b] = self::hexToRgb($hex);

        return self::rgbToHex(
            (int) round($r + (255 - $r) * $t),
            (int) round($g + (255 - $g) * $t),
            (int) round($b + (255 - $b) * $t)
        );
    }
}
