<?php

namespace App\Support;

use App\Models\Anexo;
use Illuminate\Support\Str;

class AnexoNomeBlob
{
    public const NOME_MAXIMO = 180;

    public static function deAnexo(Anexo $anexo): string
    {
        $extensao = $anexo->extensao ?: 'bin';

        return self::sanitizar((string) $anexo->nome_original, $extensao, (int) $anexo->id);
    }

    public static function sanitizar(string $nomeOriginal, string $extensao, int $idFallback): string
    {
        $extensao = strtolower(ltrim($extensao, '.')) ?: 'bin';
        $base = basename(str_replace('\\', '/', $nomeOriginal));
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $stem = Str::slug($stem, '-');

        if ($stem === '') {
            $stem = (string) max(0, $idFallback);
        }

        if (strlen($stem) > self::NOME_MAXIMO) {
            $stem = rtrim(substr($stem, 0, self::NOME_MAXIMO), '-');
        }

        return $stem.'.'.$extensao;
    }
}
