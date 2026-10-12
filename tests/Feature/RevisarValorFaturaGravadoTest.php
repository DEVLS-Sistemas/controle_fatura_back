<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\User;
use App\Services\Fatura\FaturaService;
use App\Services\Pdf\InvoicePdfParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RevisarValorFaturaGravadoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reler_pdf_grava_o_total_oficial_no_lugar_do_rotativo(): void
    {
        [$fatura] = $this->faturaComTotal(7097.16);

        $this->mock(InvoicePdfParserService::class, function ($mock): void {
            $mock->shouldReceive('parseFile')->once()->andReturn([
                'valor_fatura' => 6474.92,
            ]);
        });

        $atual = (new FaturaService)->revisarValorFaturaGravado($fatura, 6474.92);

        $this->assertSame('6474.92', (string) $atual->valor_fatura);
        $this->assertSame('6474.92', (string) $atual->valor_total);
    }

    public function test_nao_reler_quando_o_total_gravado_ja_bate_com_as_linhas(): void
    {
        [$fatura] = $this->faturaComTotal(6474.92);

        $this->mock(InvoicePdfParserService::class, function ($mock): void {
            $mock->shouldReceive('parseFile')->never();
        });

        $atual = (new FaturaService)->revisarValorFaturaGravado($fatura, 6474.92);

        $this->assertSame('6474.92', (string) $atual->valor_fatura);
    }

    public function test_leitura_igual_a_gravada_nao_altera_a_fatura(): void
    {
        [$fatura] = $this->faturaComTotal(1909.46);

        $this->mock(InvoicePdfParserService::class, function ($mock): void {
            $mock->shouldReceive('parseFile')->once()->andReturn([
                'valor_fatura' => 1909.46,
            ]);
        });

        $atual = (new FaturaService)->revisarValorFaturaGravado($fatura, 1800.00);

        $this->assertSame('1909.46', (string) $atual->valor_fatura);
        $this->assertSame('1909.46', (string) $atual->valor_total);
    }

    /**
     * @return array{0: Fatura}
     */
    private function faturaComTotal(float $total): array
    {
        Storage::fake('local');

        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat-73-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'PicPay',
            'banco' => 'PicPay',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
        $relative = 'faturas/'.$user->id.'/fatura.pdf';
        Storage::disk('local')->put($relative, '%PDF-1.4');

        $fatura = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 11,
            'ano' => 2025,
            'valor_total' => $total,
            'valor_fatura' => $total,
            'arquivo_pdf' => $relative,
            'status' => 'processada',
        ]);

        return [$fatura];
    }
}
