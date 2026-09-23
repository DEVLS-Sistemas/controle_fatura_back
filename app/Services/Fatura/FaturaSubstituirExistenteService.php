<?php

namespace App\Services\Fatura;

use App\Exceptions\FaturaSelecaoException;
use App\Models\Fatura;
use Illuminate\Http\UploadedFile;

/**
 * Competência já tem fatura: cadastrar (stub) ou substituir (com anexo, outro arquivo).
 */
class FaturaSubstituirExistenteService
{
    public const ACAO_CADASTRAR = 'cadastrar';

    public const ACAO_SUBSTITUIR = 'substituir';

    public const MENSAGEM_SUBSTITUIDA = 'Fatura substituída. As transações estão sendo atualizadas com o extrato novo.';

    public const MENSAGEM_PROCESSANDO = 'A fatura está sendo processada. Aguarde para substituir o anexo.';

    public static function confirmou(object $atributes): bool
    {
        return filter_var($atributes->confirmar_substituir_fatura ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Substituir o anexo da mesma fatura sempre dispara o job (não respeita processar_automatico=false).
     */
    public static function deveForcarProcessamento(object $atributes, bool $jaTinhaAnexo): bool
    {
        return self::confirmou($atributes) || $jaTinhaAnexo;
    }

    public static function deveDispararProcessamento(object $atributes, bool $jaTinhaAnexo): bool
    {
        if (self::deveForcarProcessamento($atributes, $jaTinhaAnexo)) {
            return true;
        }

        return filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function throwSeProcessando(Fatura $fatura, ?int $userId = null): void
    {
        if ((string) $fatura->status !== 'processando') {
            return;
        }

        $userId = $userId ?? (int) $fatura->user_id;
        $payload = [
            'fatura_processando' => true,
            'fatura_existente_id' => (int) $fatura->id,
        ];

        try {
            $payload['fatura_existente'] = (new FaturaAnexoHashService)->payloadFaturaExistente($fatura, $userId);
        } catch (\Throwable) {
            $payload['fatura_existente'] = [
                'id' => (int) $fatura->id,
                'status' => 'processando',
            ];
        }

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_FATURA_PROCESSANDO,
            $payload,
            self::MENSAGEM_PROCESSANDO
        );
    }

    public static function faturaExistenteIdDoRequest(object $atributes): ?int
    {
        $id = (int) ($atributes->fatura_existente_id ?? 0);

        return $id > 0 ? $id : null;
    }

    public static function acaoSugerida(?Fatura $fatura): string
    {
        if ($fatura !== null && $fatura->temAnexo()) {
            return self::ACAO_SUBSTITUIR;
        }

        return self::ACAO_CADASTRAR;
    }

    public static function mesmoHashDoAnexo(Fatura $existente, UploadedFile $file): bool
    {
        $novo = FaturaAnexoHashService::hashArquivo($file);
        $atual = is_string($existente->anexo_hash) && $existente->anexo_hash !== ''
            ? $existente->anexo_hash
            : FaturaAnexoHashService::hashPathStorage(
                ! empty($existente->arquivo_pdf) ? $existente->arquivo_pdf : $existente->arquivo_csv
            );

        return is_string($atual) && $atual !== '' && $atual === $novo;
    }

    public function deveExigirConfirmacao(Fatura $existente, object $atributes): bool
    {
        if (self::confirmou($atributes)) {
            return false;
        }

        if (! $existente->temAnexo()) {
            return false;
        }

        if (empty($atributes->arquivo_pdf) || ! ($atributes->arquivo_pdf instanceof UploadedFile)) {
            return false;
        }

        if (self::mesmoHashDoAnexo($existente, $atributes->arquivo_pdf)) {
            return false;
        }

        return true;
    }

    /**
     * @return never
     */
    public function throwFaturaJaAnexada(Fatura $existente, int $userId): never
    {
        $payload = (new FaturaAnexoHashService)->payloadFaturaExistente($existente, $userId);
        $rotuloCartao = $payload['cartao_nome'] ?: 'cartão';
        $trechoPessoa = ! empty($payload['pessoa_nome']) ? ' ('.$payload['pessoa_nome'].')' : '';
        $orientacao = 'Já existe fatura com anexo nesta competência: '
            .$rotuloCartao.' '.$payload['competencia'].$trechoPessoa
            .'. Substituir fatura usa este arquivo na mesma linha (não cria outra). '
            .'Cancelar mantém o anexo atual.';

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_FATURA_JA_ANEXADA,
            [
                'fatura_ja_anexada' => true,
                'acao_sugerida' => self::ACAO_SUBSTITUIR,
                'fatura_existente_id' => (int) $existente->id,
                'orientacao' => $orientacao,
                'fatura_existente' => $payload,
            ]
        );
    }
}
