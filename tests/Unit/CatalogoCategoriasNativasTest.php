<?php

namespace Tests\Unit;

use App\Services\Categoria\CatalogoCategoriasNativas;
use PHPUnit\Framework\TestCase;

class CatalogoCategoriasNativasTest extends TestCase
{
    public function test_catalogo_tem_vinte_categorias_e_cores_validas(): void
    {
        $categorias = CatalogoCategoriasNativas::categorias();

        $this->assertCount(20, $categorias);

        $nomes = [];
        foreach ($categorias as $categoria) {
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $categoria['cor']);
            $this->assertNotSame('', trim($categoria['nome']));
            $this->assertNotEmpty($categoria['subcategorias']);
            $nomes[] = mb_strtolower($categoria['nome'], 'UTF-8');

            foreach ($categoria['subcategorias'] as $sub) {
                $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $sub['cor']);
                $this->assertNotSame('', trim($sub['nome']));
            }
        }

        $this->assertCount(20, array_unique($nomes));
    }

    public function test_nomes_repetidos_de_subcategoria_sao_compartilhados(): void
    {
        $contagem = [];

        foreach (CatalogoCategoriasNativas::categorias() as $categoria) {
            foreach ($categoria['subcategorias'] as $sub) {
                $chave = mb_strtolower($sub['nome'], 'UTF-8');
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            }
        }

        $this->assertGreaterThan(1, $contagem['outros']);
        $this->assertSame(2, $contagem['software']);
        $this->assertSame(2, $contagem['celular']);
        $this->assertSame(2, $contagem['acessórios']);
    }
}
