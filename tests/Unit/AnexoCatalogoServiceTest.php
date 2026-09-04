<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Jobs\UploadAnexoParaAzureJob;
use App\Services\Anexo\AnexoCatalogoService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnexoStorageServiceFake;
use Tests\TestCase;

class AnexoCatalogoServiceTest extends TestCase
{
    private AnexoStorageServiceFake $storage;

    private AnexoCatalogoService $catalogo;

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

        $this->storage = new AnexoStorageServiceFake;
        $this->catalogo = new AnexoCatalogoService($this->storage);
    }

    public function test_recusa_tipo_fora_da_allowlist_com_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);

        $this->catalogo->validar(
            UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload'),
            AnexoOrigem::Fatura
        );
    }

    public function test_recusa_gif_em_origem_compra(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(422);

        $this->catalogo->validar(
            UploadedFile::fake()->create('print.gif', 20, 'image/gif'),
            AnexoOrigem::Compra
        );
    }

    public function test_registrar_cria_catalogo_copia_local_e_despacha_job(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');

        $resultado = $this->catalogo->registrarComFallbackLocal(
            $file,
            AnexoOrigem::Fatura,
            7,
            42,
            'faturas/7'
        );

        $anexo = $resultado['anexo'];
        $this->assertSame(AnexoOrigem::Fatura, $anexo->origem);
        $this->assertSame(42, $anexo->referencia_id);
        $this->assertSame(AnexoStatus::Pendente, $anexo->status);
        $this->assertStringStartsWith('faturas/7/', $resultado['path_local']);
        Storage::disk('local')->assertExists($resultado['path_local']);
        Storage::disk('local')->assertExists($this->storage->caminhoStaging($anexo));
        Queue::assertPushed(UploadAnexoParaAzureJob::class, function (UploadAnexoParaAzureJob $job) use ($anexo) {
            return $job->anexoId === (int) $anexo->id;
        });
    }

    public function test_registrar_compra_usa_origem_e_referencia_da_transacao(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->create('nota.pdf', 15, 'application/pdf');

        $resultado = $this->catalogo->registrarComFallbackLocal(
            $file,
            AnexoOrigem::Compra,
            3,
            88,
            'compras/3'
        );

        $this->assertSame(AnexoOrigem::Compra, $resultado['anexo']->origem);
        $this->assertSame(88, $resultado['anexo']->referencia_id);
        $this->assertStringStartsWith('compras/3/', $resultado['path_local']);
        Storage::disk('local')->assertExists($resultado['path_local']);
    }

    public function test_leitura_prefere_azure_depois_do_envio(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf');
        $resultado = $this->catalogo->registrarComFallbackLocal(
            $file,
            AnexoOrigem::Fatura,
            7,
            42,
            'faturas/7'
        );
        $enviado = $this->storage->enviar($resultado['anexo']);

        $path = $this->catalogo->caminhoLeitura((int) $enviado->id, $resultado['path_local'], 7);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('anexo-'.$enviado->id, $path);
    }

    public function test_leitura_cai_no_path_local_quando_nao_ha_catalogo(): void
    {
        Storage::disk('local')->put('faturas/7/antigo.pdf', 'conteudo-local');

        $path = $this->catalogo->caminhoLeitura(null, 'faturas/7/antigo.pdf', 7);

        $this->assertSame(Storage::disk('local')->path('faturas/7/antigo.pdf'), $path);
    }

    public function test_leitura_sem_catalogo_nem_local_retorna_null(): void
    {
        $this->assertNull($this->catalogo->caminhoLeitura(null, 'faturas/7/sumiu.pdf', 7));
    }

    public function test_excluir_remove_do_catalogo(): void
    {
        Queue::fake();
        $resultado = $this->catalogo->registrarComFallbackLocal(
            UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
            AnexoOrigem::Fatura,
            7,
            42,
            'faturas/7'
        );
        $id = (int) $resultado['anexo']->id;
        $this->storage->enviar($resultado['anexo']);

        $this->catalogo->excluirSeExistir($id);

        $this->assertNull($this->storage->buscar($id));
        $this->assertFalse($this->catalogo->existe($id));
    }

    public function test_atualizar_referencia_aponta_para_a_nova_fatura(): void
    {
        Queue::fake();
        $resultado = $this->catalogo->registrarComFallbackLocal(
            UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
            AnexoOrigem::Fatura,
            7,
            42,
            'faturas/7'
        );

        $this->catalogo->atualizarReferencia((int) $resultado['anexo']->id, 99);

        $this->assertSame(99, $this->storage->buscar((int) $resultado['anexo']->id)?->referencia_id);
    }
}
