<?php

namespace App\Services\Anexo;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Services\Fatura\FaturaAnexoHashService;
use App\Support\AnexoAllowlist;
use DateTimeInterface;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AnexoStorageService
{
    public const DISK_DESTINO = 'azure';

    public const DISK_STAGING = 'local';

    public const URL_TEMPORARIA_MINUTOS = 15;

    public function validar(UploadedFile $file, AnexoOrigem $origem): void
    {
        $extensao = $this->extensaoDoArquivo($file);
        $mime = $this->mimeAceito($file, $origem, $extensao);

        if ($mime === null) {
            throw new Exception('Tipo de arquivo não permitido para esta origem.', 422);
        }

        if (! AnexoAllowlist::tamanhoPermitido($file->getSize() ?: null)) {
            throw new Exception('O arquivo deve ter no máximo 10MB.', 422);
        }
    }

    public function registrar(
        UploadedFile $file,
        AnexoOrigem $origem,
        int $userId,
        int $referenciaId,
    ): Anexo {
        $this->validar($file, $origem);

        $extensao = $this->extensaoDoArquivo($file);
        $mime = $this->mimeAceito($file, $origem, $extensao) ?? strtolower((string) $file->getClientMimeType());

        $anexo = $this->persistirNovo([
            'user_id' => $userId,
            'origem' => $origem,
            'referencia_id' => $referenciaId,
            'nome_original' => $file->getClientOriginalName(),
            'mime' => $mime,
            'extensao' => $extensao,
            'tamanho_bytes' => $file->getSize() ?: 0,
            'hash' => FaturaAnexoHashService::hashArquivo($file),
            'disk' => self::DISK_DESTINO,
            'container' => $this->containerPadrao(),
            'status' => AnexoStatus::Pendente,
            'erro_mensagem' => null,
        ]);

        $file->storeAs(
            dirname($this->caminhoStaging($anexo)),
            basename($this->caminhoStaging($anexo)),
            self::DISK_STAGING
        );

        return $anexo;
    }

    public function enviar(Anexo $anexo): Anexo
    {
        if ($anexo->status === AnexoStatus::Excluido) {
            throw new Exception('Anexo excluído não pode ser enviado.', 422);
        }

        if ($this->jaEnviado($anexo)) {
            return $anexo;
        }

        $anexo->status = AnexoStatus::Enviando;
        $anexo->erro_mensagem = null;
        $this->persistir($anexo);

        $staging = $this->caminhoStaging($anexo);
        if (! Storage::disk(self::DISK_STAGING)->exists($staging)) {
            throw new RuntimeException('Arquivo pendente não encontrado para envio.');
        }

        $blobPath = $this->caminhoBlob($anexo);
        $stream = Storage::disk(self::DISK_STAGING)->readStream($staging);
        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler o arquivo pendente para envio.');
        }

        $disk = $anexo->disk ?: self::DISK_DESTINO;
        Storage::disk($disk)->put($blobPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $anexo->fill([
            'blob_path' => $blobPath,
            'url' => $this->urlPermanente($anexo, $blobPath),
            'container' => $anexo->container ?: $this->containerPadrao(),
            'disk' => $disk,
            'status' => AnexoStatus::Enviado,
            'enviado_em' => now(),
            'erro_mensagem' => null,
        ]);
        $this->persistir($anexo);

        Storage::disk(self::DISK_STAGING)->delete($staging);

        return $anexo;
    }

    public function buscar(int $id): ?Anexo
    {
        return Anexo::query()->find($id);
    }

    public function marcarFalhou(Anexo $anexo, ?string $mensagem): Anexo
    {
        $anexo->status = AnexoStatus::Falhou;
        $anexo->erro_mensagem = $mensagem;
        $this->persistir($anexo);

        return $anexo;
    }

    public function urlTemporaria(Anexo $anexo, ?DateTimeInterface $expiraEm = null): string
    {
        if (! $anexo->blob_path) {
            throw new Exception('Anexo ainda não foi enviado ao armazenamento.', 422);
        }

        $expiraEm ??= now()->addMinutes(self::URL_TEMPORARIA_MINUTOS);
        $disk = Storage::disk($anexo->disk ?: self::DISK_DESTINO);
        $opcoes = [];
        if ($anexo->nome_original) {
            $opcoes['content_disposition'] = 'inline; filename="'.$anexo->nome_original.'"';
        }

        try {
            return $disk->temporaryUrl($anexo->blob_path, $expiraEm, $opcoes);
        } catch (RuntimeException $e) {
            if (is_string($anexo->url) && $anexo->url !== '') {
                return $anexo->url;
            }

            return $disk->url($anexo->blob_path);
        }
    }

    public function excluir(Anexo $anexo): Anexo
    {
        $diskNome = $anexo->disk ?: self::DISK_DESTINO;
        if ($anexo->blob_path && Storage::disk($diskNome)->exists($anexo->blob_path)) {
            Storage::disk($diskNome)->delete($anexo->blob_path);
        }

        $staging = $this->caminhoStaging($anexo);
        if (Storage::disk(self::DISK_STAGING)->exists($staging)) {
            Storage::disk(self::DISK_STAGING)->delete($staging);
        }

        $anexo->status = AnexoStatus::Excluido;
        $this->persistir($anexo);
        $this->remover($anexo);

        return $anexo;
    }

    public function caminhoStaging(Anexo $anexo): string
    {
        $ext = $anexo->extensao ?: 'bin';

        return sprintf('anexos/staging/%d/%d.%s', (int) $anexo->user_id, (int) $anexo->id, $ext);
    }

    public function caminhoBlob(Anexo $anexo): string
    {
        $ext = $anexo->extensao ?: 'bin';
        $origem = $anexo->origem instanceof AnexoOrigem
            ? $anexo->origem->value
            : (string) $anexo->origem;

        return sprintf('%s/%d/%d.%s', $origem, (int) $anexo->user_id, (int) $anexo->id, $ext);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function persistirNovo(array $attrs): Anexo
    {
        return Anexo::create($attrs);
    }

    protected function persistir(Anexo $anexo): void
    {
        $anexo->save();
    }

    protected function remover(Anexo $anexo): void
    {
        $anexo->delete();
    }

    private function jaEnviado(Anexo $anexo): bool
    {
        if ($anexo->status !== AnexoStatus::Enviado || ! $anexo->blob_path) {
            return false;
        }

        return Storage::disk($anexo->disk ?: self::DISK_DESTINO)->exists($anexo->blob_path);
    }

    private function urlPermanente(Anexo $anexo, string $blobPath): string
    {
        $base = rtrim((string) config('filesystems.disks.azure.url'), '/');
        if ($base !== '') {
            return $base.'/'.ltrim($blobPath, '/');
        }

        $account = (string) config('filesystems.disks.azure.name');
        $container = $anexo->container ?: $this->containerPadrao();
        if ($account !== '' && $container !== '') {
            return sprintf(
                'https://%s.blob.core.windows.net/%s/%s',
                $account,
                $container,
                ltrim($blobPath, '/')
            );
        }

        return Storage::disk($anexo->disk ?: self::DISK_DESTINO)->url($blobPath);
    }

    private function containerPadrao(): string
    {
        return (string) (config('filesystems.disks.azure.container') ?: 'anexos');
    }

    private function extensaoDoArquivo(UploadedFile $file): string
    {
        return strtolower(ltrim((string) $file->getClientOriginalExtension(), '.'));
    }

    private function mimeAceito(UploadedFile $file, AnexoOrigem $origem, string $extensao): ?string
    {
        $candidatos = array_values(array_unique(array_filter([
            strtolower((string) $file->getMimeType()),
            strtolower((string) $file->getClientMimeType()),
        ])));

        foreach ($candidatos as $mime) {
            if (AnexoAllowlist::aceita($origem, $mime, $extensao)) {
                return $mime;
            }
        }

        return null;
    }
}
