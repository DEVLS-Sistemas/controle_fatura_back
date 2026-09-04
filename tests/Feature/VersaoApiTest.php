<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersaoApiTest extends TestCase
{
    public function test_raiz_da_api_devolve_versao_do_arquivo_de_controle(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertExactJson([
                'api_name' => 'controle-fatura-back',
                'api_version' => '1.0.0',
                'version_short' => '1.0',
            ]);
    }
}
