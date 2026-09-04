<?php

namespace App\Enums;

enum AnexoOrigem: string
{
    /** Tela da fatura (PDF/CSV). Novas telas entram como cases extras. */
    case Fatura = 'fatura';
    case Compra = 'compra';

    public function label(): string
    {
        return match ($this) {
            self::Fatura => 'Fatura',
            self::Compra => 'Compra',
        };
    }
}
