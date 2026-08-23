<?php

namespace Tests\Unit;

use App\Services\Pessoa\NomeMatch;
use PHPUnit\Framework\TestCase;

class NomeMatchTest extends TestCase
{
    public function test_iguais_ignorando_acento_e_case(): void
    {
        $this->assertTrue(NomeMatch::matches(
            'Leonardo da Silva Ferreira',
            'LEONARDO DA SILVA FERREIRA'
        ));
    }

    public function test_abreviado_bate_com_completo(): void
    {
        $this->assertTrue(NomeMatch::matches(
            'LEONARDO S FERREIRA',
            'Leonardo da Silva Ferreira'
        ));
    }

    public function test_outra_pessoa_nao_bate(): void
    {
        $this->assertFalse(NomeMatch::matches(
            'MAYSA ARAUJO DA CONCEICAO',
            'Leonardo da Silva Ferreira'
        ));
    }

    public function test_matches_any(): void
    {
        $this->assertTrue(NomeMatch::matchesAny('LEONARDO S FERREIRA', [
            'Maysa Araujo',
            'Leonardo da Silva Ferreira',
        ]));
    }
}
