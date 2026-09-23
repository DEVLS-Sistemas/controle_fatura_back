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

class AplicarSubcategoriaEstabelecimentoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sem_flag_so_a_linha_editada_muda_e_a_resposta_pergunta(): void
    {
        $ctx = $this->cenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['editada']->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => $ctx['sub']->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('transacao.aplicar_subcategoria.perguntar', true)
            ->assertJsonPath('transacao.aplicar_subcategoria.linhas_nesta_fatura', 1)
            ->assertJsonPath('transacao.aplicar_subcategoria.parcelas_outras_faturas', 1)
            ->assertJsonPath('transacao.aplicar_subcategoria.estabelecimento_nome', 'Shopee')
            ->assertJsonPath('transacao.aplicar_subcategoria.somente_categoria', false);

        $ctx['editada']->refresh();
        $ctx['outra']->refresh();
        $ctx['outraSub']->refresh();
        $ctx['parcela']->refresh();
        $ctx['estabelecimento']->refresh();

        $this->assertSame($ctx['sub']->id, $ctx['editada']->subcategoria_id);
        $this->assertNull($ctx['outra']->subcategoria_id);
        $this->assertSame($ctx['subOutra']->id, $ctx['outraSub']->subcategoria_id);
        $this->assertNull($ctx['parcela']->subcategoria_id);
        $this->assertNull($ctx['estabelecimento']->categoria_padrao_id);
        $this->assertNull($ctx['estabelecimento']->subcategoria_padrao_id);
    }

    public function test_com_flag_aplica_na_fatura_nas_parcelas_e_grava_o_padrao(): void
    {
        $ctx = $this->cenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['editada']->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => $ctx['sub']->id,
            'aplicar_subcategoria_estabelecimento' => true,
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('transacao.aplicar_subcategoria');

        $ctx['editada']->refresh();
        $ctx['outra']->refresh();
        $ctx['outraSub']->refresh();
        $ctx['parcela']->refresh();
        $ctx['parcelaOutraSub']->refresh();
        $ctx['estabelecimento']->refresh();

        $this->assertSame($ctx['categoria']->id, $ctx['outra']->categoria_id);
        $this->assertSame($ctx['sub']->id, $ctx['outra']->subcategoria_id);
        $this->assertSame($ctx['categoria']->id, $ctx['parcela']->categoria_id);
        $this->assertSame($ctx['sub']->id, $ctx['parcela']->subcategoria_id);
        $this->assertSame($ctx['subOutra']->id, $ctx['outraSub']->subcategoria_id);
        $this->assertSame($ctx['subOutra']->id, $ctx['parcelaOutraSub']->subcategoria_id);
        $this->assertSame($ctx['categoria']->id, $ctx['estabelecimento']->categoria_padrao_id);
        $this->assertSame($ctx['sub']->id, $ctx['estabelecimento']->subcategoria_padrao_id);
    }

    public function test_so_categoria_sem_flag_pergunta_e_nao_propaga(): void
    {
        $ctx = $this->cenario();
        $jaTemCategoria = $this->linhaSoComCategoria($ctx);

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['editada']->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('transacao.aplicar_subcategoria.perguntar', true)
            ->assertJsonPath('transacao.aplicar_subcategoria.somente_categoria', true)
            ->assertJsonPath('transacao.aplicar_subcategoria.linhas_nesta_fatura', 1)
            ->assertJsonPath('transacao.aplicar_subcategoria.parcelas_outras_faturas', 1);

        $ctx['editada']->refresh();
        $ctx['outra']->refresh();
        $ctx['parcela']->refresh();
        $jaTemCategoria->refresh();
        $ctx['estabelecimento']->refresh();

        $this->assertSame($ctx['categoria']->id, $ctx['editada']->categoria_id);
        $this->assertNull($ctx['editada']->subcategoria_id);
        $this->assertNull($ctx['outra']->categoria_id);
        $this->assertNull($ctx['parcela']->categoria_id);
        $this->assertSame($ctx['categoriaOutra']->id, $jaTemCategoria->categoria_id);
        $this->assertNull($ctx['estabelecimento']->categoria_padrao_id);
    }

    public function test_so_categoria_com_flag_aplica_sem_sobrescrever_quem_ja_tem_categoria(): void
    {
        $ctx = $this->cenario();
        $jaTemCategoria = $this->linhaSoComCategoria($ctx);

        $response = $this->actingAs($ctx['user'], 'sanctum')->putJson('/api/v1/transacoes/editar', [
            'id' => $ctx['editada']->id,
            'categoria_id' => $ctx['categoria']->id,
            'subcategoria_id' => null,
            'aplicar_subcategoria_estabelecimento' => true,
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('transacao.aplicar_subcategoria');

        $ctx['outra']->refresh();
        $ctx['parcela']->refresh();
        $ctx['outraSub']->refresh();
        $jaTemCategoria->refresh();
        $ctx['estabelecimento']->refresh();

        $this->assertSame($ctx['categoria']->id, $ctx['outra']->categoria_id);
        $this->assertNull($ctx['outra']->subcategoria_id);
        $this->assertSame($ctx['categoria']->id, $ctx['parcela']->categoria_id);
        $this->assertNull($ctx['parcela']->subcategoria_id);
        $this->assertSame($ctx['categoriaOutra']->id, $jaTemCategoria->categoria_id);
        $this->assertNull($jaTemCategoria->subcategoria_id);
        $this->assertSame($ctx['subOutra']->id, $ctx['outraSub']->subcategoria_id);
        $this->assertSame($ctx['categoria']->id, $ctx['estabelecimento']->categoria_padrao_id);
        $this->assertNull($ctx['estabelecimento']->subcategoria_padrao_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat25-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Sofisa',
            'banco' => 'Sofisa',
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
        $faturaSeguinte = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 10,
            'ano' => 2026,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
        $estabelecimento = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'Shopee',
            'ativo' => true,
        ]);
        $categoria = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Compras',
            'ativo' => true,
        ]);
        $categoriaOutra = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Mercado',
            'ativo' => true,
        ]);
        $sub = Subcategoria::create([
            'user_id' => $user->id,
            'nome' => 'Marketplace',
            'ativo' => true,
        ]);
        $subOutra = Subcategoria::create([
            'user_id' => $user->id,
            'nome' => 'Outra',
            'ativo' => true,
        ]);
        $categoria->subcategorias()->attach([$sub->id, $subOutra->id]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);

        $grupo = '11111111-1111-1111-1111-111111111111';
        $editada = $this->transacao($user->id, $fatura->id, $estabelecimento->id, $responsavel->id, $grupo, 1);
        $outra = $this->transacao($user->id, $fatura->id, $estabelecimento->id, $responsavel->id, null, null);
        $outraSub = $this->transacao($user->id, $fatura->id, $estabelecimento->id, $responsavel->id, null, null);
        $outraSub->categoria_id = $categoria->id;
        $outraSub->subcategoria_id = $subOutra->id;
        $outraSub->save();
        $parcela = $this->transacao($user->id, $faturaSeguinte->id, $estabelecimento->id, $responsavel->id, $grupo, 2);
        $parcelaOutraSub = $this->transacao($user->id, $faturaSeguinte->id, $estabelecimento->id, $responsavel->id, $grupo, 3);
        $parcelaOutraSub->categoria_id = $categoria->id;
        $parcelaOutraSub->subcategoria_id = $subOutra->id;
        $parcelaOutraSub->save();

        return compact(
            'user',
            'fatura',
            'responsavel',
            'categoria',
            'categoriaOutra',
            'sub',
            'subOutra',
            'estabelecimento',
            'editada',
            'outra',
            'outraSub',
            'parcela',
            'parcelaOutraSub'
        );
    }

    private function linhaSoComCategoria(array $ctx): Transacao
    {
        $linha = $this->transacao(
            $ctx['user']->id,
            $ctx['fatura']->id,
            $ctx['estabelecimento']->id,
            $ctx['responsavel']->id,
            null,
            null
        );
        $linha->categoria_id = $ctx['categoriaOutra']->id;
        $linha->save();

        return $linha;
    }

    private function transacao(
        int $userId,
        int $faturaId,
        int $estabelecimentoId,
        int $responsavelId,
        ?string $grupo,
        ?int $parcela
    ): Transacao {
        return Transacao::create([
            'user_id' => $userId,
            'fatura_id' => $faturaId,
            'estabelecimento_id' => $estabelecimentoId,
            'responsavel_id' => $responsavelId,
            'data' => '2026-09-10',
            'valor' => 10,
            'tipo' => 'purchase',
            'compra_grupo_id' => $grupo,
            'parcelas_total' => $grupo !== null ? 3 : null,
            'parcela_atual' => $parcela,
        ]);
    }
}
