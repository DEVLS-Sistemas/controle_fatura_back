<?php

namespace App\Utils;

class PncpLinkParser
{
    public static function parse(string $link): array
    {
        preg_match(
            '#/editais/(\d+)/(\d{4})/(\d+)#',
            $link,
            $matches
        );

        if (count($matches) !== 4) {
            throw new \InvalidArgumentException('Link do PNCP inválido');
        }

        [, $cnpj, $ano, $sequencial] = $matches;

        return [
            'cnpj'       => $cnpj,
            'ano'        => $ano,
            'sequencial' => ltrim($sequencial, '0'),
        ];
    }
}
