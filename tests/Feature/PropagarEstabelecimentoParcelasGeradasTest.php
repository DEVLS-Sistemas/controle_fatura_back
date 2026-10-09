<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Models\User;
use App\Services\Estabelecimento\EstabelecimentoService;
use App\Services\Transacao\TransacaoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PropagarEstabelecimentoParcelasGeradasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reprocesso_atualiza_o_nome_nas_parcelas_geradas_sem_pdf(): void
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat46-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Itaú',
            'banco' => 'Itaú',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 12,
        ]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);
        $sujo = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'MOTO CRUZ VEÍCULOS .RECIFE Valor LEONARDO DA SILVA F (final 2944) Valor',
            'ativo' => true,
        ]);
        $limpo = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'MOTO CRUZ',
            'ativo' => true,
        ]);

        $origem = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 11,
            'ano' => 2022,
            'valor_total' => 0,
            'status' => 'processada',
            'arquivo_pdf' => 'faturas/origem.pdf',
        ]);
        $anterior = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 10,
            'ano' => 2022,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);

        $grupo = 'grupo-moto-cruz';
        $ancora = Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $origem->id,
            'fatura_origem_id' => $origem->id,
            'estabelecimento_id' => $limpo->id,
            'data' => '2022-06-01',
            'valor' => 95.85,
            'valor_parcela' => 95.85,
            'parcelas_total' => 6,
            'parcela_atual' => 6,
            'tipo' => Transacao::TIPO_PURCHASE,
            'responsavel_id' => $responsavel->id,
            'compra_grupo_id' => $grupo,
            'importada_pdf' => true,
        ]);
        $gerada = Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $anterior->id,
            'fatura_origem_id' => $origem->id,
            'estabelecimento_id' => $sujo->id,
            'data' => '2022-06-01',
            'valor' => 95.85,
            'valor_parcela' => 95.85,
            'parcelas_total' => 6,
            'parcela_atual' => 5,
            'tipo' => Transacao::TIPO_PURCHASE,
            'responsavel_id' => $responsavel->id,
            'compra_grupo_id' => $grupo,
            'importada_pdf' => false,
        ]);

        $service = new TransacaoService(new EstabelecimentoService());
        $service->propagarEstabelecimentoNasParcelasGeradas($ancora);

        $gerada->refresh();
        $this->assertSame($limpo->id, $gerada->estabelecimento_id);
    }
}
