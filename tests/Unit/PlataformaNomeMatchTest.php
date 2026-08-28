<?php

namespace Tests\Unit;

use App\Services\Plataforma\PlataformaNomeMatch;
use PHPUnit\Framework\TestCase;

class PlataformaNomeMatchTest extends TestCase
{
    /**
     * @return list<array{id: int, nome: string}>
     */
    private function padroes(): array
    {
        return [
            ['id' => 1, 'nome' => 'Loja Física'],
            ['id' => 2, 'nome' => 'Mercado Livre'],
            ['id' => 3, 'nome' => 'Shopee'],
            ['id' => 4, 'nome' => 'Amazon'],
            ['id' => 5, 'nome' => 'AliExpress'],
            ['id' => 6, 'nome' => 'iFood'],
            ['id' => 7, 'nome' => 'Magalu'],
            ['id' => 8, 'nome' => 'Shein'],
            ['id' => 9, 'nome' => 'Site da loja'],
            ['id' => 10, 'nome' => 'Outros'],
        ];
    }

    public function test_mercadolivre_asterisco_mercadol(): void
    {
        $this->assertSame(
            2,
            PlataformaNomeMatch::inferirId('Mercadolivre*Mercadol', $this->padroes())
        );
    }

    public function test_shopee_asterisco_loja(): void
    {
        $this->assertSame(
            3,
            PlataformaNomeMatch::inferirId('Shopee *Raceplast', $this->padroes())
        );
    }

    public function test_ifood_e_amazon(): void
    {
        $this->assertSame(6, PlataformaNomeMatch::inferirId('IFOOD *PIZZARIA JOAO', $this->padroes()));
        $this->assertSame(4, PlataformaNomeMatch::inferirId('Amazon Marketplace', $this->padroes()));
        $this->assertSame(5, PlataformaNomeMatch::inferirId('Aliexpress*LojaX', $this->padroes()));
        $this->assertSame(8, PlataformaNomeMatch::inferirId('SHEIN*VESTIDO', $this->padroes()));
        $this->assertSame(7, PlataformaNomeMatch::inferirId('Magazine Luiza*ABC', $this->padroes()));
        $this->assertSame(4, PlataformaNomeMatch::inferirId('AMZN*Marketplace', $this->padroes()));
    }

    public function test_nao_casa_loja_generica_nem_amazonas(): void
    {
        $this->assertNull(PlataformaNomeMatch::inferirId('Padaria do Joao', $this->padroes()));
        $this->assertNull(PlataformaNomeMatch::inferirId('Amazonas Turismo', $this->padroes()));
        $this->assertNull(PlataformaNomeMatch::inferirId('Atacadao 152145', $this->padroes()));
        $this->assertNull(PlataformaNomeMatch::inferirId('Loja Física Centro', $this->padroes()));
    }

    public function test_truncamento_mercadol(): void
    {
        $this->assertSame(
            2,
            PlataformaNomeMatch::inferirId('Mercadol*Loja', $this->padroes())
        );
    }
}
