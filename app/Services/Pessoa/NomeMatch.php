<?php

namespace App\Services\Pessoa;

/**
 * Comparação frouxa de nomes (cadastro × titular da fatura).
 * Não exige igualdade: bancos abreviam (LEONARDO S FERREIRA vs Leonardo da Silva Ferreira).
 */
class NomeMatch
{
    public static function normalize(string $nome): string
    {
        $nome = mb_strtoupper(trim($nome), 'UTF-8');
        $nome = self::semAcentos($nome);
        $nome = preg_replace('/[^A-Z0-9\s]/', ' ', $nome) ?? $nome;
        $nome = preg_replace('/\s+/', ' ', $nome) ?? $nome;

        return trim($nome);
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $nome): array
    {
        $norm = self::normalize($nome);
        if ($norm === '') {
            return [];
        }

        $stop = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E'];
        $parts = explode(' ', $norm);

        return array_values(array_filter(
            $parts,
            static fn (string $t) => $t !== '' && !in_array($t, $stop, true) && strlen($t) > 1
        ));
    }

    public static function matches(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        if ($na === '' || $nb === '') {
            return false;
        }

        if ($na === $nb) {
            return true;
        }

        // Um contém o outro (abreviado vs completo).
        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return true;
        }

        $ta = self::tokens($a);
        $tb = self::tokens($b);

        if ($ta === [] || $tb === []) {
            return false;
        }

        // Primeiro nome igual + pelo menos um sobrenome em comum.
        if ($ta[0] !== $tb[0]) {
            // Inicial do meio: "LEONARDO S FERREIRA" vs "LEONARDO SILVA FERREIRA"
            // tokens já sem stopwords: LEONARDO, S, FERREIRA — S tem len 1 e foi filtrado.
            // Comparar primeiro + último.
        }

        $firstOk = $ta[0] === $tb[0]
            || (strlen($ta[0]) === 1 && str_starts_with($tb[0], $ta[0]))
            || (strlen($tb[0]) === 1 && str_starts_with($ta[0], $tb[0]));

        if (!$firstOk) {
            return false;
        }

        $sa = array_slice($ta, 1);
        $sb = array_slice($tb, 1);

        if ($sa === [] || $sb === []) {
            // Só primeiro nome em comum e um dos lados não tem sobrenome → fraco; exige igualdade já coberta.
            return count($ta) === 1 && count($tb) === 1;
        }

        foreach ($sa as $tokenA) {
            foreach ($sb as $tokenB) {
                if ($tokenA === $tokenB) {
                    return true;
                }
                if (strlen($tokenA) === 1 && str_starts_with($tokenB, $tokenA)) {
                    return true;
                }
                if (strlen($tokenB) === 1 && str_starts_with($tokenA, $tokenB)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $candidatos
     */
    public static function matchesAny(string $nome, array $candidatos): bool
    {
        foreach ($candidatos as $c) {
            if (self::matches($nome, (string) $c)) {
                return true;
            }
        }

        return false;
    }

    private static function semAcentos(string $texto): string
    {
        $map = [
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];

        return strtr($texto, $map);
    }
}
