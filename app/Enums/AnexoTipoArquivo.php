<?php

namespace App\Enums;

enum AnexoTipoArquivo: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Imagem = 'imagem';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
            self::Imagem => 'Imagem',
            self::Outro => 'Outro',
        };
    }
}
