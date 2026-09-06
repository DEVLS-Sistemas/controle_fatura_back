<?php

namespace App\Enums;

use App\Models\Fatura;
use App\Models\Transacao;

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

    /**
     * Model dono do registro apontado por referencia_id.
     *
     * @return class-string
     */
    public function modelo(): string
    {
        return match ($this) {
            self::Fatura => Fatura::class,
            self::Compra => Transacao::class,
        };
    }
}
