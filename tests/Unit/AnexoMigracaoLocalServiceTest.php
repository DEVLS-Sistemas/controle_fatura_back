<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Services\Anexo\AnexoMigracaoCandidato;
use App\Services\Anexo\AnexoMigracaoLocalService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnexoStorageServiceFake;
use Tests\TestCase;

class AnexoMigracaoLocalServiceTest extends TestCase
{
    private AnexoStorageServiceFake $storage;

    private AnexoMigracaoLocalService $service;

    /** @var list<array{0: AnexoMigracaoCandidato, 1: int}> */
    private array $vinculos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.azure.container' => 'anexos',
            'filesystems.disks.azure.url' => 'https://fake.blob.core.windows.net/anexos',
        ]);

        Storage::fake('azure');
        Storage::fake('local');

        $this->storage = new AnexoStorageServiceFake;
        $this->service = new AnexoMigracaoLocalService($this->storage);
        $this->vinculos = [];
    }

    public function test_dry_run_lista_sem_gravar(): void
    {
        Storage::disk('local')->put('faturas/7/antiga.pdf', 'conteudo-pdf');
        $candidato = $this->candidatoFatura();

        $relatorio = $this->service->processarLote(
            [$candidato],
            true,
            false,
            $this->vincularSpy()
        );

        $this->assertSame(1, $relatorio['migrados']);
        $this->assertSame(0, $relatorio['falhou']);
        $this->assertSame('Seria enviado ao Azure.', $relatorio['itens'][0]['motivo']);
        $this->assertSame([], $this->vinculos);
        $this->assertSame([], $this->storage->anexos);
        Storage::disk('azure')->assertDirectoryEmpty('/');
        Storage::disk('local')->assertExists('faturas/7/antiga.pdf');
    }

    public function test_lote_real_cria_anexo_fk_e_url_sem_apagar_local(): void
    {
        Storage::disk('local')->put('faturas/7/antiga.pdf', 'conteudo-pdf');

        $relatorio = $this->service->processarLote(
            [$this->candidatoFatura()],
            false,
            false,
            $this->vincularSpy()
        );

        $this->assertSame(1, $relatorio['migrados']);
        $this->assertCount(1, $this->storage->anexos);
        $anexo = $this->storage->anexos[1];
        $this->assertSame(AnexoStatus::Enviado, $anexo->status);
        $this->assertSame(AnexoOrigem::Fatura, $anexo->origem);
        $this->assertSame(42, $anexo->referencia_id);
        $this->assertNotEmpty($anexo->url);
        $this->assertNotEmpty($anexo->blob_path);
        $this->assertSame([[$this->candidatoFatura()->donoId, (int) $anexo->id]], array_map(
            fn (array $v) => [$v[0]->donoId, $v[1]],
            $this->vinculos
        ));
        Storage::disk('azure')->assertExists($anexo->blob_path);
        Storage::disk('local')->assertExists('faturas/7/antiga.pdf');
    }

    public function test_nao_duplica_hash_ja_migrado(): void
    {
        Storage::disk('local')->put('faturas/7/antiga.pdf', 'mesmo-conteudo');
        $primeiro = $this->service->processarLote(
            [$this->candidatoFatura()],
            false,
            false,
            $this->vincularSpy()
        );
        $this->vinculos = [];

        $relatorio = $this->service->processarLote(
            [$this->candidatoFatura()],
            false,
            false,
            $this->vincularSpy()
        );

        $this->assertSame(1, $primeiro['migrados']);
        $this->assertSame(1, $relatorio['pulados']);
        $this->assertSame('Hash já migrado.', $relatorio['itens'][0]['motivo']);
        $this->assertCount(1, $this->storage->anexos);
        $this->assertCount(1, $this->vinculos);
    }

    public function test_arquivo_ausente_e_pulado(): void
    {
        $relatorio = $this->service->processarLote(
            [$this->candidatoFatura()],
            false,
            false,
            $this->vincularSpy()
        );

        $this->assertSame(1, $relatorio['pulados']);
        $this->assertSame('Arquivo ausente no disk local.', $relatorio['itens'][0]['motivo']);
        $this->assertSame([], $this->vinculos);
    }

    public function test_falha_de_um_arquivo_nao_aborta_o_lote(): void
    {
        Storage::disk('local')->put('faturas/7/ok.pdf', 'pdf-ok');
        Storage::disk('local')->put('compras/7/nota.pdf', 'nota-ok');

        $ruim = $this->candidatoFatura(path: '../etc/passwd');
        $okFatura = $this->candidatoFatura(path: 'faturas/7/ok.pdf', referenciaId: 10, donoId: 10);
        $okCompra = $this->candidatoCompra();

        $relatorio = $this->service->processarLote(
            [$ruim, $okFatura, $okCompra],
            false,
            false,
            $this->vincularSpy()
        );

        $this->assertSame(1, $relatorio['falhou']);
        $this->assertSame(2, $relatorio['migrados']);
        $this->assertSame('Path local fora do diretório da origem.', $relatorio['itens'][0]['motivo']);
        $this->assertCount(2, $this->storage->anexos);
        $this->assertCount(2, $this->vinculos);
    }

    public function test_purge_so_apaga_depois_de_enviado(): void
    {
        Storage::disk('local')->put('compras/7/nota.pdf', 'nota');

        $this->service->processarLote(
            [$this->candidatoCompra()],
            false,
            true,
            $this->vincularSpy()
        );

        Storage::disk('local')->assertMissing('compras/7/nota.pdf');
        $this->assertSame(AnexoStatus::Enviado, $this->storage->anexos[1]->status);
        $this->assertSame(AnexoOrigem::Compra, $this->storage->anexos[1]->origem);
        $this->assertSame(88, $this->storage->anexos[1]->referencia_id);
    }

    public function test_command_recusa_origem_invalida(): void
    {
        $this->artisan('anexos:migrar-local', ['--origem' => 'xyz'])
            ->expectsOutput('Use --origem=fatura ou --origem=compra.')
            ->assertFailed();
    }

    public function test_dry_run_nao_purge(): void
    {
        Storage::disk('local')->put('faturas/7/antiga.pdf', 'conteudo-pdf');

        $this->service->processarLote(
            [$this->candidatoFatura()],
            true,
            true,
            $this->vincularSpy()
        );

        Storage::disk('local')->assertExists('faturas/7/antiga.pdf');
    }

    /**
     * @return callable(AnexoMigracaoCandidato, \App\Models\Anexo): void
     */
    private function vincularSpy(): callable
    {
        return function (AnexoMigracaoCandidato $candidato, $anexo): void {
            $this->vinculos[] = [$candidato, (int) $anexo->id];
        };
    }

    private function candidatoFatura(
        string $path = 'faturas/7/antiga.pdf',
        int $referenciaId = 42,
        int $donoId = 42,
    ): AnexoMigracaoCandidato {
        return new AnexoMigracaoCandidato(
            origem: AnexoOrigem::Fatura,
            userId: 7,
            referenciaId: $referenciaId,
            path: $path,
            anexoId: null,
            fk: 'anexo_pdf_id',
            donoTipo: 'fatura',
            donoId: $donoId,
            nomeOriginal: basename($path),
        );
    }

    private function candidatoCompra(): AnexoMigracaoCandidato
    {
        return new AnexoMigracaoCandidato(
            origem: AnexoOrigem::Compra,
            userId: 7,
            referenciaId: 88,
            path: 'compras/7/nota.pdf',
            anexoId: null,
            fk: 'anexo_id',
            donoTipo: 'compra_anexo',
            donoId: 15,
            nomeOriginal: 'nota.pdf',
        );
    }
}
