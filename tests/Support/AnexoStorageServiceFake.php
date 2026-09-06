<?php

namespace Tests\Support;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Services\Anexo\AnexoStorageService;

class AnexoStorageServiceFake extends AnexoStorageService
{
    /** @var array<int, Anexo> */
    public array $anexos = [];

    private int $seq = 1;

    public function buscar(int $id): ?Anexo
    {
        $anexo = $this->anexos[$id] ?? null;
        if ($anexo === null || $anexo->deleted_at !== null) {
            return null;
        }

        return $anexo;
    }

    public function buscarComExcluidos(int $id): ?Anexo
    {
        return $this->anexos[$id] ?? null;
    }

    public function buscarPorHash(int $userId, AnexoOrigem $origem, int $referenciaId, string $hash): ?Anexo
    {
        foreach ($this->anexos as $anexo) {
            if ($anexo->deleted_at !== null || $anexo->status === AnexoStatus::Excluido) {
                continue;
            }
            if ((int) $anexo->user_id !== $userId) {
                continue;
            }
            if ($anexo->origem !== $origem) {
                continue;
            }
            if ((int) $anexo->referencia_id !== $referenciaId) {
                continue;
            }
            if ((string) $anexo->hash !== $hash) {
                continue;
            }

            return $anexo;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function persistirNovo(array $attrs): Anexo
    {
        $anexo = new Anexo;
        $anexo->forceFill($attrs);
        $anexo->id = $this->seq++;
        $anexo->exists = true;
        $anexo->syncOriginal();
        $this->anexos[$anexo->id] = $anexo;

        return $anexo;
    }

    protected function persistir(Anexo $anexo): void
    {
        if (! $anexo->id) {
            $anexo->id = $this->seq++;
        }
        $anexo->exists = true;
        $this->anexos[$anexo->id] = $anexo;
    }

    protected function remover(Anexo $anexo): void
    {
        $anexo->deleted_at = now();
        $this->anexos[$anexo->id] = $anexo;
    }
}
