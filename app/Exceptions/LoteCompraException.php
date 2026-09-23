<?php

namespace App\Exceptions;

use Exception;

class LoteCompraException extends Exception
{
    public function __construct(
        string $message,
        int $code = 422,
        public readonly ?int $indice = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * @return array{error: true, message: string, indice?: int}
     */
    public function toResponseArray(): array
    {
        $body = [
            'error' => true,
            'message' => $this->getMessage(),
        ];

        if ($this->indice !== null) {
            $body['indice'] = $this->indice;
        }

        return $body;
    }
}
