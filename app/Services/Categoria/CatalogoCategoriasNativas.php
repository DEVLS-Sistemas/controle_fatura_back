<?php

namespace App\Services\Categoria;

use App\Models\Categoria;
use App\Models\Subcategoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo nativo de categorias e subcategorias, por usuário.
 * Reutiliza pelo nome (case-insensitive), não sobrescreve edição/inativação
 * e não restaura soft delete.
 */
class CatalogoCategoriasNativas
{
    /** @var array<int, array{nome: string, cor: string, subcategorias: array<int, array{nome: string, cor: string}>}>|null */
    private static ?array $categorias = null;

    /**
     * @return array<int, array{nome: string, cor: string, subcategorias: array<int, array{nome: string, cor: string}>}>
     */
    public static function categorias(): array
    {
        if (self::$categorias === null) {
            $json = json_decode(
                (string) file_get_contents(__DIR__.'/catalogo-categorias-nativas.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::$categorias = $json['categorias'];
        }

        return self::$categorias;
    }

    public static function aplicarParaUser(int $userId): void
    {
        $categorias = self::indexarPorNome(
            Categoria::withTrashed()->where('user_id', $userId)->orderBy('id')->get()
        );
        $subcategorias = self::indexarPorNome(
            Subcategoria::withTrashed()->where('user_id', $userId)->orderBy('id')->get()
        );

        foreach (self::categorias() as $item) {
            $chave = self::chave($item['nome']);
            $categoria = $categorias[$chave] ?? null;

            if ($categoria === null) {
                $categoria = Categoria::create([
                    'user_id' => $userId,
                    'nome' => $item['nome'],
                    'cor' => CategoriaCoresTema::parseParaGravar($item['cor']),
                    'ativo' => true,
                ]);
                $categorias[$chave] = $categoria;
            }

            if ($categoria->trashed()) {
                continue;
            }

            foreach ($item['subcategorias'] as $subItem) {
                $chaveSub = self::chave($subItem['nome']);
                $sub = $subcategorias[$chaveSub] ?? null;

                if ($sub === null) {
                    $sub = Subcategoria::create([
                        'user_id' => $userId,
                        'nome' => $subItem['nome'],
                        'ativo' => true,
                    ]);
                    $subcategorias[$chaveSub] = $sub;
                }

                if ($sub->trashed()) {
                    continue;
                }

                $jaVinculada = DB::table('categoria_subcategoria')
                    ->where('categoria_id', $categoria->id)
                    ->where('subcategoria_id', $sub->id)
                    ->exists();

                if ($jaVinculada) {
                    continue;
                }

                $categoria->subcategorias()->attach($sub->id, [
                    'cor' => CategoriaCoresTema::parseParaGravar($subItem['cor']),
                ]);
            }
        }

        self::rebaixarCategoriasQueSaoSoSubcategoria($userId, $categorias, $subcategorias);
    }

    /**
     * Garante uma linha ativa para cada categoria do catálogo.
     * Se a única linha com aquele nome estiver excluída, restaura (a cor editada permanece)
     * e liga as subcategorias. Categorias fora do catálogo não são recriadas.
     */
    public static function garantirCategoriasAusentes(int $userId): void
    {
        $existentes = self::indexarPorNome(
            Categoria::withTrashed()->where('user_id', $userId)->orderBy('id')->get()
        );

        foreach (self::categorias() as $item) {
            $chave = self::chave($item['nome']);
            $categoria = $existentes[$chave] ?? null;

            if ($categoria instanceof Categoria && !$categoria->trashed()) {
                continue;
            }

            if ($categoria instanceof Categoria) {
                $categoria->restore();
                if (!$categoria->ativo) {
                    $categoria->ativo = true;
                    $categoria->save();
                }

                continue;
            }

            Categoria::create([
                'user_id' => $userId,
                'nome' => $item['nome'],
                'cor' => CategoriaCoresTema::parseParaGravar($item['cor']),
                'ativo' => true,
            ]);
        }

        self::aplicarParaUser($userId);
    }

    /**
     * Categoria antiga cujo nome existe só como subcategoria do catálogo
     * (ex.: Açougue) deixa de ser categoria. Compras e padrões passam
     * para a categoria pai e para essa subcategoria.
     *
     * @param array<string, Categoria|Subcategoria> $categorias
     * @param array<string, Categoria|Subcategoria> $subcategorias
     */
    private static function rebaixarCategoriasQueSaoSoSubcategoria(int $userId, array $categorias, array $subcategorias): void
    {
        $chavesCategoria = [];
        $paisPorSub = [];

        foreach (self::categorias() as $item) {
            $chaveCategoria = self::chave($item['nome']);
            $chavesCategoria[$chaveCategoria] = true;

            foreach ($item['subcategorias'] as $subItem) {
                $paisPorSub[self::chave($subItem['nome'])][$chaveCategoria] = true;
            }
        }

        $ativas = Categoria::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        foreach ($ativas as $categoria) {
            $chave = self::chave((string) $categoria->nome);

            if (isset($chavesCategoria[$chave])) {
                continue;
            }

            $pais = $paisPorSub[$chave] ?? [];
            if (count($pais) !== 1) {
                continue;
            }

            $pai = $categorias[array_key_first($pais)] ?? null;
            $sub = $subcategorias[$chave] ?? null;

            if (!$pai instanceof Categoria || $pai->trashed() || $pai->id === $categoria->id) {
                continue;
            }

            if (!$sub instanceof Subcategoria || $sub->trashed()) {
                continue;
            }

            self::moverReferencias($userId, (int) $categoria->id, (int) $pai->id, (int) $sub->id);
            $categoria->delete();
        }
    }

    private static function moverReferencias(int $userId, int $categoriaId, int $paiId, int $subId): void
    {
        DB::table('transacoes')
            ->where('user_id', $userId)
            ->where('categoria_id', $categoriaId)
            ->whereNull('subcategoria_id')
            ->update([
                'categoria_id' => $paiId,
                'subcategoria_id' => $subId,
            ]);

        DB::table('transacoes')
            ->where('user_id', $userId)
            ->where('categoria_id', $categoriaId)
            ->whereNotNull('subcategoria_id')
            ->update([
                'categoria_id' => $paiId,
            ]);

        DB::table('estabelecimentos')
            ->where('user_id', $userId)
            ->where('categoria_padrao_id', $categoriaId)
            ->whereNull('subcategoria_padrao_id')
            ->update([
                'categoria_padrao_id' => $paiId,
                'subcategoria_padrao_id' => $subId,
            ]);

        DB::table('estabelecimentos')
            ->where('user_id', $userId)
            ->where('categoria_padrao_id', $categoriaId)
            ->whereNotNull('subcategoria_padrao_id')
            ->update([
                'categoria_padrao_id' => $paiId,
            ]);
    }

    /**
     * Prefere a linha ativa quando o mesmo nome existe ativo e excluído.
     *
     * @param Collection<int, Categoria|Subcategoria> $registros
     * @return array<string, Categoria|Subcategoria>
     */
    private static function indexarPorNome(Collection $registros): array
    {
        $map = [];

        foreach ($registros as $registro) {
            $chave = self::chave((string) $registro->nome);
            $atual = $map[$chave] ?? null;

            if ($atual === null || ($atual->trashed() && !$registro->trashed())) {
                $map[$chave] = $registro;
            }
        }

        return $map;
    }

    private static function chave(string $nome): string
    {
        return mb_strtolower(trim($nome), 'UTF-8');
    }
}
