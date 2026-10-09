<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClassificarTransacoesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_classificar_como_estorno_zera_categoria_e_recalcula_a_fatura(): void
    {
        $ctx = $this->cenario();
        $ctx['compra']->categoria_id = $ctx['categoriaId'];
        $ctx['compra']->save();

        $response = $this->actingAs($ctx['user'], 'sanctum')->postJson('/api/v1/transacoes/classificar', [
            'ids' => [$ctx['compra']->id],
            'tipo' => Transacao::TIPO_REFUND,
        ]);

        $response->assertOk()
            ->assertJsonPath('atualizadas', 1)
            ->assertJsonPath('status', true);

        $ctx['compra']->refresh();
        $ctx['fatura']->refresh();

        $this->assertSame(Transacao::TIPO_REFUND, $ctx['compra']->tipo);
        $this->assertNull($ctx['compra']->categoria_id);
        $this->assertSame(90.0, (float) $ctx['fatura']->valor_total);
    }

    public function test_classificar_estorno_em_fatura_processada_atualiza_o_total(): void
    {
        $ctx = $this->cenario();
        $ctx['fatura']->update([
            'status' => 'processada',
            'valor_total' => 110,
            'valor_fatura' => 110,
        ]);

        $this->actingAs($ctx['user'], 'sanctum')->postJson('/api/v1/transacoes/classificar', [
            'ids' => [$ctx['compra']->id],
            'tipo' => Transacao::TIPO_REFUND,
        ])->assertOk();

        $ctx['fatura']->refresh();
        $this->assertSame(90.0, (float) $ctx['fatura']->valor_total);
        $this->assertSame(90.0, (float) $ctx['fatura']->valor_fatura);
    }

    public function test_classificar_exige_tipo_ou_final(): void
    {
        $ctx = $this->cenario();

        $this->actingAs($ctx['user'], 'sanctum')->postJson('/api/v1/transacoes/classificar', [
            'ids' => [$ctx['compra']->id],
        ])->assertStatus(422);
    }

    /**
     * @return array{user: User, fatura: Fatura, compra: Transacao, categoriaId: int}
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'classificar-'.uniqid('', true).'@test.local',
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
        $fatura = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 7,
            'ano' => 2018,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
        $estabelecimento = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'Variação cambial',
            'ativo' => true,
        ]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);
        $categoriaId = \App\Models\Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Outros',
            'ativo' => true,
        ])->id;
        $compra = Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $fatura->id,
            'estabelecimento_id' => $estabelecimento->id,
            'responsavel_id' => $responsavel->id,
            'data' => '2018-06-24',
            'valor' => 10,
            'tipo' => Transacao::TIPO_PURCHASE,
            'categoria_id' => $categoriaId,
            'importada_pdf' => true,
        ]);
        Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $fatura->id,
            'estabelecimento_id' => $estabelecimento->id,
            'responsavel_id' => $responsavel->id,
            'data' => '2018-06-25',
            'valor' => 100,
            'tipo' => Transacao::TIPO_PURCHASE,
            'importada_pdf' => true,
        ]);

        return compact('user', 'fatura', 'compra', 'categoriaId');
    }
}
