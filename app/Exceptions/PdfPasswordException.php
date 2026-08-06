<?php

namespace App\Exceptions;

use App\Services\Pdf\PdfSenhaRegra;
use Exception;

class PdfPasswordException extends Exception
{
    public const MOTIVO_AUSENTE = 'ausente';

    public const MOTIVO_INCORRETA = 'incorreta';

    public function __construct(
        public readonly string $motivo,
        public readonly ?int $cartaoId = null,
        public readonly ?string $regra = null,
        public readonly bool $temSenhaCadastrada = false,
        string $message = '',
        int $code = 422
    ) {
        if ($message === '') {
            $message = $motivo === self::MOTIVO_INCORRETA
                ? 'A senha informada para o PDF da fatura está incorreta.'
                : 'Este PDF da fatura está protegido por senha. Informe a senha para continuar.';
        }

        parent::__construct($message, $code);
    }

    public function codigo(): string
    {
        return $this->motivo === self::MOTIVO_INCORRETA
            ? PdfSenhaRegra::CODIGO_SENHA_INCORRETA
            : PdfSenhaRegra::CODIGO_SENHA_NECESSARIA;
    }

    /**
     * @return array{
     *   necessaria: bool,
     *   motivo: string,
     *   regra: ?string,
     *   orientacao: ?string,
     *   label_regra: ?string,
     *   tem_senha_cadastrada: bool,
     *   cartao_id: ?int
     * }
     */
    public function payload(): array
    {
        return [
            'necessaria' => true,
            'motivo' => $this->motivo,
            'regra' => $this->regra,
            'orientacao' => PdfSenhaRegra::orientacao($this->regra),
            'label_regra' => PdfSenhaRegra::label($this->regra),
            'tem_senha_cadastrada' => $this->temSenhaCadastrada,
            'cartao_id' => $this->cartaoId,
        ];
    }
}
