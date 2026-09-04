<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Support\AnexoAllowlist;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\AnexoStorageServiceFake;
use Tests\TestCase;

class AnexoStorageServiceTest extends TestCase
{
    private AnexoStorageServiceFake $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.azure.container' => 'anexos',
            'filesystems.disks.azure.url' => 'https://fake.blob.core.windows.net/anexos',
        ]);

        Storage::fake('azure');
        Storage::fake('local');
        Storage::disk('azure')->buildTemporaryUrlsUsing(function (string $path, $expiration) {
            return 'https://fake.blob.core.windows.net/anexos/'.$path.'?sv=test&se='.$expiration->getTimestamp();
        });

        $this->service = new AnexoStorageServiceFake;
    }

    public function test_recusa_mime_e_extensao_fora_da_allowlist(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);

        $this->service->validar(
            UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload'),
            AnexoOrigem::Fatura
        );
    }

    public function test_recusa_csv_em_origem_compra(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);

        $this->service->validar(
            UploadedFile::fake()->create('planilha.csv', 10, 'text/csv'),
            AnexoOrigem::Compra
        );
    }

    public function test_recusa_arquivo_acima_do_tamanho_maximo(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);

        $kilobytes = (int) (AnexoAllowlist::TAMANHO_MAXIMO_BYTES / 1024) + 1;

        $this->service->validar(
            UploadedFile::fake()->create('grande.pdf', $kilobytes, 'application/pdf'),
            AnexoOrigem::Fatura
        );
    }

    public function test_aceita_pdf_de_fatura(): void
    {
        $this->service->validar(
            UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
            AnexoOrigem::Fatura
        );

        $this->addToAssertionCount(1);
    }

    public function test_registrar_cria_linha_pendente_e_grava_staging(): void
    {
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');

        $anexo = $this->service->registrar($file, AnexoOrigem::Fatura, 7, 42);

        $this->assertSame(AnexoStatus::Pendente, $anexo->status);
        $this->assertSame(AnexoOrigem::Fatura, $anexo->origem);
        $this->assertSame(7, $anexo->user_id);
        $this->assertSame(42, $anexo->referencia_id);
        $this->assertSame('fatura.pdf', $anexo->nome_original);
        $this->assertSame('pdf', $anexo->extensao);
        $this->assertSame('azure', $anexo->disk);
        $this->assertSame('anexos', $anexo->container);
        $this->assertNull($anexo->url);
        $this->assertNull($anexo->blob_path);
        $this->assertNotEmpty($anexo->hash);
        Storage::disk('local')->assertExists($this->service->caminhoStaging($anexo));
        $this->assertFalse(Storage::disk('azure')->exists($this->service->caminhoBlob($anexo)));
    }

    public function test_enviar_sobe_arquivo_e_preenche_url_e_blob_path(): void
    {
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');
        $anexo = $this->service->registrar($file, AnexoOrigem::Fatura, 7, 42);

        $enviado = $this->service->enviar($anexo);

        $this->assertSame(AnexoStatus::Enviado, $enviado->status);
        $this->assertSame($this->service->caminhoBlob($enviado), $enviado->blob_path);
        $this->assertSame(
            'https://fake.blob.core.windows.net/anexos/'.$enviado->blob_path,
            $enviado->url
        );
        $this->assertNotNull($enviado->enviado_em);
        $this->assertNull($enviado->erro_mensagem);
        Storage::disk('azure')->assertExists($enviado->blob_path);
        Storage::disk('local')->assertMissing($this->service->caminhoStaging($enviado));
    }

    public function test_url_temporaria_usa_sas_do_container_privado(): void
    {
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');
        $anexo = $this->service->enviar(
            $this->service->registrar($file, AnexoOrigem::Fatura, 7, 42)
        );

        $url = $this->service->urlTemporaria($anexo);

        $this->assertStringContainsString($anexo->blob_path, $url);
        $this->assertStringContainsString('sv=', $url);
        $this->assertStringContainsString('se=', $url);
    }

    public function test_excluir_remove_blob_e_faz_soft_delete(): void
    {
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');
        $anexo = $this->service->enviar(
            $this->service->registrar($file, AnexoOrigem::Fatura, 7, 42)
        );
        $blobPath = $anexo->blob_path;
        $id = (int) $anexo->id;

        $this->service->excluir($anexo);

        $this->assertSame(AnexoStatus::Excluido, $anexo->status);
        $this->assertNotNull($anexo->deleted_at);
        $this->assertNull($this->service->buscar($id));
        $this->assertNotNull($this->service->buscarComExcluidos($id));
        Storage::disk('azure')->assertMissing($blobPath);
    }

    public function test_registrar_de_disco_local_copia_para_staging(): void
    {
        Storage::disk('local')->put('faturas/7/antiga.pdf', 'pdf-historico');

        $anexo = $this->service->registrarDeDiscoLocal(
            'faturas/7/antiga.pdf',
            AnexoOrigem::Fatura,
            7,
            42,
            'fatura-antiga.pdf'
        );

        $this->assertSame(AnexoStatus::Pendente, $anexo->status);
        $this->assertSame('fatura-antiga.pdf', $anexo->nome_original);
        $this->assertSame(42, $anexo->referencia_id);
        $this->assertNotEmpty($anexo->hash);
        Storage::disk('local')->assertExists($this->service->caminhoStaging($anexo));
    }

    public function test_caminho_para_leitura_usa_blob_quando_enviado(): void
    {
        $anexo = $this->service->enviar(
            $this->service->registrar(
                UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
                AnexoOrigem::Fatura,
                7,
                42
            )
        );

        $path = $this->service->caminhoParaLeitura($anexo);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertTrue($this->service->existeArquivo($anexo));
    }

    public function test_enviar_sem_staging_nao_apaga_o_registro(): void
    {
        $anexo = new Anexo;
        $anexo->forceFill([
            'user_id' => 1,
            'origem' => AnexoOrigem::Fatura,
            'referencia_id' => 9,
            'nome_original' => 'fatura.pdf',
            'mime' => 'application/pdf',
            'extensao' => 'pdf',
            'tamanho_bytes' => 1024,
            'hash' => str_repeat('a', 64),
            'disk' => 'azure',
            'status' => AnexoStatus::Pendente,
        ]);
        $anexo->id = 99;

        try {
            $this->service->enviar($anexo);
            $this->fail('Deveria falhar sem o arquivo de staging.');
        } catch (RuntimeException $e) {
            $this->assertSame('Arquivo pendente não encontrado para envio.', $e->getMessage());
        }

        $persistido = $this->service->buscar(99);
        $this->assertNotNull($persistido);
        $this->assertSame(AnexoStatus::Enviando, $persistido->status);
        $this->assertNull($persistido->blob_path);
    }
}
