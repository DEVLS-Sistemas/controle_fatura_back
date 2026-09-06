<?php

namespace App\Services\Anexo;

use App\Enums\AnexoOrigem;

class AnexoMigracaoCandidato
{
    public function __construct(
        public AnexoOrigem $origem,
        public int $userId,
        public int $referenciaId,
        public string $path,
        public ?int $anexoId,
        public string $fk,
        public string $donoTipo,
        public int $donoId,
        public ?string $nomeOriginal = null,
    ) {}
}
