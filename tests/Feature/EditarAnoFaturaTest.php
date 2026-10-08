<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EditarAnoFaturaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_editar_ano_nao_grava_valor_total_nulo(): void
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat-ano-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'C6',
            'banco' => 'C6',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
        $fatura = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 1,
            'ano' => 2024,
            'valor_total' => 646.80,
            'valor_fatura' => 646.80,
            'status' => 'processada',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/faturas/editar', [
            'id' => $fatura->id,
            'fatura_id' => $fatura->id,
            'cartao_id' => $cartao->id,
            'mes' => 1,
            'ano' => 2025,
            'valor_total' => null,
            'valor_fatura' => null,
            'processar_automatico' => true,
        ]);

        $response->assertOk();
        $fatura->refresh();
        $this->assertSame(2025, (int) $fatura->ano);
        $this->assertSame('646.80', (string) $fatura->valor_total);
        $this->assertSame('646.80', (string) $fatura->valor_fatura);
    }
}
