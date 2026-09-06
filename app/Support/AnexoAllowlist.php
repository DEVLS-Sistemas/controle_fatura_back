<?php

namespace App\Support;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoTipoArquivo;

class AnexoAllowlist
{
    public const TAMANHO_MAXIMO_BYTES = 10 * 1024 * 1024;

    /**
     * @return array<int, array{mime: string, extensoes: array<int, string>, tipo: AnexoTipoArquivo}>
     */
    public static function regras(AnexoOrigem $origem): array
    {
        return match ($origem) {
            AnexoOrigem::Fatura => [
                [
                    'mime' => 'application/pdf',
                    'extensoes' => ['pdf'],
                    'tipo' => AnexoTipoArquivo::Pdf,
                ],
                [
                    'mime' => 'text/csv',
                    'extensoes' => ['csv'],
                    'tipo' => AnexoTipoArquivo::Csv,
                ],
            ],
            AnexoOrigem::Compra => [
                [
                    'mime' => 'application/pdf',
                    'extensoes' => ['pdf'],
                    'tipo' => AnexoTipoArquivo::Pdf,
                ],
                [
                    'mime' => 'image/jpeg',
                    'extensoes' => ['jpg', 'jpeg'],
                    'tipo' => AnexoTipoArquivo::Imagem,
                ],
                [
                    'mime' => 'image/png',
                    'extensoes' => ['png'],
                    'tipo' => AnexoTipoArquivo::Imagem,
                ],
                [
                    'mime' => 'image/webp',
                    'extensoes' => ['webp'],
                    'tipo' => AnexoTipoArquivo::Imagem,
                ],
            ],
        };
    }

    public static function aceita(AnexoOrigem $origem, string $mime, string $extensao): bool
    {
        $extensao = strtolower(ltrim($extensao, '.'));
        $mime = strtolower($mime);

        foreach (static::regras($origem) as $regra) {
            if ($regra['mime'] === $mime && in_array($extensao, $regra['extensoes'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function rejeita(AnexoOrigem $origem, string $mime, string $extensao): bool
    {
        return ! static::aceita($origem, $mime, $extensao);
    }

    public static function tipo(AnexoOrigem $origem, string $mime, string $extensao): ?AnexoTipoArquivo
    {
        $extensao = strtolower(ltrim($extensao, '.'));
        $mime = strtolower($mime);

        foreach (static::regras($origem) as $regra) {
            if ($regra['mime'] === $mime && in_array($extensao, $regra['extensoes'], true)) {
                return $regra['tipo'];
            }
        }

        return null;
    }

    public static function tamanhoPermitido(?int $bytes): bool
    {
        return $bytes !== null && $bytes > 0 && $bytes <= self::TAMANHO_MAXIMO_BYTES;
    }
}
