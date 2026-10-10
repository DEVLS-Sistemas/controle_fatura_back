<?php

namespace App\Jobs;

use App\Services\Fatura\FaturaService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Unifica duplicatas depois que a listagem já respondeu.
 * Não implementa ShouldQueue: a fila local é sync e voltaria a segurar o request.
 */
class ConsolidarDuplicatasFaturaJob
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $userId) {}

    public function handle(FaturaService $faturas): void
    {
        $faturas->consolidarDuplicatasAposListagem($this->userId);
    }
}
