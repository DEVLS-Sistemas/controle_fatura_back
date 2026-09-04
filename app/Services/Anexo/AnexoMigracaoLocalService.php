<?php

namespace App\Services\Anexo;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Models\CompraAnexo;
use App\Models\Fatura;
use App\Services\Fatura\FaturaAnexoHashService;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnexoMigracaoLocalService
{
    public function __construct(private AnexoStorageService $storage) {}

    /**
     * @return array{
     *     migrados: int,
     *     falhou: int,
     *     pulados: int,
     *     itens: list<array<string, mixed>>
     * }
     */
    public function executar(
        bool $dryRun,
        ?AnexoOrigem $origem,
        ?int $limit,
        bool $purge,
        ?callable $vincular = null,
    ): array {
        return $this->processarLote($this->listarCandidatos($origem, $limit), $dryRun, $purge, $vincular);
    }

    /**
     * @return list<AnexoMigracaoCandidato>
     */
    public function listarCandidatos(?AnexoOrigem $origem, ?int $limit): array
    {
        $itens = [];

        if ($origem === null || $origem === AnexoOrigem::Fatura) {
            $itens = array_merge($itens, $this->candidatosFatura());
        }
        if ($origem === null || $origem === AnexoOrigem::Compra) {
            $itens = array_merge($itens, $this->candidatosCompra());
        }

        if ($limit !== null && $limit > 0) {
            return array_slice($itens, 0, $limit);
        }

        return $itens;
    }

    /**
     * @param  list<AnexoMigracaoCandidato>  $candidatos
     * @return array{
     *     migrados: int,
     *     falhou: int,
     *     pulados: int,
     *     itens: list<array<string, mixed>>
     * }
     */
    public function processarLote(
        array $candidatos,
        bool $dryRun,
        bool $purge,
        ?callable $vincular = null,
    ): array {
        $vincular ??= fn (AnexoMigracaoCandidato $candidato, Anexo $anexo) => $this->vincularEloquent($candidato, $anexo);

        $relatorio = [
            'migrados' => 0,
            'falhou' => 0,
            'pulados' => 0,
            'itens' => [],
        ];

        foreach ($candidatos as $candidato) {
            try {
                $resultado = $this->migrarArquivo($candidato, $dryRun, $purge, $vincular);
            } catch (Throwable $e) {
                $resultado = [
                    'status' => 'falhou',
                    'motivo' => $e->getMessage(),
                    'anexo_id' => $candidato->anexoId,
                ];
            }

            $relatorio['itens'][] = array_merge([
                'origem' => $candidato->origem->value,
                'referencia_id' => $candidato->referenciaId,
                'dono' => $candidato->donoTipo,
                'dono_id' => $candidato->donoId,
                'path' => $candidato->path,
                'fk' => $candidato->fk,
            ], $resultado);

            match ($resultado['status']) {
                'migrado' => $relatorio['migrados']++,
                'falhou' => $relatorio['falhou']++,
                default => $relatorio['pulados']++,
            };
        }

        return $relatorio;
    }

    /**
     * @return array{status: string, motivo: string, anexo_id: ?int}
     */
    public function migrarArquivo(
        AnexoMigracaoCandidato $candidato,
        bool $dryRun,
        bool $purge,
        ?callable $vincular = null,
    ): array {
        $vincular ??= fn (AnexoMigracaoCandidato $item, Anexo $anexo) => $this->vincularEloquent($item, $anexo);

        if (! $this->pathSeguro($candidato)) {
            return [
                'status' => 'falhou',
                'motivo' => 'Path local fora do diretório da origem.',
                'anexo_id' => $candidato->anexoId,
            ];
        }

        if (! Storage::disk('local')->exists($candidato->path)) {
            return [
                'status' => 'pulado',
                'motivo' => 'Arquivo ausente no disk local.',
                'anexo_id' => $candidato->anexoId,
            ];
        }

        $hash = FaturaAnexoHashService::hashPathStorage($candidato->path);
        if ($hash === null) {
            return [
                'status' => 'falhou',
                'motivo' => 'Não foi possível calcular o hash.',
                'anexo_id' => $candidato->anexoId,
            ];
        }

        $existente = $this->storage->buscarPorHash(
            $candidato->userId,
            $candidato->origem,
            $candidato->referenciaId,
            $hash
        );
        if ($existente === null && $candidato->anexoId) {
            $existente = $this->storage->buscar($candidato->anexoId);
        }

        if ($existente && $existente->status === AnexoStatus::Enviado && $existente->blob_path) {
            if ($dryRun) {
                return [
                    'status' => 'pulado',
                    'motivo' => 'Hash já migrado.',
                    'anexo_id' => (int) $existente->id,
                ];
            }

            $vincular($candidato, $existente);
            $this->purgarSePedido($candidato, $existente, $purge);

            return [
                'status' => 'pulado',
                'motivo' => 'Hash já migrado.',
                'anexo_id' => (int) $existente->id,
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'migrado',
                'motivo' => 'Seria enviado ao Azure.',
                'anexo_id' => $existente?->id !== null ? (int) $existente->id : null,
            ];
        }

        $anexo = $existente;
        if ($anexo === null) {
            $anexo = $this->storage->registrarDeDiscoLocal(
                $candidato->path,
                $candidato->origem,
                $candidato->userId,
                $candidato->referenciaId,
                $candidato->nomeOriginal
            );
        }

        try {
            $anexo = $this->storage->enviar($anexo);
        } catch (Throwable $e) {
            $this->storage->marcarFalhou($anexo, $e->getMessage());
            $vincular($candidato, $anexo);

            return [
                'status' => 'falhou',
                'motivo' => $e->getMessage(),
                'anexo_id' => (int) $anexo->id,
            ];
        }

        $vincular($candidato, $anexo);
        $this->purgarSePedido($candidato, $anexo, $purge);

        return [
            'status' => 'migrado',
            'motivo' => 'Enviado ao Azure.',
            'anexo_id' => (int) $anexo->id,
        ];
    }

    /**
     * @return list<AnexoMigracaoCandidato>
     */
    private function candidatosFatura(): array
    {
        $itens = [];
        $faturas = Fatura::query()
            ->with(['anexoPdf', 'anexoCsv'])
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('arquivo_pdf')->where('arquivo_pdf', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('arquivo_csv')->where('arquivo_csv', '!=', '');
                });
            })
            ->orderBy('id')
            ->get();

        foreach ($faturas as $fatura) {
            if ($this->slotPendente($fatura->arquivo_pdf, $fatura->anexoPdf)) {
                $itens[] = new AnexoMigracaoCandidato(
                    origem: AnexoOrigem::Fatura,
                    userId: (int) $fatura->user_id,
                    referenciaId: (int) $fatura->id,
                    path: (string) $fatura->arquivo_pdf,
                    anexoId: $fatura->anexo_pdf_id !== null ? (int) $fatura->anexo_pdf_id : null,
                    fk: 'anexo_pdf_id',
                    donoTipo: 'fatura',
                    donoId: (int) $fatura->id,
                    nomeOriginal: basename((string) $fatura->arquivo_pdf),
                );
            }
            if ($this->slotPendente($fatura->arquivo_csv, $fatura->anexoCsv)) {
                $itens[] = new AnexoMigracaoCandidato(
                    origem: AnexoOrigem::Fatura,
                    userId: (int) $fatura->user_id,
                    referenciaId: (int) $fatura->id,
                    path: (string) $fatura->arquivo_csv,
                    anexoId: $fatura->anexo_csv_id !== null ? (int) $fatura->anexo_csv_id : null,
                    fk: 'anexo_csv_id',
                    donoTipo: 'fatura',
                    donoId: (int) $fatura->id,
                    nomeOriginal: basename((string) $fatura->arquivo_csv),
                );
            }
        }

        return $itens;
    }

    /**
     * @return list<AnexoMigracaoCandidato>
     */
    private function candidatosCompra(): array
    {
        $itens = [];
        $linhas = CompraAnexo::query()
            ->with('anexo')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->orderBy('id')
            ->get();

        foreach ($linhas as $linha) {
            if (! $this->slotPendente($linha->path, $linha->anexo)) {
                continue;
            }

            $itens[] = new AnexoMigracaoCandidato(
                origem: AnexoOrigem::Compra,
                userId: (int) $linha->user_id,
                referenciaId: (int) $linha->transacao_id,
                path: (string) $linha->path,
                anexoId: $linha->anexo_id !== null ? (int) $linha->anexo_id : null,
                fk: 'anexo_id',
                donoTipo: 'compra_anexo',
                donoId: (int) $linha->id,
                nomeOriginal: $linha->nome_original ?: basename((string) $linha->path),
            );
        }

        return $itens;
    }

    private function slotPendente(?string $path, ?Anexo $anexo): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return $anexo === null || $anexo->status !== AnexoStatus::Enviado || ! $anexo->blob_path;
    }

    private function pathSeguro(AnexoMigracaoCandidato $candidato): bool
    {
        $path = str_replace('\\', '/', $candidato->path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        $prefixo = $candidato->origem === AnexoOrigem::Fatura
            ? 'faturas/'.$candidato->userId.'/'
            : 'compras/'.$candidato->userId.'/';

        return str_starts_with($path, $prefixo);
    }

    private function vincularEloquent(AnexoMigracaoCandidato $candidato, Anexo $anexo): void
    {
        if ($candidato->donoTipo === 'fatura') {
            Fatura::query()->where('id', $candidato->donoId)->update([
                $candidato->fk => $anexo->id,
            ]);

            return;
        }

        CompraAnexo::query()->where('id', $candidato->donoId)->update([
            'anexo_id' => $anexo->id,
        ]);
    }

    private function purgarSePedido(AnexoMigracaoCandidato $candidato, Anexo $anexo, bool $purge): void
    {
        if (! $purge || $anexo->status !== AnexoStatus::Enviado) {
            return;
        }

        if (Storage::disk('local')->exists($candidato->path)) {
            Storage::disk('local')->delete($candidato->path);
        }
    }
}
