<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PropagarFinalCartaoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_com_flag_o_final_chega_nas_parcelas_que_ainda_nao_tinham_grupo(): void
    {
        $ctx = $this->cenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['parcelas'][0]->id,
            'cartao_numero_id' => $ctx['numero']->id,
            'propagar_grupo' => true,
        ]);

        $response->assertOk();

        $grupos = [];
        foreach ($ctx['parcelas'] as $parcela) {
            $parcela->refresh();
            $this->assertSame($ctx['numero']->id, $parcela->cartao_numero_id);
            $this->assertNotEmpty($parcela->compra_grupo_id);
            $grupos[] = $parcela->compra_grupo_id;
            $this->assertSame('80.00', number_format((float) $parcela->valor, 2, '.', ''));
        }

        $this->assertCount(1, array_unique($grupos));

        $ctx['avista']->refresh();
        $this->assertNull($ctx['avista']->cartao_numero_id);
        $this->assertNull($ctx['avista']->compra_grupo_id);
    }

    public function test_sem_flag_so_a_parcela_editada_recebe_o_final(): void
    {
        $ctx = $this->cenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['parcelas'][0]->id,
            'cartao_numero_id' => $ctx['numero']->id,
        ]);

        $response->assertOk();

        $ctx['parcelas'][0]->refresh();
        $this->assertSame($ctx['numero']->id, $ctx['parcelas'][0]->cartao_numero_id);

        $ctx['parcelas'][1]->refresh();
        $ctx['parcelas'][2]->refresh();
        $this->assertNull($ctx['parcelas'][1]->cartao_numero_id);
        $this->assertNull($ctx['parcelas'][2]->cartao_numero_id);
        $this->assertSame((int) $ctx['faturas'][1]->id, (int) $ctx['parcelas'][1]->fatura_id);
        $this->assertSame((int) $ctx['faturas'][2]->id, (int) $ctx['parcelas'][2]->fatura_id);
    }

    /**
     * @return array{user: User, numero: CartaoNumero, faturas: array<int, Fatura>, parcelas: array<int, Transacao>, avista: Transacao}
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat30-'.uniqid('', true).'@test.local',
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
        $numero = CartaoNumero::create([
            'cartao_bandeira_id' => $bandeira->id,
            'ultimos_digitos' => '7025',
            'tipo' => CartaoNumero::TIPO_FISICO,
            'ativo' => true,
        ]);
        $estabelecimento = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'Loja',
            'ativo' => true,
        ]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);

        $faturas = [];
        $parcelas = [];
        foreach ([7, 8, 9] as $indice => $mes) {
            $fatura = Fatura::create([
                'user_id' => $user->id,
                'cartao_id' => $cartao->id,
                'cartao_bandeira_id' => $bandeira->id,
                'mes' => $mes,
                'ano' => 2026,
                'valor_total' => 0,
                'status' => 'pendente',
            ]);
            $faturas[] = $fatura;
            $parcelas[] = Transacao::create([
                'user_id' => $user->id,
                'fatura_id' => $fatura->id,
                'estabelecimento_id' => $estabelecimento->id,
                'data' => '2026-07-10',
                'valor' => 80,
                'valor_parcela' => 80,
                'parcelas_total' => 3,
                'parcela_atual' => $indice + 1,
                'tipo' => Transacao::TIPO_PURCHASE,
                'responsavel_id' => $responsavel->id,
                'compra_grupo_id' => null,
                'importada_pdf' => true,
            ]);
        }

        $avista = Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $faturas[0]->id,
            'estabelecimento_id' => $estabelecimento->id,
            'data' => '2026-07-12',
            'valor' => 15,
            'parcelas_total' => 1,
            'parcela_atual' => 1,
            'tipo' => Transacao::TIPO_PURCHASE,
            'responsavel_id' => $responsavel->id,
            'compra_manual' => true,
        ]);

        return compact('user', 'numero', 'faturas', 'parcelas', 'avista');
    }
}
