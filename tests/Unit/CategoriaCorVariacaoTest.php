<?php

namespace Tests\Unit;

use App\Services\Categoria\CategoriaCoresTema;
use App\Services\Categoria\CategoriaCorVariacao;
use PHPUnit\Framework\TestCase;

class CategoriaCorVariacaoTest extends TestCase
{
    public function test_azul_com_tres_subs_gera_hex_distintos_mais_claros(): void
    {
        $tema = '#3b82f6';
        $cores = CategoriaCorVariacao::variacoes($tema, 3);
        $lTema = CategoriaCorVariacao::luminanciaRelativa($tema);

        $this->assertCount(3, $cores);
        $this->assertCount(3, array_unique($cores));
        foreach ($cores as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
            $this->assertNotSame($tema, $hex);
            $this->assertNotSame('#ffffff', $hex);
            $this->assertGreaterThan($lTema, CategoriaCorVariacao::luminanciaRelativa($hex));
        }
    }

    public function test_mudar_para_vermelho_troca_a_familia_e_continua_mais_claro(): void
    {
        $vermelho = '#ef4444';
        $azuis = CategoriaCorVariacao::variacoes('#3b82f6', 3);
        $vermelhos = CategoriaCorVariacao::variacoes($vermelho, 3);
        $lTema = CategoriaCorVariacao::luminanciaRelativa($vermelho);

        $this->assertNotEquals($azuis, $vermelhos);
        foreach ($vermelhos as $hex) {
            $this->assertGreaterThan($lTema, CategoriaCorVariacao::luminanciaRelativa($hex));
        }
    }

    public function test_mesma_sub_em_duas_categorias_recebe_tons_do_tema_de_cada_uma(): void
    {
        $azul = CategoriaCorVariacao::mapaPorIds('#3b82f6', [10]);
        $verde = CategoriaCorVariacao::mapaPorIds('#22c55e', [10]);

        $this->assertNotSame($azul[10], $verde[10]);
        $this->assertGreaterThan(
            CategoriaCorVariacao::luminanciaRelativa('#3b82f6'),
            CategoriaCorVariacao::luminanciaRelativa($azul[10])
        );
        $this->assertGreaterThan(
            CategoriaCorVariacao::luminanciaRelativa('#22c55e'),
            CategoriaCorVariacao::luminanciaRelativa($verde[10])
        );
    }

    public function test_mapa_e_estavel_pela_ordem_do_id(): void
    {
        $a = CategoriaCorVariacao::mapaPorIds('#3b82f6', [30, 10, 20]);
        $b = CategoriaCorVariacao::mapaPorIds('#3b82f6', [10, 20, 30]);

        $this->assertSame($a[10], $b[10]);
        $this->assertSame($a[20], $b[20]);
        $this->assertSame($a[30], $b[30]);
        $this->assertSame($a[10], CategoriaCorVariacao::variacoes('#3b82f6', 3)[0]);
    }

    public function test_proxima_nao_repete_tom_ja_usado(): void
    {
        $primeira = CategoriaCorVariacao::variacoes('#3b82f6', 1)[0];
        $segunda = CategoriaCorVariacao::proxima('#3b82f6', [$primeira]);

        $this->assertNotSame($primeira, $segunda);
        $this->assertGreaterThan(
            CategoriaCorVariacao::luminanciaRelativa('#3b82f6'),
            CategoriaCorVariacao::luminanciaRelativa($segunda)
        );
    }

    public function test_tema_preto_gera_cinzas_claros(): void
    {
        $cores = CategoriaCorVariacao::variacoes('#000000', 5);
        $lPreto = CategoriaCorVariacao::luminanciaRelativa('#000000');

        $this->assertCount(5, $cores);
        foreach ($cores as $hex) {
            $this->assertGreaterThan($lPreto, CategoriaCorVariacao::luminanciaRelativa($hex));
            $this->assertSame(substr($hex, 1, 2), substr($hex, 3, 2));
            $this->assertSame(substr($hex, 3, 2), substr($hex, 5, 2));
        }
    }

    public function test_lookups_trazem_cinco_variacoes_mais_claras(): void
    {
        $lookups = CategoriaCoresTema::lookups();
        $this->assertCount(5, $lookups['temas'][0]['variacoes']);

        foreach ($lookups['temas'] as $tema) {
            $this->assertCount(5, $tema['variacoes']);
            $lTema = CategoriaCorVariacao::luminanciaRelativa($tema['hex']);
            foreach ($tema['variacoes'] as $hex) {
                $this->assertGreaterThan($lTema, CategoriaCorVariacao::luminanciaRelativa($hex));
            }
        }
    }
}
