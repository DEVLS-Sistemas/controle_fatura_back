<?php

namespace Tests\Feature;

use App\Exceptions\PdfPasswordException;
use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\Fatura;
use App\Models\User;
use App\Services\Cartao\BandeiraCoresPreset;
use App\Services\Pdf\InvoicePdfParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CadastrarFaturaMetadadosCartaoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindParserDeTexto();
    }

    public function test_cartao_id_picpay_com_arquivo_sofisa_sugere_sofisa_e_bandeiras_completas(): void
    {
        $user = $this->criarUsuario();
        $picpay = $this->criarCartao($user, 'PicPay', 'PicPay');
        CartaoBandeira::create([
            'cartao_id' => $picpay->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $picpay->id,
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoSofisa()),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', 'precisa_confirmar_metadados')
            ->assertJsonPath('modo', 'cadastrar_cartao')
            ->assertJsonPath('sugestao.parser', 'sofisa')
            ->assertJsonPath('sugestao.cartao_id', null)
            ->assertJsonPath('sugestao.cartao_nome_sugerido', 'Sofisa')
            ->assertJsonPath('sugestao.bandeira_sugerida', 'Mastercard');

        $bandeiras = $response->json('bandeiras');
        $this->assertIsArray($bandeiras);
        $this->assertCount(count(BandeiraCoresPreset::paresParaLookups()), $bandeiras);
        $labels = array_column($bandeiras, 'label');
        $this->assertContains('Visa', $labels);
        $this->assertContains('Mastercard', $labels);
        $this->assertTrue(collect($bandeiras)->every(fn (array $b) => ! empty($b['criar'])));
    }

    public function test_cartao_id_picpay_com_sofisa_cadastrado_sugere_o_sofisa(): void
    {
        $user = $this->criarUsuario();
        $picpay = $this->criarCartao($user, 'PicPay', 'PicPay');
        $sofisa = $this->criarCartao($user, 'Sofisa', 'Sofisa');
        CartaoBandeira::create([
            'cartao_id' => $picpay->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $picpay->id,
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoSofisa()),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', 'precisa_confirmar_metadados')
            ->assertJsonPath('modo', 'confirmar_cartao')
            ->assertJsonPath('sugestao.parser', 'sofisa')
            ->assertJsonPath('sugestao.cartao_id', $sofisa->id)
            ->assertJsonPath('sugestao.cartao_nome_sugerido', 'Sofisa');
    }

    public function test_competencia_existente_exige_escolha_da_bandeira(): void
    {
        $user = $this->criarUsuario();
        $this->criarCartao($user, 'PicPay', 'PicPay');
        $sofisa = $this->criarCartao($user, 'Sofisa', 'Sofisa');
        $visa = CartaoBandeira::create([
            'cartao_id' => $sofisa->id,
            'bandeira' => 'Visa',
            'ativo' => true,
        ]);
        $master = CartaoBandeira::create([
            'cartao_id' => $sofisa->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);
        Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $sofisa->id,
            'cartao_bandeira_id' => $visa->id,
            'mes' => 9,
            'ano' => 2026,
            'arquivo_pdf' => 'faturas/visa.pdf',
            'status' => 'processada',
        ]);
        $masterFatura = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $sofisa->id,
            'cartao_bandeira_id' => $master->id,
            'mes' => 9,
            'ano' => 2026,
            'arquivo_pdf' => 'faturas/master.pdf',
            'status' => 'processada',
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoSofisa()),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', 'precisa_confirmar_metadados')
            ->assertJsonPath('modo', 'confirmar_cartao')
            ->assertJsonPath('precisa_selecionar_bandeira', true)
            ->assertJsonPath('sugestao.cartao_id', $sofisa->id)
            ->assertJsonPath('sugestao.cartao_bandeira_id', $master->id)
            ->assertJsonPath('fatura_existente.id', $masterFatura->id)
            ->assertJsonPath('fatura_existente.cartao_bandeira_id', $master->id)
            ->assertJsonPath('fatura_existente.bandeira', 'Mastercard');

        $bandeiras = $response->json('bandeiras');
        $this->assertIsArray($bandeiras);
        $labels = array_column($bandeiras, 'label');
        $this->assertContains('Mastercard', $labels);
        $this->assertContains('Visa', $labels);
        $masterOpt = collect($bandeiras)->firstWhere('label', 'Mastercard');
        $this->assertSame($master->id, $masterOpt['value']);
        $this->assertCount(2, $response->json('faturas_periodo'));
    }

    public function test_cartao_id_picpay_com_arquivo_picpay_confirma_o_picpay(): void
    {
        $user = $this->criarUsuario();
        $picpay = $this->criarCartao($user, 'PicPay', 'PicPay');

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $picpay->id,
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoPicPay()),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', 'precisa_confirmar_metadados')
            ->assertJsonPath('modo', 'confirmar_cartao')
            ->assertJsonPath('sugestao.parser', 'picpay')
            ->assertJsonPath('sugestao.cartao_id', $picpay->id)
            ->assertJsonPath('sugestao.confianca', 'informado');
    }

    public function test_cadastrar_usa_senha_gravada_no_cartao_sem_pedir_de_novo(): void
    {
        $user = $this->criarUsuario();
        $sofisa = $this->criarCartao($user, 'Sofisa', 'Sofisa');
        $sofisa->senha_pdf = 'segredo123';
        $sofisa->save();
        $this->bindParserQueExigeSenha('segredo123', $this->textoSofisa());

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $sofisa->id,
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoSofisa()),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('codigo', 'precisa_confirmar_metadados')
            ->assertJsonPath('sugestao.cartao_id', $sofisa->id);
    }

    public function test_salvar_senha_no_422_de_metadados_nao_e_desfeito_pelo_rollback(): void
    {
        $user = $this->criarUsuario();
        $sofisa = $this->criarCartao($user, 'Sofisa', 'Sofisa');
        $this->assertFalse($sofisa->temSenhaPdf());
        $this->bindParserQueExigeSenha('segredo123', $this->textoSofisa());

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $sofisa->id,
            'senha_pdf' => 'segredo123',
            'salvar_senha_pdf' => true,
            'arquivo_pdf' => $this->uploadTextoComoPdf($this->textoSofisa()),
        ]);

        $response->assertStatus(422)->assertJsonPath('codigo', 'precisa_confirmar_metadados');
        $sofisa->refresh();
        $this->assertTrue($sofisa->temSenhaPdf());
        $this->assertSame('segredo123', (string) $sofisa->senha_pdf);
    }

    private function bindParserDeTexto(): void
    {
        $this->app->bind(InvoicePdfParserService::class, function () {
            return new class extends InvoicePdfParserService
            {
                public function parseUploadedFile(UploadedFile $file, ?string $senhaPdf = null): array
                {
                    $path = $file->getRealPath() ?: $file->getPathname();

                    return $this->parseExtractedText((string) file_get_contents($path));
                }
            };
        });
    }

    private function bindParserQueExigeSenha(string $senhaEsperada, string $texto): void
    {
        $this->app->bind(InvoicePdfParserService::class, function () use ($senhaEsperada, $texto) {
            return new class($senhaEsperada, $texto) extends InvoicePdfParserService
            {
                public function __construct(
                    private string $senhaEsperada,
                    private string $textoFatura,
                ) {
                    parent::__construct();
                }

                public function parseUploadedFile(UploadedFile $file, ?string $senhaPdf = null): array
                {
                    if ($senhaPdf !== $this->senhaEsperada) {
                        throw new PdfPasswordException(
                            motivo: ($senhaPdf === null || $senhaPdf === '')
                                ? PdfPasswordException::MOTIVO_AUSENTE
                                : PdfPasswordException::MOTIVO_INCORRETA
                        );
                    }

                    return $this->parseExtractedText($this->textoFatura);
                }
            };
        });
    }

    private function criarUsuario(): User
    {
        return User::create([
            'name' => 'Leo',
            'email' => 'ctlfat13-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
    }

    private function criarCartao(User $user, string $nome, string $banco): Cartao
    {
        return Cartao::create([
            'user_id' => $user->id,
            'nome' => $nome,
            'banco' => $banco,
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
    }

    private function uploadTextoComoPdf(string $texto): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fatura_');
        file_put_contents($path, $texto);

        return new UploadedFile($path, 'Fatura_10092026.pdf', 'application/pdf', null, true);
    }

    private function textoSofisa(): string
    {
        return <<<'TXT'
Nome do titular LEONARDO DA SILVA FERREIRA

Olá, LEONARDO chegou a fatura com                                            Total a Pagar                    Vencimento
                                                                              R$ 162,04                       10/09/2026
as compras e pagamentos feitos até
01/09/2026 com o seu cartão SOFISA
                                                                          Pagamento mínimo              Melhor dia para compra
DIRETO MASTERCARD.                                                             R$ 24,31                       02/09/2026

(+) Total a Pagar                                         162,04

Detalhamento da Fatura
Despesas Cartão - 0217                                  R$ 162,04

Data         Transações                Moeda Original    Valor (R$)

15/11        PICPAY*WC5 JOYCESILV                             10,00
15/11        ComercialDe 10/12                                13,54
TXT;
    }

    private function textoPicPay(): string
    {
        return <<<'TXT'
PicPay Bank Banco Múltiplo S.A.
Vencimento: 10/09/2026 | Fechamento: 03/09/2026
Esta é a sua fatura de Setembro.
Total da fatura                                      R$ 2.288,25
Mastercard® GOLD

Picpay Card final 7025
Transações Nacionais
11/08     PAGAMENTO DE FATURA          -2.818,50
TXT;
    }
}
