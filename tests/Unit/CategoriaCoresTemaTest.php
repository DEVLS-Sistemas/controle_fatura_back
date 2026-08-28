<?php

namespace Tests\Unit;

use App\Services\Categoria\CategoriaCoresTema;
use Exception;
use PHPUnit\Framework\TestCase;

class CategoriaCoresTemaTest extends TestCase
{
    public function test_lookups_preto_primeiro_e_temas_casam_com_cores(): void
    {
        $lookups = CategoriaCoresTema::lookups();

        $this->assertSame('#000000', $lookups['cor_padrao']);
        $this->assertSame('#000000', $lookups['cores'][0]);
        $this->assertSame('preto', $lookups['temas'][0]['chave']);
        $this->assertTrue($lookups['temas'][0]['padrao']);
        $this->assertSame([], $lookups['temas'][0]['variacoes']);

        $this->assertSame($lookups['cores'], array_column($lookups['temas'], 'hex'));
        $this->assertContains('#3b82f6', $lookups['cores']);
        $this->assertContains('#14b8a6', $lookups['cores']);
        $this->assertCount(9, $lookups['temas']);
    }

    public function test_parse_sem_cor_grava_preto(): void
    {
        $this->assertSame('#000000', CategoriaCoresTema::parseParaGravar(null));
        $this->assertSame('#000000', CategoriaCoresTema::parseParaGravar(''));
        $this->assertSame('#000000', CategoriaCoresTema::parseParaGravar('   '));
    }

    public function test_parse_normaliza_hex_maiusculo_e_curto(): void
    {
        $this->assertSame('#3b82f6', CategoriaCoresTema::parseParaGravar('#3B82F6'));
        $this->assertSame('#aabbcc', CategoriaCoresTema::parseParaGravar('#AbC'));
    }

    public function test_parse_lixo_lanca_422(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cor deve ser um hexadecimal válido (ex.: #3b82f6)');
        $this->expectExceptionCode(422);

        CategoriaCoresTema::parseParaGravar('foo');
    }

    public function test_normalizar_legado_vazio_vira_preto_sem_lancar(): void
    {
        $this->assertSame('#000000', CategoriaCoresTema::normalizar(null));
        $this->assertSame('#000000', CategoriaCoresTema::normalizar(''));
        $this->assertSame('#000000', CategoriaCoresTema::normalizar('azul'));
        $this->assertSame('#ef4444', CategoriaCoresTema::normalizar('#EF4444'));
    }

    public function test_grafico_cadastrada_sem_cor_preto_e_bucket_cinza(): void
    {
        $this->assertSame('#000000', CategoriaCoresTema::corParaGrafico(null, 2));
        $this->assertSame('#3b82f6', CategoriaCoresTema::corParaGrafico('#3B82F6', 2));
        $this->assertSame('#9ca3af', CategoriaCoresTema::corParaGrafico(null, null));
        $this->assertSame('#9ca3af', CategoriaCoresTema::corParaGrafico('#3b82f6', 0));
        $this->assertSame('#9ca3af', CategoriaCoresTema::corParaGrafico(null, '0'));
    }
}
