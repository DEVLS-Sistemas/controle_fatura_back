<?php

namespace App\Services\Transacao;

use App\Models\CompraAnexo;
use App\Models\CompraHistorico;
use App\Models\Transacao;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompraAnexoService
{
    private CompraHistoricoService $historicoService;

    public function __construct(?CompraHistoricoService $historicoService = null)
    {
        $this->historicoService = $historicoService ?? new CompraHistoricoService();
    }

    public function handleListar(object $atributes): object
    {
        $userId = (int) Auth::id();
        $ancora = $this->resolverAncora($userId, $atributes);

        return (object) [
            'data' => $this->listarDaCompra($ancora),
            'status' => true,
            'message' => 'Anexos carregados com sucesso!',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarDaCompra(Transacao $ancora): array
    {
        $query = CompraAnexo::where('user_id', (int) $ancora->user_id)->orderByDesc('id');

        if (!empty($ancora->compra_grupo_id)) {
            $query->where(function ($q) use ($ancora) {
                $q->where('compra_grupo_id', $ancora->compra_grupo_id)
                    ->orWhere('transacao_id', $ancora->id);
            });
        } else {
            $query->where('transacao_id', $ancora->id);
        }

        return $query->get()->map(fn (CompraAnexo $anexo) => $this->mapAnexo($anexo))->values()->all();
    }

    public function handleCadastrar(object $atributes): object
    {
        $userId = (int) Auth::id();
        $ancora = $this->resolverAncora($userId, $atributes);
        $arquivos = $this->collectArquivos($atributes);

        if ($arquivos === []) {
            throw new Exception('Envie ao menos um arquivo', 422);
        }

        $criados = [];
        foreach ($arquivos as $arquivo) {
            $criados[] = $this->storeArquivo($ancora, $arquivo, $atributes->tipo ?? null);
        }

        $this->historicoService->registrar(
            $ancora,
            CompraHistorico::ACAO_ANEXO_ADICIONADO,
            count($criados) === 1
                ? 'Anexo adicionado: ' . $criados[0]['nome_original']
                : count($criados) . ' anexos adicionados'
        );

        return (object) [
            'data' => $criados,
            'status' => true,
            'message' => count($criados) === 1
                ? 'Anexo adicionado com sucesso!'
                : count($criados) . ' anexos adicionados com sucesso!',
        ];
    }

    public function handleExcluir(int|string $id): object
    {
        $userId = (int) Auth::id();
        $anexo = CompraAnexo::where('id', $id)->where('user_id', $userId)->first();
        if (!$anexo) {
            throw new Exception('Anexo não encontrado', 404);
        }

        $this->deleteStored($anexo->path);
        $nome = $anexo->nome_original;
        $transacao = Transacao::where('id', $anexo->transacao_id)->where('user_id', $userId)->first();
        $anexo->delete();

        if ($transacao) {
            $this->historicoService->registrar(
                $transacao,
                CompraHistorico::ACAO_ANEXO_REMOVIDO,
                'Anexo removido: ' . $nome
            );
        }

        return (object) [
            'data' => ['id' => (int) $id],
            'status' => true,
            'message' => 'Anexo excluído com sucesso!',
        ];
    }

    /**
     * @return array{path: string, nome: string, mime: string}
     */
    public function resolveDownload(int|string $id): array
    {
        $userId = (int) Auth::id();
        $anexo = CompraAnexo::where('id', $id)->where('user_id', $userId)->first();
        if (!$anexo) {
            throw new Exception('Anexo não encontrado', 404);
        }

        if (!$anexo->path || !Storage::disk('local')->exists($anexo->path)) {
            throw new Exception('Arquivo não encontrado', 404);
        }

        return [
            'path' => Storage::disk('local')->path($anexo->path),
            'nome' => $anexo->nome_original,
            'mime' => $anexo->mime ?: 'application/octet-stream',
        ];
    }

    public function softDeleteByTransacaoIds(array $transacaoIds, int $userId): void
    {
        if ($transacaoIds === []) {
            return;
        }

        $anexos = CompraAnexo::where('user_id', $userId)
            ->whereIn('transacao_id', $transacaoIds)
            ->get();

        foreach ($anexos as $anexo) {
            $this->deleteStored($anexo->path);
            $anexo->delete();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storeArquivo(Transacao $ancora, UploadedFile $file, mixed $tipoInformado): array
    {
        $this->assertArquivoValido($file);
        $tipo = $this->resolveTipo($file, $tipoInformado);
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = Str::random(40) . '.' . $extension;
        $path = $file->storeAs('compras/' . $ancora->user_id, $filename, 'local');

        $anexo = CompraAnexo::create([
            'user_id' => (int) $ancora->user_id,
            'transacao_id' => (int) $ancora->id,
            'compra_grupo_id' => $ancora->compra_grupo_id ?: null,
            'nome_original' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'tamanho' => $file->getSize(),
            'tipo' => $tipo,
        ]);

        return $this->mapAnexo($anexo);
    }

    /**
     * @return list<UploadedFile>
     */
    private function collectArquivos(object $atributes): array
    {
        $files = [];
        if (isset($atributes->arquivo) && $atributes->arquivo instanceof UploadedFile) {
            $files[] = $atributes->arquivo;
        }
        if (!empty($atributes->arquivos) && is_array($atributes->arquivos)) {
            foreach ($atributes->arquivos as $arquivo) {
                if ($arquivo instanceof UploadedFile) {
                    $files[] = $arquivo;
                }
            }
        }

        return $files;
    }

    private function assertArquivoValido(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/heic',
            'image/heif',
        ];

        if (!in_array($extension, $allowedExtensions, true) && !in_array($mime, $allowedMimes, true)) {
            throw new Exception('O anexo deve ser PDF ou imagem (jpg, png, webp, gif)', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new Exception('O anexo deve ter no máximo 10MB', 422);
        }
    }

    private function resolveTipo(UploadedFile $file, mixed $tipoInformado): string
    {
        $tipo = is_string($tipoInformado) ? strtolower(trim($tipoInformado)) : '';
        if (in_array($tipo, CompraAnexo::TIPOS, true)) {
            return $tipo;
        }

        $mime = strtolower((string) $file->getMimeType());
        if (str_contains($mime, 'pdf')) {
            return CompraAnexo::TIPO_PDF;
        }
        if (str_starts_with($mime, 'image/')) {
            return CompraAnexo::TIPO_IMAGEM;
        }

        return CompraAnexo::TIPO_OUTRO;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAnexo(CompraAnexo $anexo): array
    {
        $tipo = $anexo->tipo;

        return [
            'id' => (int) $anexo->id,
            'transacao_id' => (int) $anexo->transacao_id,
            'compra_grupo_id' => $anexo->compra_grupo_id,
            'nome_original' => $anexo->nome_original,
            'mime' => $anexo->mime,
            'tamanho' => $anexo->tamanho !== null ? (int) $anexo->tamanho : null,
            'tipo' => $tipo,
            'tipo_label' => $tipo !== null ? (CompraAnexo::TIPOS_LABELS[$tipo] ?? $tipo) : null,
            'created_at' => $anexo->created_at?->toIso8601String(),
        ];
    }

    private function resolverAncora(int $userId, object $atributes): Transacao
    {
        $identificador = (string) ($atributes->identificador
            ?? $atributes->transacao_id
            ?? $atributes->id
            ?? $atributes->compra_grupo_id
            ?? '');

        if ($identificador === '') {
            throw new Exception('Informe a compra (transacao_id ou identificador)', 422);
        }

        $query = Transacao::where('user_id', $userId)->where('tipo', Transacao::TIPO_PURCHASE);

        if (Str::isUuid($identificador)) {
            $record = (clone $query)->where('compra_grupo_id', $identificador)
                ->orderBy('parcela_atual')
                ->first();
        } elseif (ctype_digit((string) $identificador)) {
            $record = (clone $query)->where('id', (int) $identificador)->first();
            if ($record && !empty($record->compra_grupo_id)) {
                $primeira = (clone $query)
                    ->where('compra_grupo_id', $record->compra_grupo_id)
                    ->orderBy('parcela_atual')
                    ->first();
                $record = $primeira ?: $record;
            }
        } else {
            $record = null;
        }

        if (!$record) {
            throw new Exception('Compra não encontrada', 404);
        }

        return $record;
    }

    private function deleteStored(?string $relativePath): void
    {
        if ($relativePath && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }
}
