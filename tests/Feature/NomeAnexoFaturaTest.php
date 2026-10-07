<?php

namespace Tests\Feature;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NomeAnexoFaturaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listagem_e_detalhe_devolvem_o_nome_original_do_upload(): void
    {
        $ctx = $this->cenario();
        $fatura = $ctx['fatura'];
        $pdf = $this->anexo($ctx['user'], $fatura, 'Fatura Nubank setembro.pdf', 'pdf');
        $csv = $this->anexo($ctx['user'], $fatura, 'nubank-09-2026.csv', 'csv');
        $pathPdf = 'faturas/'.$ctx['user']->id.'/blob-interno.pdf';
        $pathCsv = 'faturas/'.$ctx['user']->id.'/blob-interno.csv';
        Storage::disk('local')->put($pathPdf, 'pdf');
        Storage::disk('local')->put($pathCsv, 'csv');
        $fatura->arquivo_pdf = $pathPdf;
        $fatura->arquivo_csv = $pathCsv;
        $fatura->anexo_pdf_id = $pdf->id;
        $fatura->anexo_csv_id = $csv->id;
        $fatura->save();

        $lista = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar?mes=9&ano=2026&perPage=20');

        $lista->assertOk();
        $item = $this->faturaNaLista($lista->json(), $fatura->id);
        $this->assertSame('Fatura Nubank setembro.pdf', $item['anexo_pdf_nome']);
        $this->assertSame('nubank-09-2026.csv', $item['anexo_csv_nome']);
        $this->assertNotSame($item['arquivo_pdf'], $item['anexo_pdf_nome']);
        $this->assertNotSame($item['arquivo_csv'], $item['anexo_csv_nome']);

        $detalhe = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$fatura->id);

        $detalhe->assertOk()
            ->assertJsonPath('anexo_pdf_nome', 'Fatura Nubank setembro.pdf')
            ->assertJsonPath('anexo_csv_nome', 'nubank-09-2026.csv');
        $this->assertNotSame($detalhe->json('arquivo_pdf'), $detalhe->json('anexo_pdf_nome'));
        Storage::disk('local')->delete([$pathPdf, $pathCsv]);
    }

    public function test_fatura_antiga_sem_catalogo_nao_usa_o_path_como_nome(): void
    {
        $ctx = $this->cenario();
        $path = 'faturas/'.$ctx['user']->id.'/ctlfat31-legado.pdf';
        Storage::disk('local')->put($path, 'legado');

        try {
            $ctx['fatura']->arquivo_pdf = $path;
            $ctx['fatura']->anexo_pdf_id = null;
            $ctx['fatura']->anexo_csv_id = null;
            $ctx['fatura']->save();

            $detalhe = $this->actingAs($ctx['user'], 'sanctum')
                ->getJson('/api/v1/faturas/listar/'.$ctx['fatura']->id);

            $detalhe->assertOk()
                ->assertJsonPath('tem_pdf', true)
                ->assertJsonPath('anexo_pdf_nome', null)
                ->assertJsonPath('anexo_csv_nome', null);
            $this->assertNotSame(basename($path), $detalhe->json('anexo_pdf_nome'));
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function test_sem_anexo_os_dois_nomes_vem_null(): void
    {
        $ctx = $this->cenario();

        $detalhe = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$ctx['fatura']->id);

        $detalhe->assertOk()
            ->assertJsonPath('tem_pdf', false)
            ->assertJsonPath('tem_csv', false)
            ->assertJsonPath('anexo_pdf_nome', null)
            ->assertJsonPath('anexo_csv_nome', null);

        $lista = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar?mes=9&ano=2026&perPage=20');
        $lista->assertOk();
        $item = $this->faturaNaLista($lista->json(), $ctx['fatura']->id);
        $this->assertNull($item['anexo_pdf_nome']);
        $this->assertNull($item['anexo_csv_nome']);
    }

    public function test_troca_devolve_o_nome_novo_e_remocao_zera(): void
    {
        $ctx = $this->cenario();
        $path = 'faturas/'.$ctx['user']->id.'/errado.pdf';
        Storage::disk('local')->put($path, 'pdf');
        $antigo = $this->anexo($ctx['user'], $ctx['fatura'], 'errado.pdf', 'pdf');
        $ctx['fatura']->anexo_pdf_id = $antigo->id;
        $ctx['fatura']->arquivo_pdf = $path;
        $ctx['fatura']->save();

        $novo = $this->anexo($ctx['user'], $ctx['fatura'], 'Nubank_09_2026.pdf', 'pdf');
        $ctx['fatura']->anexo_pdf_id = $novo->id;
        $ctx['fatura']->save();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$ctx['fatura']->id)
            ->assertOk()
            ->assertJsonPath('anexo_pdf_nome', 'Nubank_09_2026.pdf');

        $ctx['fatura']->anexo_pdf_id = null;
        $ctx['fatura']->arquivo_pdf = null;
        $ctx['fatura']->save();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/faturas/listar/'.$ctx['fatura']->id)
            ->assertOk()
            ->assertJsonPath('anexo_pdf_nome', null)
            ->assertJsonPath('tem_pdf', false);
        Storage::disk('local')->delete($path);
    }

    /**
     * @return array{user: User, fatura: Fatura}
     */
    private function cenario(): array
    {
        $user = User::create([
            'name' => 'Leo',
            'email' => 'ctlfat31-'.uniqid('', true).'@test.local',
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

        return ['user' => $user, 'fatura' => $fatura];
    }

    private function anexo(User $user, Fatura $fatura, string $nome, string $extensao): Anexo
    {
        return Anexo::create([
            'user_id' => $user->id,
            'origem' => AnexoOrigem::Fatura,
            'referencia_id' => $fatura->id,
            'nome_original' => $nome,
            'mime' => $extensao === 'pdf' ? 'application/pdf' : 'text/csv',
            'extensao' => $extensao,
            'hash' => hash('sha256', $nome.uniqid('', true)),
            'blob_path' => 'faturas/'.$user->id.'/'.hash('sha256', $nome).'.'.$extensao,
            'disk' => 'azure',
            'status' => AnexoStatus::Enviado,
        ]);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function faturaNaLista(array $json, int $id): array
    {
        foreach ($json['data'] ?? [] as $grupo) {
            foreach ($grupo['faturas'] ?? [] as $fatura) {
                if ((int) $fatura['id'] === $id) {
                    return $fatura;
                }
            }
        }

        $this->fail('Fatura '.$id.' não veio na listagem');
    }
}
