<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Estabelecimento;
use App\Models\Transacao;
use App\Models\User;
use App\Services\Transacao\TransacaoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CadastrarTransacaoLoteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lote_de_duas_compras_grava_todas_manuais(): void
    {
        $user = $this->criarUsuario();
        $cartao = $this->criarCartao($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => [
                $this->compra($cartao, 'Mouse Logitech', '249,90', 1),
                $this->compra($cartao, 'Notebook', '3.000,00', 2),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('compras.0.transacao.data.parcelas_total', 1)
            ->assertJsonPath('compras.0.transacao.data.compra_grupo_id', null)
            ->assertJsonPath('compras.0.transacao.data.transacoes.0.compra_manual', true)
            ->assertJsonPath('compras.0.transacao.data.transacoes.0.precisa_conciliar', true)
            ->assertJsonPath('compras.0.transacao.data.transacoes.0.estabelecimento_id', null)
            ->assertJsonPath('compras.0.transacao.data.transacoes.0.observacoes', 'Mouse Logitech')
            ->assertJsonPath('compras.1.transacao.data.parcelas_total', 2)
            ->assertJsonPath('compras.1.transacao.data.transacoes.0.compra_manual', true)
            ->assertJsonPath('compras.1.transacao.data.transacoes.0.precisa_conciliar', true)
            ->assertJsonPath('compras.1.transacao.data.transacoes.1.compra_manual', true)
            ->assertJsonPath('compras.1.transacao.data.transacoes.1.observacoes', 'Notebook');

        $grupo = $response->json('compras.1.transacao.data.compra_grupo_id');
        $this->assertNotEmpty($grupo);

        $linhas = Transacao::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(3, $linhas);
        $this->assertTrue($linhas->every(fn (Transacao $t) => (bool) $t->compra_manual));
        $this->assertTrue($linhas->every(
            fn (Transacao $t) => $t->status_conciliacao === Transacao::CONCILIACAO_NAO_CONCILIADA
        ));
        $this->assertTrue($linhas->every(fn (Transacao $t) => $t->estabelecimento_id === null));
        $this->assertSame(0, Estabelecimento::where('user_id', $user->id)->count());

        $avista = $linhas->firstWhere('observacoes', 'Mouse Logitech');
        $this->assertNull($avista->compra_grupo_id);
        $parcelas = $linhas->where('observacoes', 'Notebook');
        $this->assertCount(2, $parcelas);
        $this->assertSame([$grupo, $grupo], $parcelas->pluck('compra_grupo_id')->all());
    }

    public function test_uma_compra_no_array_grava(): void
    {
        $user = $this->criarUsuario();
        $cartao = $this->criarCartao($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => [
                $this->compra($cartao, 'Café', '18,00', 1),
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'compras')
            ->assertJsonPath('compras.0.transacao.data.transacoes.0.observacoes', 'Café');

        $this->assertSame(1, Transacao::where('user_id', $user->id)->count());
    }

    public function test_item_invalido_no_meio_nao_grava_nada(): void
    {
        $user = $this->criarUsuario();
        $cartao = $this->criarCartao($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => [
                $this->compra($cartao, 'Mouse Logitech', '249,90', 1),
                [
                    'cartao_id' => $cartao->id,
                    'observacoes' => 'Sem valor',
                    'data' => '2026-08-27',
                    'tipo' => 'purchase',
                    'parcelas_total' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', true)
            ->assertJsonPath('indice', 1)
            ->assertJsonPath('message', 'Valor da compra é obrigatório');

        $this->assertSame(0, Transacao::where('user_id', $user->id)->count());
    }

    public function test_zero_ou_vinte_e_um_itens_retornam_422(): void
    {
        $user = $this->criarUsuario();
        $cartao = $this->criarCartao($user);

        $vazio = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => [],
        ]);
        $vazio->assertStatus(422)
            ->assertJsonPath('message', 'Envie entre 1 e 20 compras')
            ->assertJsonMissingPath('indice');

        $itens = [];
        for ($i = 0; $i < TransacaoService::LOTE_MAX + 1; $i++) {
            $itens[] = $this->compra($cartao, 'Item '.$i, '10,00', 1);
        }

        $cheio = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => $itens,
        ]);
        $cheio->assertStatus(422)
            ->assertJsonPath('message', 'Envie entre 1 e 20 compras')
            ->assertJsonMissingPath('indice');

        $this->assertSame(0, Transacao::where('user_id', $user->id)->count());
    }

    public function test_user_id_do_item_nao_troca_o_dono(): void
    {
        $dono = $this->criarUsuario();
        $outro = $this->criarUsuario();
        $cartao = $this->criarCartao($dono);
        $item = $this->compra($cartao, 'Mouse Logitech', '249,90', 1);
        $item['user_id'] = $outro->id;

        $response = $this->actingAs($dono, 'sanctum')->postJson('/api/v1/transacoes/cadastrar-lote', [
            'compras' => [$item],
        ]);

        $response->assertOk();
        $this->assertSame(1, Transacao::where('user_id', $dono->id)->count());
        $this->assertSame(0, Transacao::where('user_id', $outro->id)->count());
    }

    private function criarUsuario(): User
    {
        return User::create([
            'name' => 'Leo',
            'email' => 'ctlfat17-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
    }

    private function criarCartao(User $user): Cartao
    {
        return Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'banco' => 'Nubank',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function compra(Cartao $cartao, string $descricao, string $valor, int $parcelas): array
    {
        return [
            'cartao_id' => $cartao->id,
            'observacoes' => $descricao,
            'valor_compra' => $valor,
            'data' => '2026-08-27',
            'tipo' => 'purchase',
            'parcelas_total' => $parcelas,
        ];
    }
}
