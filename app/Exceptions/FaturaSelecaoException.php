<?php

namespace App\Exceptions;

use Exception;

class FaturaSelecaoException extends Exception
{
    public const CODIGO_BANDEIRA = 'precisa_selecionar_bandeira';

    public const CODIGO_FINAL = 'precisa_selecionar_final';

    public const CODIGO_METADADOS = 'precisa_confirmar_metadados';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $codigo,
        public readonly array $payload = [],
        string $message = '',
        int $code = 422,
    ) {
        if ($message === '') {
            $message = match ($codigo) {
                self::CODIGO_FINAL => 'Selecione o final do cartão',
                self::CODIGO_METADADOS => 'Confirme o cartão, mês e ano identificados na fatura',
                default => 'Selecione a bandeira da fatura',
            };
        }

        parent::__construct($message, $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        return array_merge([
            'error' => true,
            'message' => $this->getMessage(),
            'codigo' => $this->codigo,
        ], $this->payload);
    }
}
