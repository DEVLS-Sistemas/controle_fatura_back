<?php

namespace App\Jobs;

use App\Services\Anexo\AnexoStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UploadAnexoParaAzureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $anexoId) {}

    public function handle(AnexoStorageService $service): void
    {
        $anexo = $service->buscar($this->anexoId);
        if (! $anexo) {
            return;
        }

        try {
            $service->enviar($anexo);
        } catch (Throwable $e) {
            $service->marcarFalhou($anexo, $e->getMessage());
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $service = app(AnexoStorageService::class);
        $anexo = $service->buscar($this->anexoId);
        if (! $anexo) {
            return;
        }

        $service->marcarFalhou($anexo, $e?->getMessage());
    }
}
