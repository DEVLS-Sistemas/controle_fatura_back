<?php

namespace Tests\Unit;

use App\Models\Cartao;
use App\Models\Pessoa;
use PHPUnit\Framework\TestCase;

class CartaoPessoaMetaTest extends TestCase
{
    public function test_pessoa_meta_sem_titular(): void
    {
        $cartao = new Cartao();
        $cartao->pessoa_id = null;

        $this->assertSame([
            'pessoa_id' => null,
            'pessoa_nome' => null,
            'pessoa_eh_principal' => false,
        ], $cartao->pessoaMeta());
    }

    public function test_pessoa_meta_com_titular(): void
    {
        $pessoa = new Pessoa();
        $pessoa->id = 2;
        $pessoa->nome = 'Maysa';
        $pessoa->sobrenome = 'Araujo da Conceicao';
        $pessoa->eh_principal = false;

        $cartao = new Cartao();
        $cartao->pessoa_id = 2;
        $cartao->setRelation('pessoa', $pessoa);

        $this->assertSame([
            'pessoa_id' => 2,
            'pessoa_nome' => 'Maysa Araujo da Conceicao',
            'pessoa_eh_principal' => false,
        ], $cartao->pessoaMeta());
    }
}
