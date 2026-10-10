<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FaturaCarregamentoProgressivoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listagem_devolve_a_pagina_antes_de_unificar_duplicatas(): void
    {
        $ctx = $this->cenario();
        $comAnexo = Fatura::create([
            'user_id' => $ctx['user']->id,
            'cartao_id' => $ctx['cartao']->id,
            'cartao_bandeira_id' => $ctx['bandeira']->id,
            'mes' => 8,
            'ano' => 2026,
            'valor_total' => 100,
            'arquivo_pdf' => 'faturas/'.$ctx['user']->id.'/nubank.pdf',
            'status' => 'processada',
        ]);
        $stub = Fatura::create([
            'user_id' => $ctx['user']->id,
            'cartao_id' => $ctx['cartao']->id,
            'mes' => 8,
            'ano' => 2026,
            'valor_total' => 10,
            'status' => 'pendente',
        ]);

        $lista = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar?mes=8&ano=2026&perPage=20');

        $lista->assertOk();
        $ids = $this->idsNaLista($lista->json());
        $this->assertContains($comAnexo->id, $ids);
        $this->assertContains($stub->id, $ids);
        $this->assertNull(Fatura::withTrashed()->find($stub->id));
        $this->assertNotNull(Fatura::query()->find($comAnexo->id));
    }

    public function test_detalhe_devolve_cabecalho_e_os_blocos_em_rotas_separadas(): void
    {
        $ctx = $this->cenario();
        $fatura = Fatura::create([
            'user_id' => $ctx['user']->id,
            'cartao_id' => $ctx['cartao']->id,
            'cartao_bandeira_id' => $ctx['bandeira']->id,
            'mes' => 9,
            'ano' => 2026,
            'valor_total' => 80,
            'valor_fatura' => 80,
            'status' => 'processada',
        ]);
        Transacao::create([
            'user_id' => $ctx['user']->id,
            'fatura_id' => $fatura->id,
            'responsavel_id' => $ctx['responsavel']->id,
            'data' => '2026-09-10',
            'valor' => 80,
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => false,
            'importada_pdf' => true,
            'status_conciliacao' => Transacao::CONCILIACAO_CONCILIADA,
        ]);

        $detalhe = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id);

        $detalhe->assertOk()
            ->assertJsonPath('cartao_nome', 'Nubank')
            ->assertJsonPath('competencia', '09/2026')
            ->assertJsonPath('status', 'processada')
            ->assertJsonMissingPath('grupos_por_cartao')
            ->assertJsonMissingPath('pago')
            ->assertJsonMissingPath('pagamentos_total')
            ->assertJsonMissingPath('conferencia')
            ->assertJsonMissingPath('valor_extrato');
        $this->assertEquals(80, (float) $detalhe->json('valor_total'));

        $grupos = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id.'/grupos');
        $grupos->assertOk();
        $this->assertCount(1, $grupos->json('grupos_por_cartao'));
        $this->assertSame(1, $grupos->json('grupos_por_cartao.0.total_transacoes'));

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id.'/quitacao')
            ->assertOk()
            ->assertJsonPath('pago', false)
            ->assertJsonPath('valor_pago', 0)
            ->assertJsonPath('pagamentos_total', 0)
            ->assertJsonPath('pagamentos_abatido_anterior', 0)
            ->assertJsonPath('pagamentos_antecipado', 0);
        $this->assertEquals(80, $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id.'/quitacao')
            ->json('valor_restante'));

        $conferencia = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id.'/conferencia');
        $conferencia->assertOk()
            ->assertJsonPath('conferencia.bate', true)
            ->assertJsonPath('tem_compras_nao_conciliadas', false);
        $this->assertEquals(80, $conferencia->json('conferencia.valor_cabecalho'));
        $this->assertEquals(80, $conferencia->json('valor_extrato'));
    }

    public function test_blocos_do_detalhe_recusam_fatura_de_outro_usuario(): void
    {
        $ctx = $this->cenario();
        $outro = User::create([
            'name' => 'Outro',
            'email' => 'ctlfat52-outro-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $fatura = Fatura::create([
            'user_id' => $ctx['user']->id,
            'cartao_id' => $ctx['cartao']->id,
            'mes' => 9,
            'ano' => 2026,
            'valor_total' => 10,
            'status' => 'pendente',
        ]);

        foreach (['grupos', 'quitacao', 'conferencia'] as $bloco) {
            $this->actingAs($outro, 'sanctum')
                ->getJson('/api/v1/faturas/listar/'.$fatura->id.'/'.$bloco)
                ->assertNotFound();
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<int>
     */
    private function idsNaLista(array $json): array
    {
        $ids = [];
        foreach ($json['data'] ?? [] as $grupo) {
            foreach ($grupo['faturas'] ?? [] as $fatura) {
                $ids[] = (int) $fatura['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array{user: User, cartao: Cartao, bandeira: CartaoBandeira, responsavel: Responsavel}
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat52-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'banco' => 'Nubank',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 12,
        ]);
        $bandeira = CartaoBandeira::create([
            'cartao_id' => $cartao->id,
            'bandeira' => 'Mastercard',
            'ativo' => true,
        ]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);

        return compact('user', 'cartao', 'bandeira', 'responsavel');
    }
}
