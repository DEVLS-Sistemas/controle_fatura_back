<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\Fatura;
use App\Models\User;
use App\Services\Pdf\InvoicePdfParserService;
use App\Services\Pdf\PdfSenhaRegra;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadPdfSenhaCartaoTest extends TestCase
{
    use DatabaseTransactions;

    private const SENHA_SOFISA = 'segredo123';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_upload_pdf_usa_senha_gravada_no_cartao_sem_campo_senha(): void
    {
        $this->pularSeNaoPuderCriptografarPdf();

        $user = $this->criarUsuario();
        $cartao = $this->criarCartaoSofisaComSenha($user, self::SENHA_SOFISA);
        $fatura = $this->criarFaturaStub($user, $cartao);
        $pdf = $this->uploadPdfProtegido(self::SENHA_SOFISA);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/upload-pdf', [
            'id' => $fatura->id,
            'arquivo_pdf' => $pdf,
        ]);

        $response->assertOk()
            ->assertJsonPath('fatura.data.tem_pdf', true)
            ->assertJsonPath('fatura.data.id', $fatura->id);

        $fatura->refresh();
        $this->assertTrue($fatura->temPdf());
    }

    public function test_upload_pdf_sem_senha_no_cartao_devolve_pdf_senha_necessaria(): void
    {
        $this->pularSeNaoPuderCriptografarPdf();

        $user = $this->criarUsuario();
        $cartao = $this->criarCartaoSofisaComSenha($user, null);
        $fatura = $this->criarFaturaStub($user, $cartao);
        $pdf = $this->uploadPdfProtegido(self::SENHA_SOFISA);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/upload-pdf', [
            'id' => $fatura->id,
            'arquivo_pdf' => $pdf,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', PdfSenhaRegra::CODIGO_SENHA_NECESSARIA)
            ->assertJsonPath('precisa_senha_pdf', true)
            ->assertJsonPath('senha_pdf.motivo', 'ausente')
            ->assertJsonPath('senha_pdf.tem_senha_cadastrada', false)
            ->assertJsonPath('senha_pdf.cartao_id', $cartao->id);

        $fatura->refresh();
        $this->assertFalse($fatura->temPdf());
    }

    public function test_upload_pdf_com_senha_salva_errada_devolve_pdf_senha_incorreta(): void
    {
        $this->pularSeNaoPuderCriptografarPdf();

        $user = $this->criarUsuario();
        $cartao = $this->criarCartaoSofisaComSenha($user, 'senha-errada');
        $fatura = $this->criarFaturaStub($user, $cartao);
        $pdf = $this->uploadPdfProtegido(self::SENHA_SOFISA);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/upload-pdf', [
            'id' => $fatura->id,
            'arquivo_pdf' => $pdf,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', PdfSenhaRegra::CODIGO_SENHA_INCORRETA)
            ->assertJsonPath('precisa_senha_pdf', true)
            ->assertJsonPath('senha_pdf.motivo', 'incorreta')
            ->assertJsonPath('senha_pdf.tem_senha_cadastrada', true);
    }

    public function test_cadastrar_com_senha_sem_arquivo_nao_cria_fatura_zerada(): void
    {
        $user = $this->criarUsuario();
        $cartao = $this->criarCartaoSofisaComSenha($user, null);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $cartao->id,
            'mes' => 9,
            'ano' => 2026,
            'senha_pdf' => self::SENHA_SOFISA,
            'salvar_senha_pdf' => true,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('PDF', (string) $response->json('message'));
        $this->assertSame(0, Fatura::where('user_id', $user->id)->count());
    }

    public function test_get_pdf_serve_arquivo_ja_aberto_com_senha_do_cartao(): void
    {
        $this->pularSeNaoPuderCriptografarPdf();

        $user = $this->criarUsuario();
        $cartao = $this->criarCartaoSofisaComSenha($user, self::SENHA_SOFISA);
        $fatura = $this->criarFaturaStub($user, $cartao);

        $origem = $this->criarPdfProtegido(self::SENHA_SOFISA);
        $relative = 'faturas/'.$user->id.'/sofisa-protegido.pdf';
        Storage::disk('local')->put($relative, file_get_contents($origem));
        $fatura->arquivo_pdf = $relative;
        $fatura->save();

        $response = $this->actingAs($user, 'sanctum')->get('/api/v1/faturas/pdf/'.$fatura->id);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $baixado = sys_get_temp_dir().'/fatura_preview_'.uniqid('', true).'.pdf';
        file_put_contents($baixado, $response->getContent());
        $this->assertFalse(
            (new InvoicePdfParserService)->pdfEstaCriptografado($baixado),
            'O preview deve ir sem criptografia para o browser'
        );
        @unlink($baixado);
        Storage::disk('local')->delete($relative);
    }

    private function pularSeNaoPuderCriptografarPdf(): void
    {
        if (! (new InvoicePdfParserService)->ghostscriptDisponivel()) {
            $this->markTestSkipped('Ghostscript (gs) é necessário para a fixture de PDF com senha.');
        }
    }

    private function criarUsuario(): User
    {
        return User::create([
            'name' => 'Leo',
            'email' => 'ctlfat14-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
    }

    private function criarCartaoSofisaComSenha(User $user, ?string $senha): Cartao
    {
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Sofisa',
            'banco' => 'Sofisa',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
            'senha_pdf' => $senha,
            'senha_pdf_regra' => PdfSenhaRegra::CPF_11_DIGITOS,
        ]);

        CartaoBandeira::create([
            'cartao_id' => $cartao->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);

        return $cartao;
    }

    private function criarFaturaStub(User $user, Cartao $cartao): Fatura
    {
        $bandeiraId = CartaoBandeira::where('cartao_id', $cartao->id)->value('id');

        return Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'cartao_bandeira_id' => $bandeiraId,
            'mes' => 9,
            'ano' => 2026,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
    }

    private function uploadPdfProtegido(string $senha): UploadedFile
    {
        $path = $this->criarPdfProtegido($senha);

        return new UploadedFile($path, 'Fatura_Sofisa.pdf', 'application/pdf', null, true);
    }

    private function criarPdfProtegido(string $senha): string
    {
        $dir = sys_get_temp_dir().'/sofisa_pdf_'.uniqid('', true);
        mkdir($dir);
        $plain = $dir.'/plain.pdf';
        $enc = $dir.'/sofisa-protegido.pdf';
        $gs = '/usr/bin/gs';

        $criar = proc_open(
            [
                $gs, '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite',
                '-sOutputFile='.$plain,
                '-c', 'showpage',
            ],
            [2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($criar)) {
            fclose($pipes[2]);
            proc_close($criar);
        }

        $criptografar = proc_open(
            [
                $gs, '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite',
                '-sOwnerPassword=owner',
                '-sUserPassword='.$senha,
                '-dEncryptionR=3',
                '-dKeyLength=128',
                '-sOutputFile='.$enc,
                $plain,
            ],
            [2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($criptografar)) {
            fclose($pipes[2]);
            proc_close($criptografar);
        }

        $this->assertFileExists($enc);

        return $enc;
    }
}
