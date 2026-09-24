<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Categoria;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Subcategoria;
use App\Models\Transacao;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CategoriaOperacionalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_editar_pagamento_ignora_categoria_e_nao_aprende_padrao(): void
    {
        $ctx = $this->cenario();
        $pagamento = $this->linha($ctx, Transacao::TIPO_PAYMENT);

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $pagamento->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => $ctx['sub']->id,
            'aplicar_subcategoria_estabelecimento' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('transacao.data.categoria_id', null)
            ->assertJsonPath('transacao.data.subcategoria_id', null)
            ->assertJsonPath('transacao.data.tipo', Transacao::TIPO_PAYMENT)
            ->assertJsonPath('transacao.data.tipo_label', 'Pagamento')
            ->assertJsonPath('transacao.data.operacional', true)
            ->assertJsonMissingPath('transacao.aplicar_subcategoria');

        $pagamento->refresh();
        $ctx['estabelecimento']->refresh();
        $ctx['compra']->refresh();

        $this->assertNull($pagamento->categoria_id);
        $this->assertNull($pagamento->subcategoria_id);
        $this->assertNull($ctx['estabelecimento']->categoria_padrao_id);
        $this->assertNull($ctx['compra']->categoria_id);
    }

    public function test_editar_compra_grava_categoria_e_subcategoria(): void
    {
        $ctx = $this->cenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['compra']->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => $ctx['sub']->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('transacao.data.categoria_id', $ctx['categoria']->id)
            ->assertJsonPath('transacao.data.subcategoria_id', $ctx['sub']->id)
            ->assertJsonPath('transacao.data.operacional', false);

        $ctx['compra']->refresh();
        $this->assertSame($ctx['categoria']->id, $ctx['compra']->categoria_id);
        $this->assertSame($ctx['sub']->id, $ctx['compra']->subcategoria_id);
    }

    public function test_cadastrar_pagamento_nao_grava_categoria_do_estabelecimento(): void
    {
        $ctx = $this->cenario();
        $ctx['estabelecimento']->categoria_padrao_id = $ctx['categoria']->id;
        $ctx['estabelecimento']->subcategoria_padrao_id = $ctx['sub']->id;
        $ctx['estabelecimento']->save();

        $response = $this->actingAs($ctx['user'], 'sanctum')->postJson('/api/v1/transacoes/cadastrar', [
            'cartao_id' => $ctx['cartao']->id,
            'estabelecimento_id' => $ctx['estabelecimento']->id,
            'observacoes' => 'Pagamento da fatura',
            'valor' => '100,00',
            'data' => '2026-09-10',
            'tipo' => Transacao::TIPO_PAYMENT,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => $ctx['sub']->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('transacao.data.transacoes.0.categoria_id', null)
            ->assertJsonPath('transacao.data.transacoes.0.subcategoria_id', null)
            ->assertJsonPath('transacao.data.transacoes.0.tipo_label', 'Pagamento')
            ->assertJsonPath('transacao.data.transacoes.0.operacional', true);

        $ctx['estabelecimento']->refresh();
        $this->assertSame($ctx['categoria']->id, $ctx['estabelecimento']->categoria_padrao_id);
    }

    public function test_pagamento_com_categoria_legada_nao_conta_como_gasto_categorizado(): void
    {
        $ctx = $this->cenario();
        $pagamento = $this->linha($ctx, Transacao::TIPO_PAYMENT);
        $pagamento->categoria_id = $ctx['categoria']->id;
        $pagamento->save();
        $ctx['compra']->categoria_id = $ctx['categoria']->id;
        $ctx['compra']->save();

        $detalhe = $this->actingAs($ctx['user'], 'sanctum')
            ->get('/api/v1/faturas/listar/'.$ctx['fatura']->id);

        $detalhe->assertOk()
            ->assertJsonPath('transacoes_com_categoria', 1);
    }

    /**
     * @return array{user: User, cartao: Cartao, fatura: Fatura, estabelecimento: Estabelecimento, categoria: Categoria, sub: Subcategoria, compra: Transacao, responsavel: Responsavel}
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat28-'.uniqid('', true).'@test.local',
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
            'mes' => 9,
            'ano' => 2026,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
        $estabelecimento = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'ativo' => true,
        ]);
        $categoria = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Contas',
            'ativo' => true,
        ]);
        $sub = Subcategoria::create([
            'user_id' => $user->id,
            'nome' => 'Fatura',
            'ativo' => true,
        ]);
        $categoria->subcategorias()->attach($sub->id);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);
        $compra = $this->linha([
            'user' => $user,
            'fatura' => $fatura,
            'estabelecimento' => $estabelecimento,
            'responsavel' => $responsavel,
        ], Transacao::TIPO_PURCHASE);

        return compact('user', 'cartao', 'fatura', 'estabelecimento', 'categoria', 'sub', 'compra', 'responsavel');
    }

    /**
     * @param  array{user: User, fatura: Fatura, estabelecimento: Estabelecimento, responsavel: Responsavel}  $ctx
     */
    private function linha(array $ctx, string $tipo): Transacao
    {
        return Transacao::create([
            'user_id' => $ctx['user']->id,
            'fatura_id' => $ctx['fatura']->id,
            'estabelecimento_id' => $ctx['estabelecimento']->id,
            'responsavel_id' => $ctx['responsavel']->id,
            'data' => '2026-09-10',
            'valor' => 80,
            'tipo' => $tipo,
            'compra_manual' => $tipo === Transacao::TIPO_PURCHASE,
            'importada_pdf' => $tipo !== Transacao::TIPO_PURCHASE,
        ]);
    }
}
