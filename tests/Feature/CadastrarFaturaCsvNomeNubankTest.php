<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
use App\Models\Fatura;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CadastrarFaturaCsvNomeNubankTest extends TestCase
{
    use DatabaseTransactions;

    public function test_nubank_2018_10_com_datas_de_setembro_cadastra_outubro(): void
    {
        [$user, $cartao, $bandeira] = $this->cenarioComSetembroAnexado();

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $cartao->id,
            'cartao_bandeira_id' => $bandeira->id,
            'mes' => 9,
            'ano' => 2018,
            'processar_automatico' => '0',
            'arquivo_pdf' => $this->csv('nubank-2018-10.csv'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('fatura.data.mes', 10);
        $response->assertJsonPath('fatura.data.ano', 2018);

        $setembro = Fatura::query()
            ->where('user_id', $user->id)
            ->where('mes', 9)
            ->where('ano', 2018)
            ->first();
        $this->assertNotNull($setembro);
        $this->assertSame('faturas/setembro-2018.csv', $setembro->arquivo_csv);
    }

    public function test_nubank_2018_09_ainda_pede_para_substituir_a_competencia_que_ja_tem_anexo(): void
    {
        [$user, $cartao, $bandeira] = $this->cenarioComSetembroAnexado();

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'cartao_id' => $cartao->id,
            'cartao_bandeira_id' => $bandeira->id,
            'mes' => 9,
            'ano' => 2018,
            'processar_automatico' => '0',
            'arquivo_pdf' => $this->csv('nubank-2018-09.csv'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('codigo', 'fatura_ja_anexada');
        $response->assertJsonPath('fatura_existente.competencia', '09/2018');
    }

    public function test_nome_da_aba_aponta_o_nubank_ja_cadastrado(): void
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat-nubank-existe-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'banco' => 'Nubank',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'processar_automatico' => '0',
            'arquivo_pdf' => $this->csv('nubank-2018-10.csv'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('codigo', 'precisa_confirmar_metadados');
        $response->assertJsonPath('sugestao.cartao_id', $cartao->id);
        $response->assertJsonPath('sugestao.cartao_nome_sugerido', 'Nubank');
        $response->assertJsonPath('sugestao.mes', 10);
        $response->assertJsonPath('sugestao.ano', 2018);
    }

    public function test_nome_da_aba_sugere_nubank_quando_o_cartao_nao_existe(): void
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat-nubank-novo-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/faturas/cadastrar', [
            'processar_automatico' => '0',
            'arquivo_pdf' => $this->csv('nubank-2018-10.csv'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('codigo', 'precisa_confirmar_metadados');
        $response->assertJsonPath('modo', 'cadastrar_cartao');
        $response->assertJsonPath('sugestao.cartao_id', null);
        $response->assertJsonPath('sugestao.cartao_nome_sugerido', 'Nubank');
    }

    /**
     * @return array{0: User, 1: Cartao, 2: CartaoBandeira}
     */
    private function cenarioComSetembroAnexado(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat-nubank-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'banco' => 'Nubank',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
        $bandeira = CartaoBandeira::create([
            'cartao_id' => $cartao->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);
        CartaoNumero::create([
            'cartao_bandeira_id' => $bandeira->id,
            'ultimos_digitos' => '7025',
            'tipo' => CartaoNumero::TIPO_FISICO,
            'ativo' => true,
        ]);
        Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'cartao_bandeira_id' => $bandeira->id,
            'mes' => 9,
            'ano' => 2018,
            'valor_total' => 100,
            'status' => 'processada',
            'arquivo_csv' => 'faturas/setembro-2018.csv',
        ]);

        return [$user, $cartao, $bandeira];
    }

    private function csv(string $nome): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fatura_csv_');
        file_put_contents($path, "date,title,amount\n2018-09-13,Loja,44.82\n2018-09-29,Servico,339\n");

        return new UploadedFile($path, $nome, 'text/csv', null, true);
    }
}
