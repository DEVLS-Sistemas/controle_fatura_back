<?php

namespace App\Services\Anexo;

use App\Enums\AnexoOrigem;
use App\Jobs\UploadAnexoParaAzureJob;
use App\Models\Anexo;
use App\Models\Fatura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnexoCatalogoService
{
    private AnexoStorageService $storage;

    public function __construct(?AnexoStorageService $storage = null)
    {
        $this->storage = $storage ?? app(AnexoStorageService::class);
    }

    public function validar(UploadedFile $file, AnexoOrigem $origem): void
    {
        $this->storage->validar($file, $origem);
    }

    /**
     * Grava no catálogo, copia o staging para o path local (fallback) e despacha o job.
     *
     * @return array{anexo: Anexo, path_local: string}
     */
    public function registrarComFallbackLocal(
        UploadedFile $file,
        AnexoOrigem $origem,
        int $userId,
        int $referenciaId,
        string $diretorioLocal,
    ): array {
        $anexo = $this->storage->registrar($file, $origem, $userId, $referenciaId);
        $extensao = $anexo->extensao ?: 'bin';
        $pathLocal = trim($diretorioLocal, '/').'/'.Str::random(40).'.'.$extensao;
        $staging = $this->storage->caminhoStaging($anexo);

        Storage::disk(AnexoStorageService::DISK_STAGING)->put(
            $pathLocal,
            Storage::disk(AnexoStorageService::DISK_STAGING)->get($staging)
        );

        UploadAnexoParaAzureJob::dispatch($anexo->id);

        return [
            'anexo' => $anexo,
            'path_local' => $pathLocal,
        ];
    }

    public function excluirSeExistir(?int $anexoId): void
    {
        if ($anexoId === null || $anexoId < 1) {
            return;
        }

        $anexo = $this->storage->buscar($anexoId);
        if ($anexo === null) {
            return;
        }

        $this->storage->excluir($anexo);
    }

    public function atualizarReferencia(?int $anexoId, int $referenciaId): void
    {
        if ($anexoId === null || $anexoId < 1) {
            return;
        }

        $anexo = $this->storage->buscar($anexoId);
        if ($anexo === null) {
            return;
        }

        $this->storage->atualizarReferencia($anexo, $referenciaId);
    }

    public function existe(?int $anexoId): bool
    {
        if ($anexoId === null || $anexoId < 1) {
            return false;
        }

        $anexo = $this->storage->buscar($anexoId);

        return $anexo !== null && $this->storage->existeArquivo($anexo);
    }

    public function caminhoLeitura(?int $anexoId, ?string $pathLocal, ?int $userId = null): ?string
    {
        if ($anexoId !== null && $anexoId > 0) {
            $anexo = $this->storage->buscar($anexoId);
            if ($anexo !== null) {
                $azureOuStaging = $this->storage->caminhoParaLeitura($anexo);
                if ($azureOuStaging !== null) {
                    return $azureOuStaging;
                }
            }
        }

        if ($pathLocal && Storage::disk('local')->exists($pathLocal)) {
            if ($userId !== null && ! Fatura::isOwnedStoragePath($pathLocal, $userId)) {
                return null;
            }

            return Storage::disk('local')->path($pathLocal);
        }

        return null;
    }
}
