<?php

namespace Tests\Unit;

use App\Models\Plataforma;
use PHPUnit\Framework\TestCase;

class PlataformaPadroesTest extends TestCase
{
    public function test_padroes_incluem_plataformas_pedidas(): void
    {
        $nomes = array_column(Plataforma::PADROES, 'nome');

        $this->assertContains('Loja Física', $nomes);
        $this->assertContains('Mercado Livre', $nomes);
        $this->assertContains('Shopee', $nomes);
        $this->assertContains('Amazon', $nomes);
        $this->assertContains('AliExpress', $nomes);
        $this->assertContains('iFood', $nomes);
        $this->assertGreaterThanOrEqual(6, count($nomes));

        foreach (Plataforma::PADROES as $padrao) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $padrao['cor']);
        }
    }
}
