<?php

namespace Tests\Unit;

use App\Exceptions\FaturaSelecaoException;
use App\Models\Fatura;
use App\Services\Fatura\FaturaAnexoHashService;
use App\Services\Fatura\FaturaSubstituirExistenteService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class FaturaSubstituirExistenteServiceTest extends TestCase
{
    public function test_confirmou_aceita_booleanos_e_strings(): void
    {
        $this->assertTrue(FaturaSubstituirExistenteService::confirmou((object) [
            'confirmar_substituir_fatura' => true,
        ]));
        $this->assertTrue(FaturaSubstituirExistenteService::confirmou((object) [
            'confirmar_substituir_fatura' => 'true',
        ]));
        $this->assertTrue(FaturaSubstituirExistenteService::confirmou((object) [
            'confirmar_substituir_fatura' => '1',
        ]));
        $this->assertFalse(FaturaSubstituirExistenteService::confirmou((object) []));
        $this->assertFalse(FaturaSubstituirExistenteService::confirmou((object) [
            'confirmar_substituir_fatura' => 'false',
        ]));
    }

    public function test_fatura_existente_id_do_request(): void
    {
        $this->assertSame(591, FaturaSubstituirExistenteService::faturaExistenteIdDoRequest((object) [
            'fatura_existente_id' => '591',
        ]));
        $this->assertNull(FaturaSubstituirExistenteService::faturaExistenteIdDoRequest((object) []));
        $this->assertNull(FaturaSubstituirExistenteService::faturaExistenteIdDoRequest((object) [
            'fatura_existente_id' => 0,
        ]));
    }

    public function test_acao_sugerida_cadastrar_sem_fatura_ou_stub(): void
    {
        $this->assertSame(
            FaturaSubstituirExistenteService::ACAO_CADASTRAR,
            FaturaSubstituirExistenteService::acaoSugerida(null)
        );

        $stub = $this->fatura(['arquivo_pdf' => null, 'anexo_pdf_id' => null]);

        $this->assertSame(
            FaturaSubstituirExistenteService::ACAO_CADASTRAR,
            FaturaSubstituirExistenteService::acaoSugerida($stub)
        );
    }

    public function test_acao_sugerida_substituir_quando_tem_anexo(): void
    {
        $comPdf = $this->fatura(['arquivo_pdf' => 'faturas/1/aberto.pdf']);
        $comCatalogo = $this->fatura(['arquivo_pdf' => null, 'anexo_pdf_id' => 33]);

        $this->assertSame(
            FaturaSubstituirExistenteService::ACAO_SUBSTITUIR,
            FaturaSubstituirExistenteService::acaoSugerida($comPdf)
        );
        $this->assertSame(
            FaturaSubstituirExistenteService::ACAO_SUBSTITUIR,
            FaturaSubstituirExistenteService::acaoSugerida($comCatalogo)
        );
    }

    public function test_stub_sem_anexo_nao_exige_confirmacao(): void
    {
        $stub = $this->fatura(['arquivo_pdf' => null]);
        $svc = new FaturaSubstituirExistenteService;
        $file = $this->arquivoTemp('pdf-fechado');

        try {
            $this->assertFalse($svc->deveExigirConfirmacao($stub, (object) [
                'arquivo_pdf' => $file,
            ]));
        } finally {
            @unlink($file->getRealPath());
        }
    }

    public function test_fatura_com_pdf_e_arquivo_novo_exige_confirmacao(): void
    {
        $file = $this->arquivoTemp('pdf-fechado');
        $existente = $this->fatura([
            'arquivo_pdf' => 'faturas/1/aberto.pdf',
            'anexo_hash' => FaturaAnexoHashService::hashConteudo('pdf-aberto'),
        ]);
        $svc = new FaturaSubstituirExistenteService;

        try {
            $this->assertTrue($svc->deveExigirConfirmacao($existente, (object) [
                'arquivo_pdf' => $file,
            ]));
        } finally {
            @unlink($file->getRealPath());
        }
    }

    public function test_mesmo_hash_nao_exige_fatura_ja_anexada(): void
    {
        $conteudo = 'mesmo-pdf-bytes';
        $file = $this->arquivoTemp($conteudo);
        $existente = $this->fatura([
            'arquivo_pdf' => 'faturas/1/mesmo.pdf',
            'anexo_hash' => FaturaAnexoHashService::hashConteudo($conteudo),
        ]);
        $svc = new FaturaSubstituirExistenteService;

        try {
            $this->assertFalse($svc->deveExigirConfirmacao($existente, (object) [
                'arquivo_pdf' => $file,
            ]));
        } finally {
            @unlink($file->getRealPath());
        }
    }

    public function test_flag_dispensa_o_422(): void
    {
        $file = $this->arquivoTemp('pdf-fechado');
        $existente = $this->fatura([
            'arquivo_pdf' => 'faturas/1/aberto.pdf',
            'anexo_hash' => FaturaAnexoHashService::hashConteudo('pdf-aberto'),
        ]);
        $svc = new FaturaSubstituirExistenteService;

        try {
            $this->assertFalse($svc->deveExigirConfirmacao($existente, (object) [
                'arquivo_pdf' => $file,
                'confirmar_substituir_fatura' => true,
                'fatura_existente_id' => 591,
            ]));
        } finally {
            @unlink($file->getRealPath());
        }
    }

    public function test_codigo_fatura_ja_anexada_na_exception(): void
    {
        $ex = new FaturaSelecaoException(FaturaSelecaoException::CODIGO_FATURA_JA_ANEXADA, [
            'fatura_ja_anexada' => true,
            'acao_sugerida' => 'substituir',
            'fatura_existente' => [
                'id' => 591,
                'tem_anexo' => true,
                'tem_pdf' => true,
            ],
        ]);

        $payload = $ex->toResponseArray();

        $this->assertTrue($payload['error']);
        $this->assertSame('fatura_ja_anexada', $payload['codigo']);
        $this->assertTrue($payload['fatura_ja_anexada']);
        $this->assertSame('substituir', $payload['acao_sugerida']);
        $this->assertTrue($payload['fatura_existente']['tem_anexo']);
        $this->assertSame(422, $ex->getCode());
        $this->assertStringContainsString('substituir a fatura', $payload['message']);
    }

    public function test_substituir_sempre_dispara_processamento(): void
    {
        $this->assertTrue(FaturaSubstituirExistenteService::deveDispararProcessamento((object) [
            'confirmar_substituir_fatura' => true,
            'processar_automatico' => false,
        ], false));

        $this->assertTrue(FaturaSubstituirExistenteService::deveDispararProcessamento((object) [
            'processar_automatico' => false,
        ], true));

        $this->assertFalse(FaturaSubstituirExistenteService::deveDispararProcessamento((object) [
            'processar_automatico' => false,
        ], false));

        $this->assertTrue(FaturaSubstituirExistenteService::deveDispararProcessamento((object) [], false));
    }

    public function test_processando_bloqueia_substituir_com_codigo(): void
    {
        $fatura = $this->fatura(['status' => 'processando']);
        $svc = new FaturaSubstituirExistenteService;

        try {
            $svc->throwSeProcessando($fatura, 1);
            $this->fail('Esperava 422 fatura_processando');
        } catch (FaturaSelecaoException $e) {
            $payload = $e->toResponseArray();
            $this->assertSame('fatura_processando', $payload['codigo']);
            $this->assertTrue($payload['fatura_processando']);
            $this->assertSame(591, $payload['fatura_existente_id']);
            $this->assertSame(422, $e->getCode());
            $this->assertSame(FaturaSubstituirExistenteService::MENSAGEM_PROCESSANDO, $payload['message']);
        }
    }

    public function test_nao_bloqueia_quando_nao_esta_processando(): void
    {
        $svc = new FaturaSubstituirExistenteService;
        $svc->throwSeProcessando($this->fatura(['status' => 'processada']), 1);
        $this->assertTrue(true);
    }

    public function test_mensagem_deixa_claro_que_transacoes_atualizam(): void
    {
        $this->assertStringContainsString(
            'transações estão sendo atualizadas',
            FaturaSubstituirExistenteService::MENSAGEM_SUBSTITUIDA
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function fatura(array $attrs): Fatura
    {
        $fatura = new Fatura;
        $fatura->setRawAttributes(array_merge([
            'id' => 591,
            'status' => 'pendente',
            'arquivo_pdf' => null,
            'arquivo_csv' => null,
            'anexo_pdf_id' => null,
            'anexo_csv_id' => null,
            'anexo_hash' => null,
            'deleted_at' => null,
        ], $attrs), true);

        return $fatura;
    }

    private function arquivoTemp(string $conteudo): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $conteudo);

        return new UploadedFile($path, 'fatura.pdf', 'application/pdf', null, true);
    }
}
