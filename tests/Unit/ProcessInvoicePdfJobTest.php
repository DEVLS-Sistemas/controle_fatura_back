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
    }
}
