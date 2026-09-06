<?php

namespace Tests\Unit;

use App\Models\Fatura;
use App\Models\Transacao;
use App\Services\Fatura\FaturaPeriodoUnicidadeService;
use PHPUnit\Framework\TestCase;

class FaturaPeriodoUnicidadeTest extends TestCase
{
    public function test_escolhe_a_fatura_com_anexo(): void
    {
        $stub = $this->fatura([
            'id' => 643,
            'status' => 'pendente',
            'arquivo_pdf' => null,
        ]);
        $comPdf = $this->fatura([
            'id' => 696,
            'status' => 'processada',
            'arquivo_pdf' => 'faturas/5/nubank.pdf',
        ]);

        $escolhida = FaturaPeriodoUnicidadeService::escolherCanonico(collect([$stub, $comPdf]));

        $this->assertNotNull($escolhida);
        $this->assertSame(696, (int) $escolhida->id);
    }

    public function test_escolhe_a_fatura_com_catalogo_mesmo_sem_path_local(): void
    {
        $stub = $this->fatura([
            'id' => 10,
            'status' => 'pendente',
            'arquivo_pdf' => null,
            'anexo_pdf_id' => null,
        ]);
        $comCatalogo = $this->fatura([
            'id' => 11,
            'status' => 'pendente',
            'arquivo_pdf' => null,
            'anexo_pdf_id' => 33,
        ]);

        $escolhida = FaturaPeriodoUnicidadeService::escolherCanonico(collect([$stub, $comCatalogo]));

        $this->assertSame(11, (int) $escolhida->id);
    }

    public function test_sem_anexo_prefere_processada(): void
    {
        $pendente = $this->fatura(['id' => 1, 'status' => 'pendente']);
        $processada = $this->fatura(['id' => 2, 'status' => 'processada']);

        $escolhida = FaturaPeriodoUnicidadeService::escolherCanonico(collect([$pendente, $processada]));

        $this->assertSame(2, (int) $escolhida->id);
    }

    public function test_prefere_ativa_a_apagada(): void
    {
        $apagada = $this->fatura([
            'id' => 10,
            'status' => 'processada',
            'arquivo_pdf' => 'a.pdf',
            'deleted_at' => '2026-08-01 00:00:00',
        ]);
        $ativa = $this->fatura([
            'id' => 11,
            'status' => 'pendente',
            'arquivo_pdf' => null,
        ]);

        $escolhida = FaturaPeriodoUnicidadeService::escolherCanonico(collect([$apagada, $ativa]));

        $this->assertSame(11, (int) $escolhida->id);
    }

    public function test_empate_fica_com_o_id_menor(): void
    {
        $a = $this->fatura(['id' => 80, 'status' => 'pendente']);
        $b = $this->fatura(['id' => 81, 'status' => 'pendente']);

        $escolhida = FaturaPeriodoUnicidadeService::escolherCanonico(collect([$b, $a]));

        $this->assertSame(80, (int) $escolhida->id);
    }

    public function test_chave_transacao_igual_para_mesmo_lancamento(): void
    {
        $a = $this->transacao([
            'tipo' => 'purchase',
            'estabelecimento_id' => 9,
            'data' => '2026-07-20',
            'valor' => 260.12,
            'parcela_atual' => 2,
            'parcelas_total' => 10,
        ]);
        $b = $this->transacao([
            'tipo' => 'purchase',
            'estabelecimento_id' => 9,
            'data' => '2026-07-20',
            'valor' => '260.12',
            'parcela_atual' => 2,
            'parcelas_total' => 10,
        ]);

        $this->assertSame(
            FaturaPeriodoUnicidadeService::chaveTransacao($a),
            FaturaPeriodoUnicidadeService::chaveTransacao($b)
        );
    }

    public function test_chave_transacao_diferente_quando_parcela_muda(): void
    {
        $a = $this->transacao([
            'tipo' => 'purchase',
            'estabelecimento_id' => 9,
            'data' => '2026-07-20',
            'valor' => 100,
            'parcela_atual' => 1,
            'parcelas_total' => 3,
        ]);
        $b = $this->transacao([
            'tipo' => 'purchase',
            'estabelecimento_id' => 9,
            'data' => '2026-07-20',
            'valor' => 100,
            'parcela_atual' => 2,
            'parcelas_total' => 3,
        ]);

        $this->assertNotSame(
            FaturaPeriodoUnicidadeService::chaveTransacao($a),
            FaturaPeriodoUnicidadeService::chaveTransacao($b)
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function fatura(array $attrs): Fatura
    {
        $fatura = new Fatura;
        $fatura->setRawAttributes(array_merge([
            'id' => 0,
            'status' => 'pendente',
            'arquivo_pdf' => null,
            'arquivo_csv' => null,
            'deleted_at' => null,
        ], $attrs), true);

        return $fatura;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function transacao(array $attrs): Transacao
    {
        $tx = new Transacao;
        $tx->setRawAttributes($attrs, true);

        return $tx;
    }
}
