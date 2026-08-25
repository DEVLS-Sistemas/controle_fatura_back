<?php

namespace Tests\Unit;

use App\Services\Transacao\ConciliacaoMatcher;
use App\Services\Transacao\ConciliacaoService;
use App\Models\Transacao;
use PHPUnit\Framework\TestCase;

class ConciliacaoMatcherTest extends TestCase
{
    private ConciliacaoMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ConciliacaoMatcher();
    }

    public function test_score_alto_para_mesmo_valor_fatura_data_e_parcela(): void
    {
        $score = $this->matcher->score(
            [
                'valor' => 249.90,
                'data' => '2026-08-23',
                'fatura_id' => 10,
                'cartao_numero_id' => 7,
                'parcela_atual' => 1,
            ],
            [
                'valor' => 249.90,
                'data' => '2026-08-23',
                'fatura_id' => 10,
                'cartao_numero_id' => 7,
                'parcela_atual' => 1,
            ]
        );

        $this->assertSame(100, $score);
        $this->assertTrue($this->matcher->isSugestao($score));
    }

    public function test_score_zero_quando_valor_diverge(): void
    {
        $score = $this->matcher->score(
            [
                'valor' => 249.90,
                'fatura_id' => 10,
                'data' => '2026-08-23',
            ],
            [
                'valor' => 100.00,
                'fatura_id' => 10,
                'data' => '2026-08-23',
            ]
        );

        $this->assertSame(0, $score);
        $this->assertFalse($this->matcher->isSugestao($score));
    }

    public function test_proximidade_de_data_soma_pontos_parciais(): void
    {
        $score = $this->matcher->score(
            [
                'valor' => 200.00,
                'data' => '2026-08-20',
                'fatura_id' => 10,
                'parcela_atual' => 1,
            ],
            [
                'valor' => 200.00,
                'data' => '2026-08-23',
                'fatura_id' => 10,
                'parcela_atual' => 1,
            ]
        );

        $this->assertSame(78, $score);
        $this->assertTrue($this->matcher->isSugestao($score));
    }

    public function test_mensagens_de_status(): void
    {
        $service = new ConciliacaoService();

        $this->assertStringContainsString(
            'ainda não foi localizado',
            (string) $service->mensagem(Transacao::CONCILIACAO_NAO_CONCILIADA)
        );
        $this->assertStringContainsString(
            'pode corresponder',
            (string) $service->mensagem(Transacao::CONCILIACAO_PENDENTE)
        );
        $this->assertStringContainsString(
            'descrição amigável',
            (string) $service->mensagem(Transacao::CONCILIACAO_CONCILIADA)
        );
        $this->assertStringContainsString(
            'rejeitada',
            (string) $service->mensagem(Transacao::CONCILIACAO_REJEITADA)
        );
    }
}
