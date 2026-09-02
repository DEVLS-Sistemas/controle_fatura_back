<?php

namespace Tests\Unit;

use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Transacao;
use App\Services\Pdf\Parsers\AbstractInvoiceParser;
use PHPUnit\Framework\TestCase;

class ProcessInvoicePdfJobTest extends TestCase
{
    public function test_abril_primeira_fatura_soma_compras(): void
    {
        $transactions = [
            ['valor' => 49.90, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 207.70, 'tipo' => Transacao::TIPO_PURCHASE],
        ];

        $this->assertSame(257.60, ProcessInvoicePdfJob::calculateValorTotal($transactions));
    }

    public function test_sem_anterior_processada_pagamentos_nao_zeram_ciclo_atual(): void
    {
        // CSV de maio sem abril processada: "Pagamento recebido" é da fatura anterior
        // desconhecida e não pode abater as compras do ciclo atual.
        $transactions = [
            ['valor' => 400.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 456.84, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 522.00, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 371.01, 'tipo' => Transacao::TIPO_PAYMENT],
        ];

        $this->assertSame(
            856.84,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, null)
        );

        // Com anterior processada no valor dos pagamentos, o total continua as compras.
        $this->assertSame(
            856.84,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 893.01)
        );
    }

    public function test_sem_anterior_pagamento_na_competencia_antecipa_ciclo(): void
    {
        // Fatura 08/2026 sem julho processada: pagamentos de julho quitam a anterior
        // desconhecida; o de 04/08 antecipa agosto (como no PDF: 2.001,33 − 51 = 1.950,33).
        $transactions = [
            ['valor' => 2009.53, 'tipo' => Transacao::TIPO_PURCHASE, 'data' => '2026-07-14'],
            ['valor' => 8.20, 'tipo' => Transacao::TIPO_REFUND, 'data' => '2026-07-28'],
            ['valor' => 217.99, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-07-15'],
            ['valor' => 124.00, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-07-16'],
            ['valor' => 388.00, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-07-17'],
            ['valor' => 750.63, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-07-20'],
            ['valor' => 51.00, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-08-04'],
        ];

        $this->assertSame(
            1950.33,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, null, '2026-08-01')
        );
    }

    public function test_sem_anterior_aloca_antecipacao_pela_data_da_competencia(): void
    {
        $transactions = [
            ['valor' => 1480.62, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-07-20'],
            ['valor' => 51.00, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-08-04'],
        ];

        $this->assertSame(
            ['applied_to_previous' => 1480.62, 'applied_to_current' => 51.0],
            ProcessInvoicePdfJob::allocatePaymentsFromTransactions($transactions, null, '2026-08-01')
        );
    }

    public function test_maio_ignora_pagamento_da_fatura_anterior(): void
    {
        $transactions = [
            ['valor' => 71.73, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 257.60, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 235.52, 'tipo' => Transacao::TIPO_PURCHASE],
        ];

        $this->assertSame(
            307.25,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 257.60)
        );
    }

    public function test_junho_pagamento_antecipado_zera_saldo_do_extrato(): void
    {
        $transactions = [
            ['valor' => 25.74, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 20.99, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 63.01, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 8.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 307.25, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 14.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 100.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 5.29, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 23.98, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 82.92, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 50.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 14.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 14.50, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 8.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 91.34, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 5.23, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 25.74, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 0.09, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 426.63, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 10.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 10.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 17.74, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 41.83, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 19.80, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 16.13, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 6.00, 'tipo' => Transacao::TIPO_PURCHASE],
        ];

        $this->assertSame(
            121.50,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 307.25)
        );
    }

    public function test_julho_variacao_cambial_credito_nao_e_pagamento(): void
    {
        $transactions = [
            ['valor' => 29.99, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 13.89, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 121.50, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 12.70, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 30.01, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 10.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 0.99, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 13.21, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 10.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 56.76, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 34.69, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 11.35, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 23.20, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 38.76, 'tipo' => Transacao::TIPO_PURCHASE],
        ];

        $this->assertSame(
            283.57,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 121.50)
        );
    }

    public function test_agosto_pagamento_parcial_da_fatura_anterior(): void
    {
        // Fatura anterior: 283.57
        // Pagou 170.70 (parcial) + 521.00 (quita residual 112.87 e antecipa 408.13 do ciclo)
        $transactions = [
            ['valor' => 29.99, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 29.98, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 10.50, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 10.00, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 102.87, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 8.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 12.40, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 0.11, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 29.99, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 9.26, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 170.70, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 19.99, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 150.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 17.80, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 31.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 11.82, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 20.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 8.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 9.33, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 39.80, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 8.31, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 44.82, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 64.20, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 33.24, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 521.00, 'tipo' => Transacao::TIPO_PAYMENT],
        ];

        $this->assertSame(
            67.32,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 283.57)
        );
    }

    public function test_carrega_residual_quando_pagamento_parcial_nao_quita_anterior(): void
    {
        $transactions = [
            ['valor' => 100.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 50.00, 'tipo' => Transacao::TIPO_PAYMENT],
        ];

        // Anterior 80; pagou só 50 → residual 30 + compras 100 = 130
        $this->assertSame(
            130.0,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 80.0)
        );
    }

    public function test_maio_com_anterior_correta_chega_no_total_do_cabecalho(): void
    {
        // Compras líquidas 2.255,50; pagamentos 3.637,43; anterior 2.280,95 → 899,02
        $transactions = [
            ['valor' => 692.41, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 57.12, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 128.94, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 93.33, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 55.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 48.49, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 28.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 19.98, 'tipo' => Transacao::TIPO_REFUND],
            ['valor' => 19.45, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 42.29, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 20.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 52.70, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 218.44, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 28.78, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 199.96, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 555.36, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 35.21, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 2260.97, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 55.21, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 374.79, 'tipo' => Transacao::TIPO_PAYMENT],
            ['valor' => 946.46, 'tipo' => Transacao::TIPO_PAYMENT],
        ];

        $this->assertSame(
            899.02,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, 2280.95)
        );
    }

    public function test_allocate_payments_quita_anterior_e_antecipa_excedente(): void
    {
        $this->assertSame(
            ['applied_to_previous' => 257.60, 'applied_to_current' => 0.0],
            ProcessInvoicePdfJob::allocatePayments(257.60, 257.60)
        );

        $this->assertSame(
            ['applied_to_previous' => 307.25, 'applied_to_current' => 426.63],
            ProcessInvoicePdfJob::allocatePayments(733.88, 307.25)
        );

        $this->assertSame(
            ['applied_to_previous' => 50.0, 'applied_to_current' => 0.0],
            ProcessInvoicePdfJob::allocatePayments(50.0, 80.0)
        );
    }

    public function test_build_pagamento_status_usa_pagamentos_da_competencia_seguinte(): void
    {
        $pago = ProcessInvoicePdfJob::buildPagamentoStatus(307.25, 733.88);
        $this->assertTrue($pago['pago']);
        $this->assertSame(307.25, $pago['valor_pago']);
        $this->assertSame(0.0, $pago['valor_restante']);

        $parcial = ProcessInvoicePdfJob::buildPagamentoStatus(80.0, 50.0);
        $this->assertFalse($parcial['pago']);
        $this->assertSame(50.0, $parcial['valor_pago']);
        $this->assertSame(30.0, $parcial['valor_restante']);

        $emAberto = ProcessInvoicePdfJob::buildPagamentoStatus(121.50, 0.0);
        $this->assertFalse($emAberto['pago']);
        $this->assertSame(0.0, $emAberto['valor_pago']);
        $this->assertSame(121.50, $emAberto['valor_restante']);

        $zerada = ProcessInvoicePdfJob::buildPagamentoStatus(0.0, 0.0);
        $this->assertTrue($zerada['pago']);
        $this->assertSame(0.0, $zerada['valor_pago']);
        $this->assertSame(0.0, $zerada['valor_restante']);
    }

    public function test_pagamento_da_fatura_seguinte_quita_total_sem_residual_de_stub(): void
    {
        // Cenário real: 06/2026 processada com compras 1530.26 (sem residual de 05 pendente).
        // 07/2026 tem PAGAMENTO DE FATURA 1530.27 → 06 deve ficar paga.
        $comTotalCorreto = ProcessInvoicePdfJob::buildPagamentoStatus(1530.26, 1530.27);
        $this->assertTrue($comTotalCorreto['pago']);
        $this->assertSame(1530.26, $comTotalCorreto['valor_pago']);
        $this->assertSame(0.0, $comTotalCorreto['valor_restante']);

        // Regressão: se 06 absorveu residual de stub pendente (1190), o pagamento
        // de 07 não quita e a listagem mostra "Em aberto" indevidamente.
        $comResidualInflado = ProcessInvoicePdfJob::buildPagamentoStatus(2720.26, 1530.27);
        $this->assertFalse($comResidualInflado['pago']);
        $this->assertSame(1530.27, $comResidualInflado['valor_pago']);
        $this->assertSame(1189.99, $comResidualInflado['valor_restante']);
    }

    public function test_next_e_previous_competencia(): void
    {
        $this->assertSame([1, 2027], ProcessInvoicePdfJob::nextCompetencia(12, 2026));
        $this->assertSame([8, 2026], ProcessInvoicePdfJob::nextCompetencia(7, 2026));
        $this->assertSame([12, 2025], ProcessInvoicePdfJob::previousCompetencia(1, 2026));
        $this->assertSame([6, 2026], ProcessInvoicePdfJob::previousCompetencia(7, 2026));
    }

    public function test_detect_type_nao_trata_credito_generico_como_pagamento(): void
    {
        $parser = new class extends AbstractInvoiceParser {
            public function name(): string
            {
                return 'test';
            }

            public function supports(string $text): bool
            {
                return true;
            }

            public function parse(string $text): array
            {
                return [];
            }

            public function type(string $establishment, float $amount): string
            {
                return $this->detectType($establishment, $amount);
            }
        };

        $this->assertSame('payment', $parser->type('Pagamento recebido', -121.50));
        $this->assertSame('refund', $parser->type('Variação cambial', -0.99));
        $this->assertSame('purchase', $parser->type('Variação cambial', 5.23));
        $this->assertSame('refund', $parser->type('Desconto Antecipação Mercpago', -0.09));
        $this->assertSame('refund', $parser->type('Estorno de "Mercpago"', -63.01));
        $this->assertSame('purchase', $parser->type('Uber do Brasil Tecnolo', 13.21));
        $this->assertSame('fee', $parser->type('Juros do rotativo', 30.20));
        $this->assertSame('fee', $parser->type('Multa por atraso', 24.00));
        $this->assertSame('fee', $parser->type('IOF CREDITO PARCELADO', 16.56));
        $this->assertSame('fee', $parser->type('IOF de financiamento', 5.05));
        $this->assertSame('carryover', $parser->type('Saldo restante da fatura anterior', 0.0));
        $this->assertSame('purchase', $parser->type('Thaís Araújo da Silva', 52.96));
        $this->assertSame('purchase', $parser->type('Guilherme Oliveira Izidio dos Santos', 9.71));
    }

    public function test_calculate_valor_total_soma_encargos_fee(): void
    {
        $transactions = [
            ['valor' => 100.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 15.10, 'tipo' => Transacao::TIPO_FEE],
            ['valor' => 5.00, 'tipo' => Transacao::TIPO_FEE],
        ];

        $this->assertSame(120.10, ProcessInvoicePdfJob::calculateValorTotal($transactions));
    }

    public function test_carryover_entra_no_total_so_sem_anterior_processada(): void
    {
        $transactions = [
            ['valor' => 100.00, 'tipo' => Transacao::TIPO_PURCHASE],
            ['valor' => 50.00, 'tipo' => Transacao::TIPO_CARRYOVER],
        ];

        $this->assertSame(150.0, ProcessInvoicePdfJob::calculateValorTotal($transactions, null));
        $this->assertSame(180.0, ProcessInvoicePdfJob::calculateValorTotal($transactions, 80.0));
    }

    public function test_valor_extrato_nao_abate_pagamento_do_ciclo(): void
    {
        $transactions = [
            ['valor' => 3565.87, 'tipo' => Transacao::TIPO_PURCHASE, 'data' => '2026-07-20'],
            ['valor' => 119.90, 'tipo' => Transacao::TIPO_PAYMENT, 'data' => '2026-08-04'],
        ];

        $this->assertSame(
            3445.97,
            ProcessInvoicePdfJob::calculateValorTotal($transactions, null, '2026-08-01')
        );
        $this->assertSame(3565.87, ProcessInvoicePdfJob::calculateValorExtrato($transactions));
    }

    public function test_resolve_valor_fatura_prevalece_cabecalho_mesmo_com_linhas_a_mais(): void
    {
        $this->assertSame(1909.46, ProcessInvoicePdfJob::resolveValorFatura(1909.46, 2368.06));
        $this->assertSame(2368.06, ProcessInvoicePdfJob::resolveValorFatura(null, 2368.06));
        $this->assertSame(1909.46, ProcessInvoicePdfJob::resolveValorFatura(1909.46, 1909.46));
        $this->assertSame(0.0, ProcessInvoicePdfJob::resolveValorFatura(0.0, 2004.79));
    }

    public function test_nao_materializa_quando_o_pdf_ja_trouxe_varias_parcelas_da_mesma_compra(): void
    {
        $job = new ProcessInvoicePdfJob(1);
        $method = new \ReflectionMethod(ProcessInvoicePdfJob::class, 'deveMaterializarDoParse');
        $method->setAccessible(true);

        $parsed = [
            ['estabelecimento' => 'GOL LINHAS A*QKSQOO017', 'parcela_atual' => 1, 'parcelas_total' => 5, 'tipo' => Transacao::TIPO_PURCHASE],
            ['estabelecimento' => 'GOL LINHAS A*QKSQOO017', 'parcela_atual' => 2, 'parcelas_total' => 5, 'tipo' => Transacao::TIPO_PURCHASE],
            ['estabelecimento' => 'RI HAPPY', 'parcela_atual' => 1, 'parcelas_total' => 6, 'tipo' => Transacao::TIPO_PURCHASE],
        ];

        $this->assertFalse($method->invoke($job, $parsed[0], $parsed));
        $this->assertTrue($method->invoke($job, $parsed[2], $parsed));
    }
}
