<?php

namespace Tests\Unit;

use App\Exceptions\FaturaSelecaoException;
use App\Services\Fatura\FaturaAnexoHashService;
use PHPUnit\Framework\TestCase;

class FaturaAnexoHashServiceTest extends TestCase
{
    public function test_hash_conteudo_e_sha256_estavel(): void
    {
        $this->assertSame(
            hash('sha256', "fatura-nubank-08-2026\n"),
            FaturaAnexoHashService::hashConteudo("fatura-nubank-08-2026\n")
        );
    }

    public function test_mesmo_conteudo_gera_mesmo_hash(): void
    {
        $a = FaturaAnexoHashService::hashConteudo('pdf-bytes');
        $b = FaturaAnexoHashService::hashConteudo('pdf-bytes');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function test_conteudo_diferente_gera_hash_diferente(): void
    {
        $this->assertNotSame(
            FaturaAnexoHashService::hashConteudo('pdf-a'),
            FaturaAnexoHashService::hashConteudo('pdf-b')
        );
    }

    public function test_confirmacao_substituir_e_manter(): void
    {
        $this->assertSame(
            FaturaAnexoHashService::CONFIRMAR_SUBSTITUIR,
            FaturaAnexoHashService::confirmacaoDoRequest((object) [
                'confirmar_anexo_duplicado' => 'substituir',
            ])
        );
        $this->assertSame(
            FaturaAnexoHashService::CONFIRMAR_MANTER,
            FaturaAnexoHashService::confirmacaoDoRequest((object) [
                'confirmar_anexo_duplicado' => 'MANTER',
            ])
        );
        $this->assertNull(FaturaAnexoHashService::confirmacaoDoRequest((object) []));
        $this->assertNull(FaturaAnexoHashService::confirmacaoDoRequest((object) [
            'confirmar_anexo_duplicado' => 'criar',
        ]));
    }

    public function test_fatura_duplicada_id_do_request(): void
    {
        $this->assertSame(643, FaturaAnexoHashService::faturaDuplicadaIdDoRequest((object) [
            'fatura_duplicada_id' => '643',
        ]));
        $this->assertNull(FaturaAnexoHashService::faturaDuplicadaIdDoRequest((object) []));
        $this->assertNull(FaturaAnexoHashService::faturaDuplicadaIdDoRequest((object) [
            'fatura_duplicada_id' => 0,
        ]));
    }

    public function test_codigo_anexo_duplicado_na_exception(): void
    {
        $ex = new FaturaSelecaoException(FaturaSelecaoException::CODIGO_ANEXO_DUPLICADO, [
            'anexo_duplicado' => true,
        ]);

        $payload = $ex->toResponseArray();

        $this->assertTrue($payload['error']);
        $this->assertSame('anexo_duplicado', $payload['codigo']);
        $this->assertTrue($payload['anexo_duplicado']);
        $this->assertSame(422, $ex->getCode());
    }
}
