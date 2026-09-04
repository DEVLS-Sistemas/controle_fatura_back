<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Jobs\UploadAnexoParaAzureJob;
use App\Models\Anexo;
use App\Services\Anexo\AnexoStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\AnexoStorageServiceFake;
use Tests\TestCase;

class UploadAnexoParaAzureJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.azure.container' => 'anexos',
            'filesystems.disks.azure.url' => 'https://fake.blob.core.windows.net/anexos',
        ]);

        Storage::fake('azure');
        Storage::fake('local');
    }

    public function test_job_envia_e_deixa_linha_enviada(): void
    {
        $service = new AnexoStorageServiceFake;
        $anexo = $service->registrar(
            UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
            AnexoOrigem::Fatura,
            3,
            11
        );

        (new UploadAnexoParaAzureJob($anexo->id))->handle($service);

        $enviado = $service->buscar($anexo->id);
        $this->assertNotNull($enviado);
        $this->assertSame(AnexoStatus::Enviado, $enviado->status);
        $this->assertNotEmpty($enviado->url);
        $this->assertNotEmpty($enviado->blob_path);
        Storage::disk('azure')->assertExists($enviado->blob_path);
    }

    public function test_falha_de_azure_marca_falhou_sem_perder_registro(): void
    {
        $anexo = new Anexo;
        $anexo->forceFill([
            'user_id' => 3,
            'origem' => AnexoOrigem::Fatura,
            'referencia_id' => 11,
            'nome_original' => 'fatura.pdf',
            'mime' => 'application/pdf',
            'extensao' => 'pdf',
            'tamanho_bytes' => 1024,
            'hash' => str_repeat('b', 64),
            'disk' => 'azure',
            'status' => AnexoStatus::Pendente,
        ]);
        $anexo->id = 15;

        $service = Mockery::mock(AnexoStorageService::class);
        $service->shouldReceive('buscar')->once()->with(15)->andReturn($anexo);
        $service->shouldReceive('enviar')
            ->once()
            ->andThrow(new RuntimeException('Azure unavailable'));
        $service->shouldReceive('marcarFalhou')
            ->once()
            ->andReturnUsing(function (Anexo $alvo, ?string $mensagem) {
                $alvo->status = AnexoStatus::Falhou;
                $alvo->erro_mensagem = $mensagem;

                return $alvo;
            });

        try {
            (new UploadAnexoParaAzureJob(15))->handle($service);
            $this->fail('O job deveria relançar a falha para permitir retry.');
        } catch (RuntimeException $e) {
            $this->assertSame('Azure unavailable', $e->getMessage());
        }

        $this->assertSame(AnexoStatus::Falhou, $anexo->status);
        $this->assertSame('Azure unavailable', $anexo->erro_mensagem);
        $this->assertSame(15, $anexo->id);
        $this->assertGreaterThanOrEqual(3, (new UploadAnexoParaAzureJob(15))->tries);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
