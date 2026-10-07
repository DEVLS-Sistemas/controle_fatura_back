<?php

namespace App\Services\Fatura;

use App\Exceptions\FaturaSelecaoException;
use App\Models\Fatura;
use App\Services\Pdf\FaturaParserHomologacao;
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

    public const MENSAGEM_ARQUIVO_DIVERGE = 'Este arquivo não é do mesmo cartão, bandeira e competência. Confirme para cadastrar em vez de substituir.';

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

    /**
     * O id da tela de origem não vale se for outro cartão, outra bandeira ou outra competência.
     */
    public static function escolhaConfereComFatura(Fatura $fatura, object $atributes): bool
    {
        if (! empty($atributes->cartao_id) && (int) $fatura->cartao_id !== (int) $atributes->cartao_id) {
            return false;
        }

        if (self::informado($atributes->mes ?? null) && (int) $fatura->mes !== (int) $atributes->mes) {
            return false;
        }

        if (self::informado($atributes->ano ?? null) && (int) $fatura->ano !== (int) $atributes->ano) {
            return false;
        }

        if (! empty($atributes->cartao_bandeira_id)
            && $fatura->cartao_bandeira_id !== null
            && (int) $fatura->cartao_bandeira_id !== (int) $atributes->cartao_bandeira_id
        ) {
            return false;
        }

        return true;
    }

    /**
     * Só substitui se o arquivo for uma fatura válida do mesmo cartão, bandeira e competência.
     */
    public static function arquivoConfereComAlvo(
        string $parser,
        ?int $mesArquivo,
        ?int $anoArquivo,
        ?string $bandeiraSugerida,
        int $mesAlvo,
        int $anoAlvo,
        ?string $cartaoNome,
        ?string $cartaoBanco,
        ?string $bandeiraAlvo,
    ): bool {
        if (! self::arquivoValidoParaSubstituir($parser, $mesArquivo, $anoArquivo)) {
            return false;
        }

        if ((int) $mesArquivo !== $mesAlvo || (int) $anoArquivo !== $anoAlvo) {
            return false;
        }

        if (! self::parserConfereComCartao($parser, $cartaoNome, $cartaoBanco)) {
            return false;
        }

        return self::bandeirasConferem($bandeiraSugerida, $bandeiraAlvo);
    }

    public static function arquivoValidoParaSubstituir(string $parser, ?int $mes, ?int $ano): bool
    {
        if ($mes === null || $ano === null || $mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
            return false;
        }

        $base = strtolower(explode('-', $parser)[0]);
        if ($base === 'csv') {
            return true;
        }

        return FaturaParserHomologacao::isParserHomologado($parser);
    }

    public static function parserConfereComCartao(string $parser, ?string $nome, ?string $banco): bool
    {
        $base = strtolower(explode('-', $parser)[0]);
        if ($base === '' || $base === 'generico' || $base === 'xml') {
            return false;
        }

        if ($base === 'csv') {
            return true;
        }

        $aliases = self::aliasesDoBanco($base);
        if ($aliases === []) {
            return false;
        }

        $haystack = mb_strtolower(trim(($nome ?? '').' '.($banco ?? '')));
        foreach ($aliases as $alias) {
            if ($alias !== '' && str_contains($haystack, $alias)) {
                return true;
            }
        }

        return false;
    }

    public static function bandeirasConferem(?string $sugerida, ?string $daFatura): bool
    {
        $arquivo = self::normalizarBandeira($sugerida);
        $alvo = self::normalizarBandeira($daFatura);
        if ($arquivo === '' || $alvo === '') {
            return true;
        }

        return $arquivo === $alvo;
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

    /**
     * @param  array<string, mixed>  $sugestao
     * @return never
     */
    public function throwArquivoDivergeAlvo(Fatura $alvo, int $userId, array $sugestao = []): never
    {
        $payload = (new FaturaAnexoHashService)->payloadFaturaExistente($alvo, $userId);
        $rotuloCartao = $payload['cartao_nome'] ?: 'cartão';
        $competencia = (string) ($payload['competencia'] ?? '');
        $orientacao = 'O arquivo não é da fatura '.$rotuloCartao
            .($competencia !== '' ? ' '.$competencia : '')
            .'. Nada foi substituído. Confirme para cadastrar este arquivo, sem alterar a fatura escolhida.';

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_ARQUIVO_DIVERGE_ALVO,
            [
                'arquivo_diverge_alvo' => true,
                'acao_sugerida' => self::ACAO_CADASTRAR,
                'fatura_existente_id' => (int) $alvo->id,
                'orientacao' => $orientacao,
                'fatura_existente' => $payload,
                'sugestao' => $sugestao,
            ],
            self::MENSAGEM_ARQUIVO_DIVERGE
        );
    }

    private static function informado(mixed $valor): bool
    {
        if ($valor === null || $valor === '') {
            return false;
        }

        return (int) $valor > 0;
    }

    private static function normalizarBandeira(?string $valor): string
    {
        $texto = mb_strtolower(trim((string) $valor));

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function aliasesDoBanco(string $base): array
    {
        return match ($base) {
            'c6' => ['c6', 'c6 bank', 'c6bank'],
            'nubank' => ['nubank', 'nu pagamentos'],
            'inter' => ['inter'],
            'itau' => ['itaú', 'itau', 'unibanco'],
            'picpay' => ['picpay'],
            'sofisa' => ['sofisa'],
            default => [],
        };
    }
}
