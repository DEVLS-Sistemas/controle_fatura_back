<?php

namespace App\Services\Fatura;

use App\Exceptions\FaturaSelecaoException;
use App\Models\Fatura;
use App\Models\Pessoa;
use App\Models\Transacao;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FaturaAnexoHashService
{
    public const CONFIRMAR_SUBSTITUIR = 'substituir';

    public const CONFIRMAR_MANTER = 'manter';

    public static function hashConteudo(string $contents): string
    {
        return hash('sha256', $contents);
    }

    public static function hashArquivo(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $hash = hash_file('sha256', $path);
            if ($hash !== false) {
                return $hash;
            }
        }

        return self::hashConteudo($file->getContent());
    }

    public static function hashPathStorage(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }

        if (!Storage::disk('local')->exists($relative)) {
            return null;
        }

        $full = Storage::disk('local')->path($relative);
        if (!is_readable($full)) {
            return null;
        }

        $hash = hash_file('sha256', $full);

        return $hash !== false ? $hash : null;
    }

    public static function confirmacaoDoRequest(object $atributes): ?string
    {
        $raw = strtolower(trim((string) ($atributes->confirmar_anexo_duplicado ?? '')));

        return match ($raw) {
            self::CONFIRMAR_SUBSTITUIR, self::CONFIRMAR_MANTER => $raw,
            default => null,
        };
    }

    public static function faturaDuplicadaIdDoRequest(object $atributes): ?int
    {
        $id = (int) ($atributes->fatura_duplicada_id ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Recalcula `anexo_hash` a partir dos arquivos ainda gravados (PDF tem prioridade).
     */
    public function sincronizarHash(Fatura $fatura): void
    {
        $path = !empty($fatura->arquivo_pdf) ? $fatura->arquivo_pdf : $fatura->arquivo_csv;
        $hash = self::hashPathStorage($path);
        if ($fatura->anexo_hash === $hash) {
            return;
        }

        $fatura->anexo_hash = $hash;
        $fatura->save();
    }

    /**
     * @return array{acao: 'seguir'}|array{acao: 'manter'|'substituir', fatura: Fatura}
     */
    public function resolver(object $atributes, int $userId, ?int $faturaAlvoId): array
    {
        $confirmacao = self::confirmacaoDoRequest($atributes);

        if ($confirmacao === self::CONFIRMAR_MANTER) {
            return [
                'acao' => self::CONFIRMAR_MANTER,
                'fatura' => $this->faturaConfirmadaDoRequest($atributes, $userId),
            ];
        }

        $temArquivo = !empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile;
        if (!$temArquivo) {
            return ['acao' => 'seguir'];
        }

        /** @var UploadedFile $file */
        $file = $atributes->arquivo_pdf;
        $hash = self::hashArquivo($file);
        $this->garantirHashAnexosDoUsuario($userId);

        if ($faturaAlvoId !== null && $faturaAlvoId > 0) {
            $alvo = Fatura::where('id', $faturaAlvoId)->where('user_id', $userId)->first();
            if ($alvo && $alvo->anexo_hash === $hash) {
                if ($confirmacao === self::CONFIRMAR_SUBSTITUIR) {
                    $this->assertPodeSubstituir($alvo);

                    return [
                        'acao' => self::CONFIRMAR_SUBSTITUIR,
                        'fatura' => $alvo,
                    ];
                }

                return ['acao' => 'seguir'];
            }
        }

        $duplicada = $this->encontrarDuplicada($userId, $hash, $faturaAlvoId);

        if ($duplicada === null) {
            if ($confirmacao === self::CONFIRMAR_SUBSTITUIR) {
                return [
                    'acao' => self::CONFIRMAR_SUBSTITUIR,
                    'fatura' => $this->faturaConfirmadaDoRequest($atributes, $userId),
                ];
            }

            return ['acao' => 'seguir'];
        }

        if ($confirmacao === self::CONFIRMAR_SUBSTITUIR) {
            $pedida = self::faturaDuplicadaIdDoRequest($atributes);
            if ($pedida !== null && $pedida !== (int) $duplicada->id) {
                $this->throwAnexoDuplicado($duplicada, $userId);
            }
            $this->assertPodeSubstituir($duplicada);

            return [
                'acao' => self::CONFIRMAR_SUBSTITUIR,
                'fatura' => $duplicada,
            ];
        }

        $this->throwAnexoDuplicado($duplicada, $userId);
    }

    public function encontrarDuplicada(int $userId, string $hash, ?int $excetoId): ?Fatura
    {
        $this->garantirHashAnexosDoUsuario($userId);

        $query = Fatura::query()
            ->where('user_id', $userId)
            ->where('anexo_hash', $hash)
            ->where(function ($q) {
                $q->whereNotNull('arquivo_pdf')->orWhereNotNull('arquivo_csv');
            })
            ->orderBy('id');

        if ($excetoId !== null && $excetoId > 0) {
            $query->where('id', '!=', $excetoId);
        }

        return $query->first();
    }

    private function garantirHashAnexosDoUsuario(int $userId): void
    {
        $faturas = Fatura::query()
            ->where('user_id', $userId)
            ->whereNull('anexo_hash')
            ->where(function ($q) {
                $q->whereNotNull('arquivo_pdf')->orWhereNotNull('arquivo_csv');
            })
            ->get();

        foreach ($faturas as $fatura) {
            $this->sincronizarHash($fatura);
        }
    }

    private function faturaConfirmadaDoRequest(object $atributes, int $userId): Fatura
    {
        $id = self::faturaDuplicadaIdDoRequest($atributes);
        if ($id === null) {
            throw new Exception('Informe a fatura que já possui este anexo', 422);
        }

        $fatura = Fatura::where('id', $id)->where('user_id', $userId)->first();
        if (!$fatura) {
            throw new Exception('Fatura não encontrada', 404);
        }

        return $fatura;
    }

    private function assertPodeSubstituir(Fatura $fatura): void
    {
        if ((string) $fatura->status === 'processando') {
            throw new Exception('A fatura está sendo processada. Aguarde para substituir o anexo.', 422);
        }
    }

    /**
     * @return never
     */
    public function throwAnexoDuplicado(Fatura $existente, int $userId): never
    {
        $existente->loadMissing(['cartao', 'cartaoBandeira', 'pessoa']);

        $cartaoNome = $existente->cartao?->nome;
        $competencia = sprintf('%02d/%d', (int) $existente->mes, (int) $existente->ano);
        $pessoaNome = $existente->pessoa?->nomeCompleto();
        if ($pessoaNome === null && $existente->pessoa_id) {
            $pessoa = Pessoa::where('id', $existente->pessoa_id)->where('user_id', $userId)->first();
            $pessoaNome = $pessoa?->nomeCompleto();
        }

        $intervalo = $existente->cartao
            ? $existente->cartao->intervaloPeriodoFatura((int) $existente->mes, (int) $existente->ano)
            : [
                'periodo_inicio' => null,
                'periodo_fim' => null,
                'data_vencimento' => null,
            ];

        $rotuloCartao = $cartaoNome ?: 'cartão';
        $trechoPessoa = $pessoaNome ? ' (' . $pessoaNome . ')' : '';
        $orientacao = 'O conteúdo deste PDF/CSV é o mesmo da fatura '
            . $rotuloCartao . ' ' . $competencia . $trechoPessoa
            . '. Substituir atualiza aquela fatura. Salvar sem substituir mantém o anexo atual e não cria outra fatura.';

        $temPdf = !empty($existente->arquivo_pdf);
        $temCsv = !empty($existente->arquivo_csv);

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_ANEXO_DUPLICADO,
            [
                'anexo_duplicado' => true,
                'orientacao' => $orientacao,
                'fatura_existente' => [
                    'id' => (int) $existente->id,
                    'cartao_id' => (int) $existente->cartao_id,
                    'cartao_nome' => $cartaoNome,
                    'bandeira' => $existente->cartaoBandeira?->bandeira,
                    'pessoa_id' => $existente->pessoa_id !== null ? (int) $existente->pessoa_id : null,
                    'pessoa_nome' => $pessoaNome,
                    'mes' => (int) $existente->mes,
                    'ano' => (int) $existente->ano,
                    'competencia' => $competencia,
                    'periodo_inicio' => $intervalo['periodo_inicio'],
                    'periodo_fim' => $intervalo['periodo_fim'],
                    'data_vencimento' => $intervalo['data_vencimento'],
                    'valor_total' => $existente->valor_total,
                    'status' => $existente->status,
                    'total_transacoes' => $this->contarTransacoesVisiveis($existente, $userId),
                    'tem_pdf' => $temPdf,
                    'tem_csv' => $temCsv,
                    'pdf_url' => $temPdf ? url('/api/v1/faturas/pdf/' . $existente->id) : null,
                    'processado_em' => $existente->processado_em,
                    'created_at' => $existente->created_at?->format('Y-m-d H:i:s'),
                ],
            ],
            'Este arquivo já foi anexado em outra fatura. Deseja substituir o anexo ou manter o que já está salvo?'
        );
    }

    private function contarTransacoesVisiveis(Fatura $fatura, int $userId): int
    {
        return (int) Transacao::query()
            ->where('fatura_id', $fatura->id)
            ->where('user_id', $userId)
            ->where('ignorar_no_total', 0)
            ->count();
    }
}
