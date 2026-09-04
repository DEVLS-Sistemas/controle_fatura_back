<?php

namespace App\Enums;

enum AnexoStatus: string
{
    case Pendente = 'pendente';
    case Enviando = 'enviando';
    case Enviado = 'enviado';
    case Falhou = 'falhou';
    case Excluido = 'excluido';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Enviando => 'Enviando',
            self::Enviado => 'Enviado',
            self::Falhou => 'Falhou',
            self::Excluido => 'Excluído',
        };
    }
}
