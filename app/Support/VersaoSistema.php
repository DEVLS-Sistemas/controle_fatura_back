<?php

namespace App\Support;

use RuntimeException;

class VersaoSistema
{
    public static function caminho(): string
    {
        return dirname(__DIR__, 2).'/version.json';
    }

    /**
     * @return array{name: string, version: string, version_short: string}
     */
    public static function dados(?string $caminho = null): array
    {
        $arquivo = $caminho ?? static::caminho();

        if (! is_readable($arquivo)) {
            throw new RuntimeException('Arquivo version.json não encontrado.');
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);

        if (! is_array($dados) || empty($dados['version'])) {
            throw new RuntimeException('version.json inválido.');
        }

        $version = (string) $dados['version'];
        $partes = explode('.', $version);

        return [
            'name' => (string) ($dados['name'] ?? 'controle-fatura-back'),
            'version' => $version,
            'version_short' => (string) ($dados['version_short'] ?? ($partes[0].'.'.($partes[1] ?? '0'))),
        ];
    }

    public static function name(): string
    {
        return static::dados()['name'];
    }

    public static function version(): string
    {
        return static::dados()['version'];
    }

    public static function versionShort(): string
    {
        return static::dados()['version_short'];
    }
}
