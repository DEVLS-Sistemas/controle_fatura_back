<?php

namespace Tests\Unit;

use App\Support\VersaoSistema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VersaoSistemaTest extends TestCase
{
    public function test_le_version_json_do_repositorio(): void
    {
        $dados = VersaoSistema::dados();

        $this->assertSame('controle-fatura-back', $dados['name']);
        $this->assertSame('1.0.0', $dados['version']);
        $this->assertSame('1.0', $dados['version_short']);
        $this->assertSame('1.0.0', VersaoSistema::version());
        $this->assertSame('1.0', VersaoSistema::versionShort());
    }

    public function test_deriva_version_short_quando_ausente(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'versao');
        file_put_contents($arquivo, json_encode([
            'name' => 'teste',
            'version' => '2.3.4',
        ]));

        try {
            $dados = VersaoSistema::dados($arquivo);
            $this->assertSame('2.3', $dados['version_short']);
        } finally {
            unlink($arquivo);
        }
    }

    public function test_falha_se_arquivo_nao_existe(): void
    {
        $this->expectException(RuntimeException::class);
        VersaoSistema::dados('/tmp/version-inexistente.json');
    }
}
