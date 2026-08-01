<?php

namespace Tests\Unit;

use App\Services\Estabelecimento\EstabelecimentoService;
use App\Services\Pdf\Parsers\AbstractInvoiceParser;
use PHPUnit\Framework\TestCase;

class EstabelecimentoNormalizeNomeTest extends TestCase
{
    public function test_normalize_remove_parcela_barra(): void
    {
        $this->assertSame(
            'Atacado dos Presentes',
            EstabelecimentoService::normalizeNome('Atacado dos Presentes 1/3')
        );
        $this->assertSame(
            'Atacado dos Presentes',
            EstabelecimentoService::normalizeNome('Atacado dos Presentes 2/3')
        );
    }

    public function test_normalize_remove_parcela_parc(): void
    {
        $this->assertSame(
            'Loja XYZ',
            EstabelecimentoService::normalizeNome('Loja XYZ PARC 02/10')
        );
    }

    public function test_normalize_preserva_nome_sem_parcela(): void
    {
        $this->assertSame(
            'Assai Atacadista',
            EstabelecimentoService::normalizeNome('Assai Atacadista')
        );
    }

    public function test_make_transaction_separa_parcela_do_nome(): void
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

            public function build(string $establishment, float $amount): array
            {
                return $this->makeTransaction('2026-07-15', $establishment, $amount);
            }
        };

        $tx = $parser->build('Atacado dos Presentes 1/3', 50.0);

        $this->assertSame('Atacado dos Presentes', $tx['estabelecimento']);
        $this->assertSame(1, $tx['parcela_atual']);
        $this->assertSame(3, $tx['parcelas_total']);
        $this->assertSame(50.0, $tx['valor']);
    }
}
