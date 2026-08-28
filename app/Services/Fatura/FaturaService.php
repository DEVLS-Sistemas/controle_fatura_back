<?php

namespace App\Services\Fatura;

use App\Exceptions\FaturaSelecaoException;
use App\Exceptions\PdfPasswordException;
use App\Jobs\ProcessInvoicePdfJob;
use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
use App\Models\Fatura;
use App\Models\Pessoa;
use App\Models\Responsavel;
use App\Models\Transacao;
use App\Models\User;
use App\Services\Cartao\BandeiraCoresPreset;
use App\Services\Cartao\CartaoService;
use App\Services\PaginateService;
use App\Services\Pdf\FaturaParserHomologacao;
use App\Services\Pdf\InvoicePdfParserService;
use App\Services\Pdf\PdfSenhaRegra;
use App\Services\Pessoa\NomeMatch;
use App\Services\Pessoa\PessoaService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FaturaService
{
    public function handleLookupsFatura(): array
    {
        $userId = Auth::id();

        return [
            'status' => [
                ['value' => 'pendente', 'label' => 'Pendente'],
                ['value' => 'processando', 'label' => 'Processando'],
                ['value' => 'processada', 'label' => 'Processada'],
                ['value' => 'erro', 'label' => 'Erro'],
            ],
            'cartoes' => Cartao::where('user_id', $userId)
                ->where('ativo', true)
                ->with(['bandeiras' => function ($q) {
                    $q->whereNull('deleted_at')
                        ->where('ativo', true)
                        ->orderBy('bandeira')
                        ->select('id', 'cartao_id', 'bandeira', 'limite_credito', 'cor_principal', 'cor_secundaria', 'ativo');
                }])
                ->orderBy('nome')
                ->get()
                ->map(fn (Cartao $c) => array_merge([
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'banco' => $c->banco,
                    'dia_limite_fatura' => $c->dia_limite_fatura,
                    'dia_vencimento_fatura' => $c->dia_vencimento_fatura,
                    'cor_fundo' => $c->cor_fundo,
                    'cor_texto' => $c->cor_texto,
                    'tem_senha_pdf' => $c->temSenhaPdf(),
                    'senha_pdf_regra' => $c->senha_pdf_regra,
                    'senha_pdf_orientacao' => PdfSenhaRegra::orientacao($c->senha_pdf_regra),
                    'bandeiras' => $c->bandeiras->map(fn (CartaoBandeira $b) => array_merge([
                        'id' => $b->id,
                        'cartao_id' => $b->cartao_id,
                        'bandeira' => $b->bandeira,
                        'limite_credito' => $b->limite_credito,
                        'ativo' => (bool) $b->ativo,
                    ], BandeiraCoresPreset::anexar($b->bandeira, $b->cor_principal, $b->cor_secundaria)))->values()->all(),
                ], FaturaParserHomologacao::anexarCartao($c->nome, $c->banco)))->values()->all(),
            'meses' => collect(range(1, 12))->map(fn ($m) => [
                'value' => $m,
                'label' => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
            ]),
            'anos' => $this->anosLookupsFatura((int) $userId),
            'competencia_atual' => self::competenciaAtual(),
            'senhas_pdf_regras' => PdfSenhaRegra::all(),
            'parsers_homologados' => FaturaParserHomologacao::all(),
        ];
    }

    public function handleAddFatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->createFatura($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditFatura(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->updateFatura($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteFatura(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->deleteFatura($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Soft-delete de todas as faturas e transações do usuário autenticado.
     * Útil para resetar dados em testes. Exige confirmar=true.
     */
    public function handleDeleteTodasFaturas(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->deleteTodasFaturas($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleUploadPdf(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->fatura = $this->uploadPdf($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleProcessarPdf(int|string $id, ?object $atributes = null): object
    {
        try {
            $fatura = Fatura::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$fatura) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if (!$fatura->arquivo_pdf && !$fatura->arquivo_csv) {
                throw new Exception('Fatura sem arquivo para processar', 422);
            }

            $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
            $salvarSenha = filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN);
            $senhaPdfRegra = $this->extractSenhaPdfRegraFromRequest($atributes);

            $fatura->update([
                'status' => 'pendente',
                'erro_mensagem' => null,
                'erro_codigo' => null,
            ]);

            $this->dispatchProcessamento(
                $fatura->id,
                null,
                $senhaPdf,
                $salvarSenha,
                rethrowSenha: true,
                cartaoNumeroIdPadrao: null,
                senhaPdfRegra: $senhaPdfRegra
            );

            return $this->buildFaturaProcessamentoResponse(
                $fatura->fresh(['cartao']),
                'Processamento da fatura iniciado!'
            );
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleImpactoRemoverAnexo(int|string $id): object
    {
        return (new FaturaAnexoReversaoService())->handlePreview($id);
    }

    public function handleComprasParaReconcilia(int|string $id): object
    {
        return (new FaturaAnexoReversaoService())->handleComprasParaReconcilia($id);
    }

    public function handleRemoverAnexo(object $atributes): object
    {
        try {
            DB::beginTransaction();
            $motivo = trim((string) ($atributes->motivo ?? ''));
            $reversao = new FaturaAnexoReversaoService();
            $result = $motivo === FaturaAnexoReversaoService::MOTIVO_TROCAR_PDF
                ? $this->trocarAnexo($atributes, $reversao)
                : $reversao->handleRemover($atributes);
            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Etapa 3: desfaz o extrato errado e anexa/processa o arquivo novo.
     */
    private function trocarAnexo(object $atributes, FaturaAnexoReversaoService $reversao): object
    {
        if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
            throw new Exception('Para trocar o PDF, envie o arquivo novo.', 422);
        }

        $faturaId = (int) ($atributes->id ?? 0);
        if ($faturaId < 1) {
            throw new Exception('Informe a fatura', 422);
        }

        $userId = (int) Auth::id();
        $plano = $reversao->reverterMantendoArquivo($faturaId);
        $compras = $plano['comprasRestaurar'];

        $this->attachPdfToFatura(
            $plano['fatura']->fresh(),
            $atributes,
            $userId,
            'PDF substituído. A fatura está sendo processada.'
        );

        $fatura = Fatura::with('cartao')->where('id', $faturaId)->where('user_id', $userId)->first();
        $status = (string) ($fatura?->status ?? 'pendente');
        $anexo = $this->buildAnexoMeta(
            $fatura?->arquivo_pdf,
            $fatura?->arquivo_csv,
            $faturaId
        );
        $anexo = $this->anexarPodeRemoverAnexo(array_merge($anexo, ['status' => $status]));
        $aguardando = in_array($status, ['pendente', 'processando'], true);
        $precisaSenha = $this->isSenhaPdfErro($fatura?->erro_codigo);
        $cartao = $fatura?->cartao;

        return (object) [
            'status' => true,
            'message' => $aguardando
                ? 'PDF substituído. A fatura está sendo processada.'
                : 'PDF substituído.',
            'precisa_senha_pdf' => $precisaSenha,
            'data' => [
                'fatura_id' => $faturaId,
                'motivo' => FaturaAnexoReversaoService::MOTIVO_TROCAR_PDF,
                'anexo_removido' => true,
                'tem_pdf' => $anexo['tem_pdf'],
                'tem_csv' => $anexo['tem_csv'],
                'tipo_arquivo' => $anexo['tipo_arquivo'],
                'pdf_url' => $anexo['pdf_url'],
                'csv_url' => $anexo['csv_url'],
                'pode_remover_anexo' => $anexo['pode_remover_anexo'],
                'status' => $status,
                'erro_codigo' => $fatura?->erro_codigo,
                'erro_mensagem' => $fatura?->erro_mensagem,
                'precisa_senha_pdf' => $precisaSenha,
                'senha_pdf' => $this->buildSenhaPdfMeta(
                    $fatura?->erro_codigo,
                    $fatura?->cartao_id !== null ? (int) $fatura->cartao_id : null,
                    $cartao?->senha_pdf_regra,
                    (bool) ($cartao?->temSenhaPdf())
                ),
                'aguardando_processamento' => $aguardando,
                'lancamentos_apagados' => $plano['lancamentos']->count(),
                'parcelas_apagadas_outras_faturas' => $plano['parcelasOutras']->count(),
                'faturas_stub_excluidas' => array_map(
                    fn (array $stub) => $stub['id'],
                    $plano['stubsExcluir']
                ),
                'compras_que_voltaram_a_conciliar' => $compras,
                'avisos' => FaturaAnexoReversaoService::montarAvisos(
                    $plano['lancamentos']->count(),
                    $plano['parcelasOutras']->count(),
                    count($compras),
                    $plano['stubsExcluir']
                ),
            ],
        ];
    }

    public function createFatura(object $atributes): object
    {
        try {
            $userId = Auth::id();
            $temArquivo = !empty($atributes->arquivo_pdf) && $atributes->arquivo_pdf instanceof UploadedFile;
            $tipoAnexo = $temArquivo ? $this->resolveAnexoTipo($atributes->arquivo_pdf) : null;

            // Sem anexo: cartão + mês + ano obrigatórios.
            // Com anexo: podem vir vazios — o PDF/CSV sugere e o front confirma no modal.
            // Retry do modal sem cartão existente: cadastra cartão (nome + bandeira) na mesma request.
            if (!$temArquivo) {
                $this->validatePeriodo($atributes);
            } elseif ($this->hasPeriodoCompleto($atributes)) {
                $this->validatePeriodo($atributes);
            } elseif ($this->hasCadastroCartaoInline($atributes)) {
                $this->criarCartaoInlineNoCadastroFatura($atributes, $userId);
                $this->validatePeriodo($atributes);
            } elseif ($this->preencherPeriodoDoAnexoSeStubExistente($atributes, $userId)) {
                $this->validatePeriodo($atributes);
            } else {
                $this->throwConfirmacaoMetadadosDoAnexo($atributes, $userId);
            }

            if ($temArquivo) {
                $this->aplicarPeriodoDetectadoDoAnexo($atributes);
                $this->validatePeriodo($atributes);
            }

            $this->assertCartaoDoUsuario($atributes->cartao_id, $userId);

            $cartaoId = (int) $atributes->cartao_id;
            $cartaoNumeroIdPadrao = null;

            $pessoaIdResolvida = null;
            if ($temArquivo) {
                $pessoaIdResolvida = $this->assertTitularConfirmadoSeNecessario($atributes, $userId, $cartaoId);
            } else {
                $pessoaIdResolvida = $this->resolvePessoaIdOpcional($atributes, $userId, $cartaoId);
            }

            $existingCandidate = Fatura::where('user_id', $userId)
                ->where('cartao_id', $cartaoId)
                ->where('mes', (int) $atributes->mes)
                ->where('ano', (int) $atributes->ano)
                ->orderByRaw('cartao_bandeira_id is null')
                ->get();

            if ($temArquivo && $tipoAnexo !== null) {
                $previewBandeiraId = !empty($atributes->cartao_bandeira_id)
                    ? (int) $atributes->cartao_bandeira_id
                    : null;
                $existingForSelecao = $existingCandidate
                    ->filter(function (Fatura $f) use ($previewBandeiraId) {
                        if ($previewBandeiraId !== null) {
                            return (int) ($f->cartao_bandeira_id ?? 0) === $previewBandeiraId
                                || $f->cartao_bandeira_id === null;
                        }

                        return true;
                    })
                    ->sortByDesc(fn (Fatura $f) => !empty($f->arquivo_pdf))
                    ->first();

                $selecao = $this->assertSelecaoBandeiraFinalParaAnexo(
                    $cartaoId,
                    $userId,
                    $atributes,
                    $tipoAnexo,
                    $existingForSelecao
                );
                $bandeiraId = $selecao['bandeira_id'];
                $cartaoNumeroIdPadrao = $selecao['cartao_numero_id'];
            } else {
                $bandeiraId = $this->resolveCartaoBandeiraId(
                    $cartaoId,
                    $userId,
                    $atributes->cartao_bandeira_id ?? null
                );
            }

            $existing = $existingCandidate->first(function (Fatura $f) use ($bandeiraId) {
                if ($bandeiraId !== null) {
                    return (int) ($f->cartao_bandeira_id ?? 0) === $bandeiraId
                        || $f->cartao_bandeira_id === null;
                }

                return $f->cartao_bandeira_id === null;
            });

            // Fatura já criada (ex.: parcela futura): com arquivo no request, anexa/substitui e processa.
            if ($existing) {
                if (!$temArquivo) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                if ($bandeiraId !== null && (int) ($existing->cartao_bandeira_id ?? 0) !== $bandeiraId) {
                    $conflito = Fatura::where('user_id', $userId)
                        ->where('cartao_id', $cartaoId)
                        ->where('cartao_bandeira_id', $bandeiraId)
                        ->where('mes', (int) $atributes->mes)
                        ->where('ano', (int) $atributes->ano)
                        ->where('id', '!=', $existing->id)
                        ->exists();
                    if ($conflito) {
                        throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                    }
                    $existing->cartao_bandeira_id = $bandeiraId;
                    $existing->save();
                }

                $jaTem = $tipoAnexo === 'pdf'
                    ? !empty($existing->arquivo_pdf)
                    : !empty($existing->arquivo_csv);
                $rotulo = $tipoAnexo === 'pdf' ? 'PDF' : 'CSV';
                $message = $jaTem
                    ? "{$rotulo} atualizado na fatura existente com sucesso!"
                    : "{$rotulo} anexado à fatura existente com sucesso!";

                if ($this->novoAnexoConflitaComFaturaExistente(
                    $existing,
                    $cartaoId,
                    $userId,
                    $pessoaIdResolvida,
                    $atributes
                )) {
                    $this->throwPrecisaCartaoDoTitular($existing, $cartaoId, $userId, $atributes, $pessoaIdResolvida);
                }

                if ($pessoaIdResolvida !== null) {
                    $existing->pessoa_id = $pessoaIdResolvida;
                    $existing->responsavel_id = $this->resolveResponsavelIdParaPessoa($pessoaIdResolvida, $userId);
                    $existing->save();
                    $this->linkPessoaAoCartao($cartaoId, $pessoaIdResolvida);
                    $this->realinharTransacoesImportadasAoPadrao($existing);
                }

                return $this->attachPdfToFatura(
                    $existing->fresh(),
                    $atributes,
                    $userId,
                    $message,
                    $cartaoNumeroIdPadrao
                );
            }

            $arquivoPdfPath = null;
            $arquivoCsvPath = null;
            $processar = false;

            if ($temArquivo && $tipoAnexo !== null) {
                $path = $this->storePdf($atributes->arquivo_pdf, $userId);
                if ($tipoAnexo === 'pdf') {
                    $arquivoPdfPath = $path;
                } else {
                    $arquivoCsvPath = $path;
                }
                $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
            }

            $pessoaIdFinal = $pessoaIdResolvida;
            if ($pessoaIdFinal === null) {
                $cartaoPessoaId = Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id');
                $pessoaIdFinal = $cartaoPessoaId !== null ? (int) $cartaoPessoaId : null;
            }
            $responsavelIdFinal = $pessoaIdFinal !== null
                ? $this->resolveResponsavelIdParaPessoa($pessoaIdFinal, $userId)
                : null;

            $newData = new Fatura([
                'user_id' => $userId,
                'pessoa_id' => $pessoaIdFinal,
                'responsavel_id' => $responsavelIdFinal,
                'cartao_id' => $cartaoId,
                'cartao_bandeira_id' => $bandeiraId,
                'mes' => (int) $atributes->mes,
                'ano' => (int) $atributes->ano,
                'valor_total' => $atributes->valor_total ?? 0,
                'arquivo_pdf' => $arquivoPdfPath,
                'arquivo_csv' => $arquivoCsvPath,
                'status' => 'pendente',
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Fatura', 500);
            }

            if ($pessoaIdResolvida !== null) {
                $this->linkPessoaAoCartao($cartaoId, $pessoaIdResolvida);
            }

            if ($processar && ($arquivoPdfPath || $arquivoCsvPath)) {
                $this->dispatchProcessamento(
                    $newData->id,
                    $tipoAnexo,
                    $this->extractSenhaPdfFromRequest($atributes),
                    filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN),
                    false,
                    $cartaoNumeroIdPadrao,
                    $this->extractSenhaPdfRegraFromRequest($atributes)
                );
            }

            return $this->buildFaturaProcessamentoResponse(
                $newData->fresh(['cartao']),
                'Fatura cadastrada com sucesso!'
            );
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateFatura(object $atributes): object
    {
        try {
            if (empty($atributes->id) && !empty($atributes->fatura_id)) {
                $atributes->id = $atributes->fatura_id;
            }

            if (empty($atributes->id)) {
                throw new Exception('ID da fatura é obrigatório', 422);
            }

            $record = Fatura::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            if (!empty($atributes->cartao_id)) {
                $this->assertCartaoDoUsuario($atributes->cartao_id, Auth::id());
            }

            if (!empty($atributes->mes) || !empty($atributes->ano)
                || !empty($atributes->cartao_id) || !empty($atributes->cartao_bandeira_id)
            ) {
                $mes = (int) ($atributes->mes ?? $record->mes);
                $ano = (int) ($atributes->ano ?? $record->ano);
                $cartaoId = (int) ($atributes->cartao_id ?? $record->cartao_id);
                $bandeiraId = $this->resolveCartaoBandeiraId(
                    $cartaoId,
                    (int) Auth::id(),
                    $atributes->cartao_bandeira_id ?? $record->cartao_bandeira_id
                );

                if ($mes < 1 || $mes > 12) {
                    throw new Exception('Mês inválido', 422);
                }

                $existsQuery = Fatura::where('user_id', Auth::id())
                    ->where('cartao_id', $cartaoId)
                    ->where('mes', $mes)
                    ->where('ano', $ano)
                    ->where('id', '!=', $record->id);
                if ($bandeiraId !== null) {
                    $existsQuery->where('cartao_bandeira_id', $bandeiraId);
                } else {
                    $existsQuery->whereNull('cartao_bandeira_id');
                }

                if ($existsQuery->exists()) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }

                $atributes->cartao_bandeira_id = $bandeiraId;
            }

            $data = get_object_vars($atributes);
            unset(
                $data['user_id'],
                $data['id'],
                $data['fatura_id'],
                $data['arquivo_pdf'],
                $data['arquivo_csv'],
                $data['processar_automatico']
            );

            $record->fill($data);
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Fatura', 500);
            }

            return (object) [
                'data' => $record->fresh()->load('cartao'),
                'status' => true,
                'message' => 'Fatura alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteFatura(int|string $id): object
    {
        try {
            $record = Fatura::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            (new FaturaAnexoReversaoService())->reverterAntesDeExcluirFatura($record);

            $this->limparAnexoDaFatura($record);

            Transacao::where('fatura_id', $record->id)->delete();

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Fatura', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Fatura excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteTodasFaturas(object $atributes): object
    {
        $confirmado = filter_var($atributes->confirmar ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$confirmado) {
            throw new Exception('Envie confirmar=true para excluir todas as faturas e transações', 422);
        }

        $userId = Auth::id();
        if (!$userId) {
            throw new Exception('Não autenticado', 401);
        }

        $faturas = Fatura::where('user_id', $userId)->get(['id', 'arquivo_pdf', 'arquivo_csv']);

        foreach ($faturas as $fatura) {
            $this->limparAnexoDaFatura($fatura);
        }

        $transacoesExcluidas = Transacao::where('user_id', $userId)->delete();
        $faturasExcluidas = Fatura::where('user_id', $userId)->delete();

        return (object) [
            'data' => [
                'faturas_excluidas' => (int) $faturasExcluidas,
                'transacoes_excluidas' => (int) $transacoesExcluidas,
            ],
            'status' => true,
            'message' => 'Todas as faturas e transações foram excluídas com sucesso!',
        ];
    }

    public function uploadPdf(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID da fatura é obrigatório', 422);
            }

            if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
                throw new Exception('Arquivo da fatura é obrigatório (PDF, CSV ou XML)', 422);
            }

            $record = Fatura::where('id', $atributes->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Fatura não encontrada', 404);
            }

            $userId = (int) Auth::id();
            $tipoAnexo = $this->resolveAnexoTipo($atributes->arquivo_pdf);
            $selecao = $this->assertSelecaoBandeiraFinalParaAnexo(
                (int) $record->cartao_id,
                $userId,
                $atributes,
                $tipoAnexo,
                $record
            );

            if ($selecao['bandeira_id'] !== null
                && (int) ($record->cartao_bandeira_id ?? 0) !== $selecao['bandeira_id']
            ) {
                $conflito = Fatura::where('user_id', $userId)
                    ->where('cartao_id', (int) $record->cartao_id)
                    ->where('cartao_bandeira_id', $selecao['bandeira_id'])
                    ->where('mes', (int) $record->mes)
                    ->where('ano', (int) $record->ano)
                    ->where('id', '!=', $record->id)
                    ->exists();
                if ($conflito) {
                    throw new Exception('Já existe fatura para esta bandeira no período informado', 422);
                }
                $record->cartao_bandeira_id = $selecao['bandeira_id'];
                $record->save();
            }

            return $this->attachPdfToFatura(
                $record->fresh(),
                $atributes,
                $userId,
                'PDF enviado com sucesso!',
                $selecao['cartao_numero_id']
            );
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Lista faturas agrupadas por cartão (sem itens de transação).
     * Ordenação: competência (ano/mês desc) → cartão (nome) → status.
     * Paginação é por fatura; a página é reagrupada por cartão na resposta.
     */
    public function getFaturaPaginate(object $atributes): array
    {
        $userId = Auth::id();
        $page = max(1, (int) ($atributes->page ?? 1));
        $perPage = max(1, (int) ($atributes->perPage ?? 5));

        $faturasQuery = DB::table('faturas as ent')
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
            })
            ->leftJoin('cartao_bandeiras as cb', function ($join) {
                $join->on('cb.id', '=', 'ent.cartao_bandeira_id')->whereNull('cb.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', $userId)
            ->select(
                'ent.id',
                'ent.cartao_id',
                'ent.cartao_bandeira_id',
                'ent.pessoa_id',
                'ent.responsavel_id',
                'c.nome as cartao_nome',
                'c.banco as cartao_banco',
                'c.pessoa_id as cartao_pessoa_id',
                'cb.bandeira as cartao_bandeira',
                'c.dia_limite_fatura as cartao_dia_limite_fatura',
                'c.dia_vencimento_fatura as cartao_dia_vencimento_fatura',
                'c.cor_fundo as cartao_cor_fundo',
                'c.cor_texto as cartao_cor_texto',
                'c.ativo as cartao_ativo',
                'ent.mes',
                'ent.ano',
                'ent.valor_total',
                'ent.arquivo_pdf',
                'ent.arquivo_csv',
                'ent.status',
                'ent.erro_mensagem',
                'ent.erro_codigo',
                'c.senha_pdf_regra as cartao_senha_pdf_regra',
                DB::raw('(c.senha_pdf IS NOT NULL) as cartao_tem_senha_pdf'),
                'ent.processado_em',
                'ent.created_at',
                'ent.updated_at',
                DB::raw('(SELECT COUNT(*) FROM transacoes t WHERE t.fatura_id = ent.id AND t.deleted_at IS NULL AND t.user_id = ent.user_id AND t.ignorar_no_total = 0) as total_transacoes'),
                DB::raw('(SELECT COUNT(*) FROM transacoes t
                    WHERE t.fatura_id = ent.id
                        AND t.deleted_at IS NULL
                        AND t.user_id = ent.user_id
                        AND t.categoria_id IS NOT NULL) as transacoes_com_categoria'),
            )
            ->orderByDesc('ent.ano')
            ->orderByDesc('ent.mes')
            ->orderBy('c.nome')
            ->orderBy('ent.status');

        $this->applyFaturaListFilters($faturasQuery, $atributes);

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $faturasQuery,
            $page,
            $perPage,
            ['path' => $atributes->url ?? null, 'query' => $atributes->query ?? []]
        );
        $resultado->appends((array) $atributes);

        $faturas = collect($resultado->items());
        if ($faturas->isEmpty()) {
            return $this->anexarMetaListagem(collect($resultado)->toArray(), $atributes);
        }

        foreach ($faturas as $row) {
            $model = Fatura::where('id', $row->id)->where('user_id', $userId)->first();
            if (!$model) {
                continue;
            }
            $this->ensureResponsavelPadraoFatura($model);
            $this->descartarAnexoOrfaoDoStub($model);
            $model->refresh();
            $row->pessoa_id = $model->pessoa_id;
            $row->responsavel_id = $model->responsavel_id;
            $row->arquivo_pdf = $model->arquivo_pdf;
            $row->arquivo_csv = $model->arquivo_csv;
            $row->status = $model->status;
            $row->processado_em = $model->processado_em;
        }

        $cartaoIds = $faturas->pluck('cartao_id')->unique()->values()->all();
        $cartaoModels = Cartao::whereIn('id', $cartaoIds)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('id');

        $pessoaIds = $faturas->pluck('pessoa_id')
            ->merge($faturas->pluck('cartao_pessoa_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $pessoasById = $pessoaIds === []
            ? collect()
            : Pessoa::where('user_id', $userId)->whereIn('id', $pessoaIds)->get()->keyBy('id');

        $responsavelIds = $faturas->pluck('responsavel_id')->filter()->unique()->values()->all();
        $responsaveisById = $responsavelIds === []
            ? collect()
            : \App\Models\Responsavel::where('user_id', $userId)->whereIn('id', $responsavelIds)->get()->keyBy('id');

        $grupos = [];
        foreach ($faturas as $fatura) {
            $cartaoId = (int) $fatura->cartao_id;

            if (!isset($grupos[$cartaoId])) {
                $cartaoPessoaId = $fatura->cartao_pessoa_id !== null ? (int) $fatura->cartao_pessoa_id : null;
                $cartaoPessoa = $cartaoPessoaId !== null ? $pessoasById->get($cartaoPessoaId) : null;
                $grupos[$cartaoId] = [
                    'cartao_id' => $cartaoId,
                    'nome' => $fatura->cartao_nome,
                    'banco' => $fatura->cartao_banco,
                    'pessoa_id' => $cartaoPessoaId,
                    'pessoa_nome' => $cartaoPessoa?->nomeCompleto(),
                    'dia_limite_fatura' => $fatura->cartao_dia_limite_fatura !== null
                        ? (int) $fatura->cartao_dia_limite_fatura
                        : null,
                    'dia_vencimento_fatura' => $fatura->cartao_dia_vencimento_fatura !== null
                        ? (int) $fatura->cartao_dia_vencimento_fatura
                        : null,
                    'cor_fundo' => $fatura->cartao_cor_fundo,
                    'cor_texto' => $fatura->cartao_cor_texto,
                    'ativo' => (bool) $fatura->cartao_ativo,
                    'total_faturas' => 0,
                    'valor_total' => 0.0,
                    'faturas' => [],
                ];
            }

            $model = $cartaoModels->get($cartaoId);
            $intervalo = $model
                ? $model->intervaloPeriodoFatura((int) $fatura->mes, (int) $fatura->ano)
                : [
                    'periodo_inicio' => null,
                    'periodo_fim' => null,
                    'data_vencimento' => null,
                ];

            $pessoaId = $fatura->pessoa_id !== null ? (int) $fatura->pessoa_id : null;
            $pessoa = $pessoaId !== null ? $pessoasById->get($pessoaId) : null;
            $responsavelId = $fatura->responsavel_id !== null ? (int) $fatura->responsavel_id : null;
            $responsavel = $responsavelId !== null ? $responsaveisById->get($responsavelId) : null;

            $item = array_merge([
                'id' => (int) $fatura->id,
                'pessoa_id' => $pessoaId,
                'pessoa_nome' => $pessoa?->nomeCompleto(),
                'responsavel_id' => $responsavelId,
                'responsavel_nome' => $responsavel?->nome,
                'cartao_bandeira_id' => $fatura->cartao_bandeira_id !== null
                    ? (int) $fatura->cartao_bandeira_id
                    : null,
                'bandeira' => $fatura->cartao_bandeira,
                'mes' => (int) $fatura->mes,
                'ano' => (int) $fatura->ano,
                'competencia' => sprintf('%02d/%d', (int) $fatura->mes, (int) $fatura->ano),
                'periodo_inicio' => $intervalo['periodo_inicio'],
                'periodo_fim' => $intervalo['periodo_fim'],
                'data_vencimento' => $intervalo['data_vencimento'],
                'valor_total' => $fatura->valor_total,
                'status' => $fatura->status,
                'erro_mensagem' => $fatura->erro_mensagem,
                'erro_codigo' => $fatura->erro_codigo,
                'processado_em' => $fatura->processado_em,
                'total_transacoes' => (int) $fatura->total_transacoes,
                'transacoes_com_categoria' => (int) $fatura->transacoes_com_categoria,
                'created_at' => $fatura->created_at,
                'updated_at' => $fatura->updated_at,
                'senha_pdf' => $this->buildSenhaPdfMeta(
                    $fatura->erro_codigo,
                    (int) $fatura->cartao_id,
                    $fatura->cartao_senha_pdf_regra ?? null,
                    (bool) ($fatura->cartao_tem_senha_pdf ?? false)
                ),
                'precisa_senha_pdf' => $this->isSenhaPdfErro($fatura->erro_codigo),
            ], $this->buildAnexoMeta(
                $fatura->arquivo_pdf,
                $fatura->arquivo_csv ?? null,
                (int) $fatura->id
            ));
            $item = $this->anexarPodeRemoverAnexo($item);

            $grupos[$cartaoId]['faturas'][] = $item;
            $grupos[$cartaoId]['total_faturas']++;
            $grupos[$cartaoId]['valor_total'] = round(
                $grupos[$cartaoId]['valor_total'] + (float) $fatura->valor_total,
                2
            );
        }

        $pagamentoById = $this->resolvePagamentoStatusByFaturaIds(
            $faturas->map(fn ($f) => [
                'id' => (int) $f->id,
                'cartao_id' => (int) $f->cartao_id,
                'cartao_bandeira_id' => $f->cartao_bandeira_id !== null
                    ? (int) $f->cartao_bandeira_id
                    : null,
                'mes' => (int) $f->mes,
                'ano' => (int) $f->ano,
                'valor_total' => (float) $f->valor_total,
            ])->all(),
            (int) $userId
        );

        foreach ($grupos as $cartaoId => $grupo) {
            foreach ($grupo['faturas'] as $index => $item) {
                $pagamento = $pagamentoById[$item['id']] ?? ProcessInvoicePdfJob::buildPagamentoStatus(
                    (float) $item['valor_total'],
                    0.0
                );
                $grupos[$cartaoId]['faturas'][$index] = array_merge($item, $pagamento);
            }
        }

        $resultado->setCollection(collect(array_values($grupos)));

        return $this->anexarMetaListagem(collect($resultado)->toArray(), $atributes);
    }

    /**
     * Competência calendário de hoje (mês/ano da listagem "Ir para Mês Atual").
     *
     * @return array{mes: int, ano: int, label: string}
     */
    public static function competenciaAtual(): array
    {
        $agora = Carbon::now();
        $mes = (int) $agora->month;
        $ano = (int) $agora->year;

        return [
            'mes' => $mes,
            'ano' => $ano,
            'label' => sprintf('%02d/%d', $mes, $ano),
        ];
    }

    /**
     * Se `mes_atual` for verdadeiro, preenche mes/ano com a competência de hoje.
     *
     * @return array{mes: ?int, ano: ?int, mes_atual_ativo: bool, competencia_atual: array{mes: int, ano: int, label: string}}
     */
    public static function aplicarFiltroMesAtual(object $atributes): array
    {
        $competencia = self::competenciaAtual();
        $querMesAtual = filter_var($atributes->mes_atual ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($querMesAtual) {
            $atributes->mes = $competencia['mes'];
            $atributes->ano = $competencia['ano'];
        }

        $mes = !empty($atributes->mes) ? (int) $atributes->mes : null;
        $ano = !empty($atributes->ano) ? (int) $atributes->ano : null;

        return [
            'mes' => $mes,
            'ano' => $ano,
            'mes_atual_ativo' => $mes === $competencia['mes'] && $ano === $competencia['ano'],
            'competencia_atual' => $competencia,
        ];
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function anexarMetaListagem(array $resultado, object $atributes): array
    {
        $filtros = self::aplicarFiltroMesAtual($atributes);
        $resultado['competencia_atual'] = $filtros['competencia_atual'];
        $resultado['filtros'] = [
            'mes' => $filtros['mes'],
            'ano' => $filtros['ano'],
            'mes_atual_ativo' => $filtros['mes_atual_ativo'],
        ];

        return $resultado;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function anosLookupsFatura(int $userId): array
    {
        $anoAtual = (int) Carbon::now()->year;
        $anosDb = Fatura::query()
            ->where('user_id', $userId)
            ->distinct()
            ->orderByDesc('ano')
            ->pluck('ano')
            ->map(fn ($ano) => (int) $ano)
            ->all();

        $anos = array_values(array_unique(array_merge(
            [$anoAtual - 1, $anoAtual, $anoAtual + 1],
            $anosDb
        )));
        rsort($anos, SORT_NUMERIC);

        return collect($anos)->map(fn (int $ano) => [
            'value' => $ano,
            'label' => (string) $ano,
        ])->values()->all();
    }

    private function applyFaturaListFilters($query, object $atributes): void
    {
        self::aplicarFiltroMesAtual($atributes);

        if (!empty($atributes->cartao_id)) {
            $query->where('ent.cartao_id', $atributes->cartao_id);
        }

        if (!empty($atributes->cartao_bandeira_id)) {
            $query->where('ent.cartao_bandeira_id', $atributes->cartao_bandeira_id);
        }

        if (!empty($atributes->mes)) {
            $query->where('ent.mes', (int) $atributes->mes);
        }

        if (!empty($atributes->ano)) {
            $query->where('ent.ano', (int) $atributes->ano);
        }

        if (!empty($atributes->status)) {
            $query->where('ent.status', $atributes->status);
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('c.nome', 'like', '%' . $chave . '%')
                    ->orWhere('c.banco', 'like', '%' . $chave . '%')
                    ->orWhere('ent.status', 'like', '%' . $chave . '%');
            });
        }

        if (!empty($atributes->pessoa_id)) {
            $query->where('ent.pessoa_id', (int) $atributes->pessoa_id);
        }
    }

    public function getFaturaId(int|string $id): array
    {
        try {
            $query = DB::table('faturas as ent')
                ->leftJoin('cartoes as c', function ($join) {
                    $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
                })
                ->leftJoin('cartao_bandeiras as cb', function ($join) {
                    $join->on('cb.id', '=', 'ent.cartao_bandeira_id')->whereNull('cb.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.cartao_id',
                    'ent.cartao_bandeira_id',
                    'c.nome as cartao_nome',
                    'cb.bandeira as cartao_bandeira',
                    'c.cor_fundo as cartao_cor_fundo',
                    'c.cor_texto as cartao_cor_texto',
                    'c.dia_limite_fatura as cartao_dia_limite_fatura',
                    'c.dia_vencimento_fatura as cartao_dia_vencimento_fatura',
                    'ent.pessoa_id',
                    'ent.responsavel_id',
                    'ent.mes',
                    'ent.ano',
                    'ent.valor_total',
                    'ent.arquivo_pdf',
                    'ent.arquivo_csv',
                    'ent.status',
                    'ent.erro_mensagem',
                    'ent.erro_codigo',
                    'c.senha_pdf_regra as cartao_senha_pdf_regra',
                    DB::raw('(c.senha_pdf IS NOT NULL) as cartao_tem_senha_pdf'),
                    'ent.processado_em',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Fatura não encontrada', 404);
            }

            $result = collect($data)->toArray();
            $result['mes'] = (int) $result['mes'];
            $result['ano'] = (int) $result['ano'];
            $result['pessoa_id'] = $result['pessoa_id'] !== null ? (int) $result['pessoa_id'] : null;
            $result['responsavel_id'] = $result['responsavel_id'] !== null ? (int) $result['responsavel_id'] : null;

            $faturaModel = Fatura::where('id', $id)->where('user_id', Auth::id())->first();
            if ($faturaModel) {
                $this->ensureResponsavelPadraoFatura($faturaModel);
                $this->descartarAnexoOrfaoDoStub($faturaModel);
                $faturaModel->refresh();
                $result['pessoa_id'] = $faturaModel->pessoa_id !== null ? (int) $faturaModel->pessoa_id : null;
                $result['responsavel_id'] = $faturaModel->responsavel_id !== null ? (int) $faturaModel->responsavel_id : null;
                $result['arquivo_pdf'] = $faturaModel->arquivo_pdf;
                $result['arquivo_csv'] = $faturaModel->arquivo_csv;
                $result['status'] = $faturaModel->status;
                $result['processado_em'] = $faturaModel->processado_em;
            }

            $pessoa = $result['pessoa_id']
                ? Pessoa::where('id', $result['pessoa_id'])->where('user_id', Auth::id())->first()
                : null;
            $responsavel = $result['responsavel_id']
                ? \App\Models\Responsavel::where('id', $result['responsavel_id'])->where('user_id', Auth::id())->first()
                : null;
            $result['pessoa_nome'] = $pessoa?->nomeCompleto();
            $result['responsavel_nome'] = $responsavel?->nome;
            $result['competencia'] = sprintf('%02d/%d', $result['mes'], $result['ano']);

            $cartao = Cartao::where('id', $result['cartao_id'])
                ->where('user_id', Auth::id())
                ->first();
            $intervalo = $cartao
                ? $cartao->intervaloPeriodoFatura($result['mes'], $result['ano'])
                : [
                    'periodo_inicio' => null,
                    'periodo_fim' => null,
                    'data_vencimento' => null,
                ];

            $result['periodo_inicio'] = $intervalo['periodo_inicio'];
            $result['periodo_fim'] = $intervalo['periodo_fim'];
            $result['data_vencimento'] = $intervalo['data_vencimento'];
            $result['total_transacoes'] = DB::table('transacoes')
                ->where('fatura_id', $id)
                ->where('user_id', Auth::id())
                ->whereNull('deleted_at')
                ->where('ignorar_no_total', false)
                ->count();
            $result['transacoes_com_categoria'] = DB::table('transacoes as t')
                ->where('t.fatura_id', $id)
                ->where('t.user_id', Auth::id())
                ->whereNull('t.deleted_at')
                ->whereNotNull('t.categoria_id')
                ->count();
            $result = array_merge($result, $this->buildAnexoMeta(
                $result['arquivo_pdf'] ?? null,
                $result['arquivo_csv'] ?? null,
                (int) $id
            ));
            $result = $this->anexarPodeRemoverAnexo($result);
            $result['senha_pdf'] = $this->buildSenhaPdfMeta(
                $result['erro_codigo'] ?? null,
                (int) $result['cartao_id'],
                $result['cartao_senha_pdf_regra'] ?? null,
                (bool) ($result['cartao_tem_senha_pdf'] ?? false)
            );
            $result['precisa_senha_pdf'] = $this->isSenhaPdfErro($result['erro_codigo'] ?? null);
            unset($result['cartao_senha_pdf_regra'], $result['cartao_tem_senha_pdf']);
            $result['grupos_por_cartao'] = $this->buildGruposPorCartao((int) $id);

            $faturaId = (int) $result['id'];
            $pagamentoById = $this->resolvePagamentoStatusByFaturaIds(
                [[
                    'id' => $faturaId,
                    'cartao_id' => (int) $result['cartao_id'],
                    'cartao_bandeira_id' => $result['cartao_bandeira_id'] !== null
                        ? (int) $result['cartao_bandeira_id']
                        : null,
                    'mes' => (int) $result['mes'],
                    'ano' => (int) $result['ano'],
                    'valor_total' => (float) $result['valor_total'],
                ]],
                (int) Auth::id()
            );
            $pagamento = $pagamentoById[$faturaId]
                ?? ProcessInvoicePdfJob::buildPagamentoStatus((float) $result['valor_total'], 0.0);
            $result = array_merge($result, $pagamento);

            $pagamentosTotal = $this->sumPagamentosByFaturaIds([$faturaId])[$faturaId] ?? 0.0;
            $faturaModel = Fatura::find($faturaId);
            $previousTotal = $faturaModel
                ? ProcessInvoicePdfJob::resolvePreviousFaturaTotal($faturaModel)
                : null;
            $paymentTxs = Transacao::where('fatura_id', $faturaId)
                ->where('user_id', Auth::id())
                ->where('tipo', Transacao::TIPO_PAYMENT)
                ->get(['valor', 'tipo', 'data'])
                ->map(fn (Transacao $t) => [
                    'valor' => (float) $t->valor,
                    'tipo' => $t->tipo,
                    'data' => $t->data?->toDateString(),
                ])
                ->all();
            $alocacao = ProcessInvoicePdfJob::allocatePaymentsFromTransactions(
                $paymentTxs,
                $previousTotal,
                $faturaModel
                    ? ProcessInvoicePdfJob::competenciaInicio((int) $faturaModel->mes, (int) $faturaModel->ano)
                    : null
            );
            $result['pagamentos_total'] = round($pagamentosTotal, 2);
            $result['pagamentos_abatido_anterior'] = $alocacao['applied_to_previous'];
            $result['pagamentos_antecipado'] = $alocacao['applied_to_current'];

            if ($faturaModel) {
                $result = array_merge($result, $this->buildTotaisConciliacao($faturaModel));
            }

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function downloadPdf(int|string $id)
    {
        return $this->downloadAnexo($id, 'pdf');
    }

    public function downloadCsv(int|string $id)
    {
        return $this->downloadAnexo($id, 'csv');
    }

    /**
     * @param  'pdf'|'csv'  $tipo
     */
    private function downloadAnexo(int|string $id, string $tipo): string
    {
        $fatura = Fatura::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$fatura) {
            throw new Exception('Fatura não encontrada', 404);
        }

        $relative = $tipo === 'pdf' ? $fatura->arquivo_pdf : $fatura->arquivo_csv;
        $label = $tipo === 'pdf' ? 'PDF' : 'CSV';

        if (!Fatura::isOwnedStoragePath($relative, (int) Auth::id())) {
            throw new Exception("Arquivo {$label} não encontrado", 404);
        }

        if (!$relative || !Storage::disk('local')->exists($relative)) {
            throw new Exception("Arquivo {$label} não encontrado", 404);
        }

        return Storage::disk('local')->path($relative);
    }

    public function getFaturaAsync(object $params): array
    {
        $query = DB::table('faturas as ent')
            ->leftJoin('cartoes as c', function ($join) {
                $join->on('c.id', '=', 'ent.cartao_id')->whereNull('c.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select(
                'ent.id',
                DB::raw("CONCAT(c.nome, ' - ', LPAD(ent.mes, 2, '0'), '/', ent.ano) as nome"),
                'ent.mes',
                'ent.ano',
                'ent.status'
            );

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('c.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.ano', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->orderByDesc('ent.ano')->orderByDesc('ent.mes')->get()->toArray();
    }

    /**
     * Extrato da fatura (PDF / lançamentos automáticos) + soma das compras manuais
     * ainda não conciliadas. O front usa no detalhe: o total exibido soma os dois
     * e o aviso só aparece enquanto houver pendência.
     *
     * @return array{
     *     valor_extrato: float,
     *     valor_nao_conciliado: float,
     *     valor_total_com_pendencias: float,
     *     tem_compras_nao_conciliadas: bool,
     *     compras_nao_conciliadas_label: ?string
     * }
     */
    public function buildTotaisConciliacao(Fatura $fatura): array
    {
        $extratoTxs = Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->where(function ($q) {
                $q->where('compra_manual', false)
                    ->orWhere('tipo', '!=', Transacao::TIPO_PURCHASE);
            })
            ->get(['valor', 'tipo', 'data'])
            ->map(fn (Transacao $t) => [
                'valor' => (float) $t->valor,
                'tipo' => $t->tipo,
                'data' => $t->data?->toDateString(),
            ])
            ->all();

        // Extrato = o que o PDF descreve (compras/encargos/estornos). Pagamentos e
        // residual da anterior entram só em valor_total (quitação).
        $valorExtrato = ProcessInvoicePdfJob::calculateValorExtrato($extratoTxs);

        $valorNaoConciliado = (float) Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->where('tipo', Transacao::TIPO_PURCHASE)
            ->where('compra_manual', true)
            ->whereIn('status_conciliacao', [
                Transacao::CONCILIACAO_NAO_CONCILIADA,
                Transacao::CONCILIACAO_PENDENTE,
            ])
            ->sum('valor');

        return Fatura::totaisConciliacaoPayload($valorExtrato, $valorNaoConciliado);
    }

    /**
     * Recalcula valor_total a partir das transações da fatura (compras manuais e PDF).
     * Usa a mesma regra de saldo do ProcessInvoicePdfJob (pagamentos, estornos, residual).
     */
    public function recalculateValorTotal(int $faturaId): float
    {
        $fatura = Fatura::find($faturaId);
        if (!$fatura) {
            return 0.0;
        }

        $transactions = Transacao::where('fatura_id', $faturaId)
            ->where('user_id', $fatura->user_id)
            ->where('ignorar_no_total', false)
            ->get(['valor', 'tipo', 'data'])
            ->map(fn (Transacao $t) => [
                'valor' => (float) $t->valor,
                'tipo' => $t->tipo,
                'data' => $t->data?->toDateString(),
            ])
            ->all();

        // Residual da fatura anterior só no fechamento do extrato (PDF/processada),
        // e apenas se a anterior também estiver processada (não stub de parcela).
        // Faturas pendentes (compras manuais) refletem só o saldo do ciclo.
        $previousTotal = $fatura->status === 'processada'
            ? ProcessInvoicePdfJob::resolvePreviousFaturaTotal($fatura)
            : null;

        $valorTotal = ProcessInvoicePdfJob::calculateValorTotal(
            $transactions,
            $previousTotal,
            ProcessInvoicePdfJob::competenciaInicio((int) $fatura->mes, (int) $fatura->ano)
        );

        $fatura->update(['valor_total' => $valorTotal]);

        return $valorTotal;
    }

    /**
     * Agrupa transações da fatura por final do cartão (para a view de detalhe).
     *
     * @return list<array<string, mixed>>
     */
    private function buildGruposPorCartao(int $faturaId): array
    {
        $rows = DB::table('transacoes as t')
            ->leftJoin('cartao_numeros as cn', function ($join) {
                $join->on('cn.id', '=', 't.cartao_numero_id')->whereNull('cn.deleted_at');
            })
            ->where('t.fatura_id', $faturaId)
            ->where('t.user_id', Auth::id())
            ->whereNull('t.deleted_at')
            ->where('t.ignorar_no_total', false)
            ->where(function ($q) {
                $q->whereNotNull('cn.ultimos_digitos')
                    ->orWhere('t.tipo', Transacao::TIPO_PURCHASE);
            })
            ->select(
                'cn.id as cartao_numero_id',
                'cn.ultimos_digitos',
                'cn.tipo',
                'cn.apelido',
                'cn.nome_no_cartao',
                DB::raw('COUNT(*) as total_transacoes'),
                DB::raw('COALESCE(SUM(t.valor), 0) as valor_total')
            )
            ->groupBy('cn.id', 'cn.ultimos_digitos', 'cn.tipo', 'cn.apelido', 'cn.nome_no_cartao')
            ->orderByRaw('cn.ultimos_digitos IS NULL')
            ->orderBy('cn.ultimos_digitos')
            ->get();

        return $rows->map(function ($row) {
            $ultimos = $row->ultimos_digitos;
            if ($ultimos) {
                $label = '•••• ' . $ultimos;
                if (!empty($row->nome_no_cartao)) {
                    $label .= ' · ' . $row->nome_no_cartao;
                } elseif (!empty($row->apelido)) {
                    $label .= ' · ' . $row->apelido;
                }
                $grupoChave = Transacao::GRUPO_CARTAO;
            } else {
                $label = Transacao::GRUPO_PAGAMENTOS_FINANCIAMENTOS_LABEL;
                $grupoChave = Transacao::GRUPO_PAGAMENTOS_FINANCIAMENTOS;
            }

            return [
                'cartao_numero_id' => $row->cartao_numero_id !== null ? (int) $row->cartao_numero_id : null,
                'ultimos_digitos' => $ultimos,
                'tipo' => $row->tipo,
                'apelido' => $row->apelido,
                'nome_no_cartao' => $row->nome_no_cartao,
                'grupo_chave' => $grupoChave,
                'label' => $label,
                'total_transacoes' => (int) $row->total_transacoes,
                'valor_total' => round((float) $row->valor_total, 2),
            ];
        })->all();
    }

    /**
     * @param array<int, int|string|null> $faturaIds
     */
    public function recalculateValorTotalMany(array $faturaIds): void
    {
        foreach (array_unique(array_filter($faturaIds)) as $faturaId) {
            $this->recalculateValorTotal((int) $faturaId);
        }
    }

    /**
     * Para cada fatura, calcula quitação com os pagamentos da competência seguinte
     * (mesma bandeira; fallback para o grupo do cartão).
     *
     * @param  list<array{id:int,cartao_id:int,cartao_bandeira_id:?int,mes:int,ano:int,valor_total:float}>  $faturas
     * @return array<int, array{pago: bool, valor_pago: float, valor_restante: float}>
     */
    public function pagamentoStatusPorFaturas(array $faturas, int $userId): array
    {
        return $this->resolvePagamentoStatusByFaturaIds($faturas, $userId);
    }

    /**
     * @param  list<array{id:int,cartao_id:int,cartao_bandeira_id:?int,mes:int,ano:int,valor_total:float}>  $faturas
     * @return array<int, array{pago: bool, valor_pago: float, valor_restante: float}>
     */
    private function resolvePagamentoStatusByFaturaIds(array $faturas, int $userId): array
    {
        if ($faturas === []) {
            return [];
        }

        $nextKeys = [];
        foreach ($faturas as $fatura) {
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura['mes'],
                (int) $fatura['ano']
            );
            $scopeKey = $fatura['cartao_bandeira_id'] !== null
                ? 'b:' . $fatura['cartao_bandeira_id']
                : 'c:' . $fatura['cartao_id'];
            $nextKeys[$scopeKey . ':' . $nextMes . ':' . $nextAno] = [
                'cartao_id' => (int) $fatura['cartao_id'],
                'cartao_bandeira_id' => $fatura['cartao_bandeira_id'],
                'mes' => $nextMes,
                'ano' => $nextAno,
            ];
        }

        $nextFaturasQuery = Fatura::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($nextKeys) {
                foreach ($nextKeys as $next) {
                    $query->orWhere(function ($inner) use ($next) {
                        $inner->where('mes', $next['mes'])->where('ano', $next['ano']);
                        if ($next['cartao_bandeira_id'] !== null) {
                            $inner->where('cartao_bandeira_id', $next['cartao_bandeira_id']);
                        } else {
                            $inner->where('cartao_id', $next['cartao_id']);
                        }
                    });
                }
            });

        $nextFaturas = $nextFaturasQuery->get(['id', 'cartao_id', 'cartao_bandeira_id', 'mes', 'ano']);
        $nextIdByKey = [];
        foreach ($nextFaturas as $next) {
            $scopeKey = $next->cartao_bandeira_id !== null
                ? 'b:' . $next->cartao_bandeira_id
                : 'c:' . $next->cartao_id;
            $nextIdByKey[$scopeKey . ':' . $next->mes . ':' . $next->ano] = (int) $next->id;
        }

        $paymentSums = $this->sumPagamentosByFaturaIds($nextFaturas->pluck('id')->all());

        $result = [];
        foreach ($faturas as $fatura) {
            [$nextMes, $nextAno] = ProcessInvoicePdfJob::nextCompetencia(
                (int) $fatura['mes'],
                (int) $fatura['ano']
            );
            $scopeKey = $fatura['cartao_bandeira_id'] !== null
                ? 'b:' . $fatura['cartao_bandeira_id']
                : 'c:' . $fatura['cartao_id'];
            $nextId = $nextIdByKey[$scopeKey . ':' . $nextMes . ':' . $nextAno] ?? null;
            $pagamentosNext = $nextId !== null ? ($paymentSums[$nextId] ?? 0.0) : 0.0;

            $result[(int) $fatura['id']] = ProcessInvoicePdfJob::buildPagamentoStatus(
                (float) $fatura['valor_total'],
                $pagamentosNext
            );
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $faturaIds
     * @return array<int, float>
     */
    private function sumPagamentosByFaturaIds(array $faturaIds): array
    {
        $faturaIds = array_values(array_unique(array_filter(array_map('intval', $faturaIds))));
        if ($faturaIds === []) {
            return [];
        }

        return DB::table('transacoes')
            ->whereIn('fatura_id', $faturaIds)
            ->where('user_id', Auth::id())
            ->where('tipo', Transacao::TIPO_PAYMENT)
            ->whereNull('deleted_at')
            ->groupBy('fatura_id')
            ->selectRaw('fatura_id, COALESCE(SUM(valor), 0) as total')
            ->pluck('total', 'fatura_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * Localiza fatura da bandeira no período ou cria (status pendente).
     * Usado no cadastro de compra via cartao_id + data.
     */
    public function findOrCreateByCartaoPeriodo(
        int $userId,
        int $cartaoId,
        int $mes,
        int $ano,
        ?int $cartaoBandeiraId = null
    ): Fatura {
        $this->assertCartaoDoUsuario($cartaoId, $userId);
        $bandeiraId = $this->resolveCartaoBandeiraId($cartaoId, $userId, $cartaoBandeiraId);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mês inválido', 422);
        }

        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }

        $faturaQuery = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->where('cartao_id', $cartaoId)
            ->where('mes', $mes)
            ->where('ano', $ano);
        if ($bandeiraId !== null) {
            $faturaQuery->where('cartao_bandeira_id', $bandeiraId);
        } else {
            $faturaQuery->whereNull('cartao_bandeira_id');
        }
        $fatura = $faturaQuery->first();

        if ($fatura) {
            if ($fatura->trashed()) {
                $fatura->restore();
                // Stub de parcela / competência vizinha: não herda PDF nem "processada"
                // da fatura apagada (ex.: importar agosto restaurava julho com ícone de PDF).
                $fatura->fill(self::atributosStubSemAnexo());
                $fatura->save();
            }

            if ($fatura->pessoa_id === null || $fatura->responsavel_id === null) {
                $this->aplicarPessoaResponsavelDoCartao($fatura, $cartaoId, $userId);
            }

            return $fatura->fresh();
        }

        $pessoaId = Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id');
        $pessoaId = $pessoaId !== null ? (int) $pessoaId : null;
        $responsavelId = $pessoaId !== null
            ? $this->resolveResponsavelIdParaPessoa($pessoaId, $userId)
            : null;

        return Fatura::create([
            'user_id' => $userId,
            'pessoa_id' => $pessoaId,
            'responsavel_id' => $responsavelId,
            'cartao_id' => $cartaoId,
            'cartao_bandeira_id' => $bandeiraId,
            'mes' => $mes,
            'ano' => $ano,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
    }

    /**
     * Resolve a bandeira da fatura.
     * Se não informada e o cartão tiver exatamente uma bandeira ativa, usa essa.
     * Se o cartão não tiver bandeiras, retorna null (cartao_bandeira_id é opcional).
     */
    public function resolveCartaoBandeiraId(int $cartaoId, int $userId, mixed $bandeiraId = null): ?int
    {
        $this->assertCartaoDoUsuario($cartaoId, $userId);

        if (!empty($bandeiraId)) {
            $exists = CartaoBandeira::where('id', $bandeiraId)
                ->where('cartao_id', $cartaoId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$exists) {
                throw new Exception('Bandeira inválida para este cartão', 422);
            }

            return (int) $bandeiraId;
        }

        $bandeiras = CartaoBandeira::where('cartao_id', $cartaoId)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->orderBy('id')
            ->get(['id']);

        if ($bandeiras->isEmpty()) {
            return null;
        }

        if ($bandeiras->count() > 1) {
            throw new Exception('Selecione a bandeira da fatura', 422);
        }

        return (int) $bandeiras->first()->id;
    }

    /**
     * Quando o cartão não tem finais ativos e há upload PDF/CSV, exige seleção
     * de bandeira (modal). No CSV sem PDF vinculado, exige também o final.
     *
     * @return array{bandeira_id: int|null, cartao_numero_id: int|null}
     */
    private function assertSelecaoBandeiraFinalParaAnexo(
        int $cartaoId,
        int $userId,
        object $atributes,
        string $tipoAnexo,
        ?Fatura $faturaExistente = null
    ): array {
        if ($this->cartaoTemFinaisAtivos($cartaoId)) {
            $bandeiraId = $this->resolveCartaoBandeiraId(
                $cartaoId,
                $userId,
                $atributes->cartao_bandeira_id ?? ($faturaExistente?->cartao_bandeira_id)
            );

            return [
                'bandeira_id' => $bandeiraId,
                'cartao_numero_id' => null,
            ];
        }

        $bandeiraId = $this->resolveBandeiraParaModal(
            $cartaoId,
            $userId,
            $atributes,
            $faturaExistente
        );

        $cartaoNumeroId = null;
        if ($tipoAnexo === 'csv') {
            $temPdfVinculado = $faturaExistente !== null && !empty($faturaExistente->arquivo_pdf);
            if (!$temPdfVinculado) {
                $cartaoNumeroId = $this->resolveCartaoNumeroParaModal(
                    $bandeiraId,
                    $userId,
                    $atributes
                );
            }
        }

        return [
            'bandeira_id' => $bandeiraId,
            'cartao_numero_id' => $cartaoNumeroId,
        ];
    }

    private function cartaoTemFinaisAtivos(int $cartaoId): bool
    {
        return CartaoNumero::query()
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->whereHas('bandeira', function ($q) use ($cartaoId) {
                $q->where('cartao_id', $cartaoId)
                    ->whereNull('deleted_at')
                    ->where('ativo', true);
            })
            ->exists();
    }

    private function resolveBandeiraParaModal(
        int $cartaoId,
        int $userId,
        object $atributes,
        ?Fatura $faturaExistente = null
    ): int {
        if (!empty($atributes->cartao_bandeira_id)) {
            return (int) $this->resolveCartaoBandeiraId(
                $cartaoId,
                $userId,
                $atributes->cartao_bandeira_id
            );
        }

        if (!empty($atributes->bandeira)) {
            return $this->findOrCreateBandeiraByNome($cartaoId, trim((string) $atributes->bandeira));
        }

        if ($faturaExistente?->cartao_bandeira_id) {
            return (int) $faturaExistente->cartao_bandeira_id;
        }

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_BANDEIRA,
            [
                'precisa_selecionar_bandeira' => true,
                'bandeiras' => $this->buildBandeirasModalOptions($cartaoId),
            ],
            'Selecione a bandeira da fatura'
        );
    }

    /**
     * @return list<array{value: int|null, label: string, qtd_numeros?: int, criar?: bool}>
     */
    private function buildBandeirasModalOptions(int $cartaoId): array
    {
        $existentes = CartaoBandeira::query()
            ->where('cartao_id', $cartaoId)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->withCount(['numeros' => function ($q) {
                $q->whereNull('deleted_at')->where('ativo', true);
            }])
            ->orderBy('bandeira')
            ->get();

        if ($existentes->isNotEmpty()) {
            return $existentes->map(fn (CartaoBandeira $b) => array_merge([
                'value' => (int) $b->id,
                'label' => $b->bandeira,
                'qtd_numeros' => (int) $b->numeros_count,
            ], BandeiraCoresPreset::anexar($b->bandeira, $b->cor_principal, $b->cor_secundaria)))->values()->all();
        }

        return collect(BandeiraCoresPreset::paresParaLookups())->map(fn (array $preset) => array_merge([
            'value' => null,
            'label' => $preset['label'],
            'criar' => true,
        ], BandeiraCoresPreset::anexar($preset['label'])))->values()->all();
    }

    private function findOrCreateBandeiraByNome(int $cartaoId, string $nome): int
    {
        if (!BandeiraCoresPreset::isValida($nome)) {
            throw new Exception('Bandeira inválida. Use: ' . implode(', ', BandeiraCoresPreset::nomesLookups()), 422);
        }

        $bandeira = CartaoBandeira::withTrashed()
            ->where('cartao_id', $cartaoId)
            ->where('bandeira', $nome)
            ->first();

        if ($bandeira) {
            if ($bandeira->trashed()) {
                $bandeira->restore();
            }
            if (!$bandeira->ativo) {
                $bandeira->ativo = true;
                $bandeira->save();
            }

            return (int) $bandeira->id;
        }

        $bandeira = CartaoBandeira::create([
            'cartao_id' => $cartaoId,
            'bandeira' => $nome,
            'ativo' => true,
        ]);

        return (int) $bandeira->id;
    }

    private function resolveCartaoNumeroParaModal(
        int $bandeiraId,
        int $userId,
        object $atributes
    ): int {
        if (!empty($atributes->cartao_numero_id)) {
            return $this->assertCartaoNumeroDaBandeira(
                (int) $atributes->cartao_numero_id,
                $bandeiraId,
                $userId
            );
        }

        $digitos = isset($atributes->ultimos_digitos)
            ? trim((string) $atributes->ultimos_digitos)
            : '';

        if ($digitos !== '') {
            if (!preg_match('/^\d{4}$/', $digitos)) {
                throw new Exception('Final do cartão deve ter 4 dígitos', 422);
            }

            return $this->findOrCreateCartaoNumero($bandeiraId, $digitos);
        }

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_FINAL,
            [
                'precisa_selecionar_final' => true,
                'cartao_bandeira_id' => $bandeiraId,
                'numeros' => $this->buildNumerosModalOptions($bandeiraId),
            ],
            'Selecione o final do cartão'
        );
    }

    /**
     * @return list<array{value: int, label: string, ultimos_digitos: string}>
     */
    private function buildNumerosModalOptions(int $bandeiraId): array
    {
        return CartaoNumero::query()
            ->where('cartao_bandeira_id', $bandeiraId)
            ->whereNull('deleted_at')
            ->where('ativo', true)
            ->orderBy('ultimos_digitos')
            ->get()
            ->map(fn (CartaoNumero $n) => [
                'value' => (int) $n->id,
                'label' => '•••• ' . $n->ultimos_digitos,
                'ultimos_digitos' => $n->ultimos_digitos,
            ])
            ->values()
            ->all();
    }

    private function assertCartaoNumeroDaBandeira(int $numeroId, int $bandeiraId, int $userId): int
    {
        $numero = CartaoNumero::query()
            ->where('id', $numeroId)
            ->whereNull('deleted_at')
            ->whereHas('bandeira', function ($q) use ($bandeiraId, $userId) {
                $q->where('id', $bandeiraId)
                    ->whereNull('deleted_at')
                    ->whereHas('cartao', function ($c) use ($userId) {
                        $c->where('user_id', $userId)->whereNull('deleted_at');
                    });
            })
            ->first();

        if (!$numero) {
            throw new Exception('Final do cartão inválido para esta bandeira', 422);
        }

        return (int) $numero->id;
    }

    private function findOrCreateCartaoNumero(int $bandeiraId, string $digitos): int
    {
        $numero = CartaoNumero::withTrashed()
            ->where('cartao_bandeira_id', $bandeiraId)
            ->where('ultimos_digitos', $digitos)
            ->first();

        if ($numero) {
            if ($numero->trashed()) {
                $numero->restore();
            }
            if (!$numero->ativo) {
                $numero->ativo = true;
                $numero->save();
            }

            return (int) $numero->id;
        }

        $numero = CartaoNumero::create([
            'cartao_bandeira_id' => $bandeiraId,
            'ultimos_digitos' => $digitos,
            'ativo' => true,
        ]);

        return (int) $numero->id;
    }

    /**
     * Se o arquivo tem competência clara (mês+ano no texto) diferente da fatura
     * clicada/confirmada, anexa na fatura certa (ex.: PDF 07/2024 não vai para 07/2026).
     */
    public static function periodoDetectadoDiverge(?int $mesAnexo, ?int $anoAnexo, int $mesFatura, int $anoFatura): bool
    {
        if ($mesAnexo === null || $anoAnexo === null) {
            return false;
        }

        if ($mesAnexo < 1 || $mesAnexo > 12 || $anoAnexo < 2000 || $anoAnexo > 2100) {
            return false;
        }

        return $mesAnexo !== $mesFatura || $anoAnexo !== $anoFatura;
    }

    /**
     * @return array{mes: int, ano: int}|null
     */
    private function detectarPeriodoDoArquivo(object $atributes): ?array
    {
        if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
            return null;
        }

        try {
            $parsed = (new InvoicePdfParserService())->parseUploadedFile(
                $atributes->arquivo_pdf,
                $this->extractSenhaPdfFromRequest($atributes)
            );
        } catch (PdfPasswordException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Não foi possível ler competência do anexo antes de vincular', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $metadata = $parsed['metadata'] ?? [];
        $mes = isset($metadata['mes']) ? (int) $metadata['mes'] : 0;
        $ano = isset($metadata['ano']) ? (int) $metadata['ano'] : 0;
        if ($mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
            return null;
        }

        $text = (string) ($parsed['text'] ?? '');
        if ($text !== '' && !preg_match('/\b' . preg_quote((string) $ano, '/') . '\b/', $text)) {
            return null;
        }

        return ['mes' => $mes, 'ano' => $ano];
    }

    private function aplicarPeriodoDetectadoDoAnexo(object $atributes): void
    {
        $periodo = $this->detectarPeriodoDoArquivo($atributes);
        if ($periodo === null) {
            return;
        }

        $mesAtual = (int) ($atributes->mes ?? 0);
        $anoAtual = (int) ($atributes->ano ?? 0);
        if (!self::periodoDetectadoDiverge($periodo['mes'], $periodo['ano'], $mesAtual, $anoAtual)) {
            return;
        }

        Log::info('Competência do anexo difere da informada; usando a do arquivo', [
            'informado' => sprintf('%02d/%d', $mesAtual, $anoAtual),
            'arquivo' => sprintf('%02d/%d', $periodo['mes'], $periodo['ano']),
        ]);

        $atributes->mes = $periodo['mes'];
        $atributes->ano = $periodo['ano'];
    }

    private function faturaAlvoPeloPeriodoDoAnexo(Fatura $fatura, object $atributes, int $userId): Fatura
    {
        $periodo = $this->detectarPeriodoDoArquivo($atributes);
        if ($periodo === null) {
            return $fatura;
        }

        if (!self::periodoDetectadoDiverge(
            $periodo['mes'],
            $periodo['ano'],
            (int) $fatura->mes,
            (int) $fatura->ano
        )) {
            return $fatura;
        }

        $alvo = $this->findOrCreateByCartaoPeriodo(
            $userId,
            (int) $fatura->cartao_id,
            $periodo['mes'],
            $periodo['ano'],
            $fatura->cartao_bandeira_id !== null ? (int) $fatura->cartao_bandeira_id : null
        );

        if ((int) $alvo->id === (int) $fatura->id) {
            return $fatura;
        }

        $jaTemAnexo = !empty($alvo->arquivo_pdf) || !empty($alvo->arquivo_csv);
        if ($jaTemAnexo) {
            throw new Exception(
                sprintf(
                    'Este arquivo é da competência %02d/%d, que já possui anexo. Remova o anexo de lá antes de enviar de novo.',
                    $periodo['mes'],
                    $periodo['ano']
                ),
                422
            );
        }

        Log::info('Anexo será vinculado na competência do arquivo, não na fatura clicada', [
            'fatura_clicada' => $fatura->id,
            'fatura_alvo' => $alvo->id,
            'competencia' => sprintf('%02d/%d', $periodo['mes'], $periodo['ano']),
        ]);

        return $alvo;
    }

    /**
     * Move o anexo para a fatura da competência lida no PDF, se divergir.
     * Usado no job (arquivo já gravado na fatura errada).
     *
     * @param  array<string, mixed>  $parsed
     */
    public function realocarAnexoSeCompetenciaDivergir(Fatura $fatura, array $parsed): Fatura
    {
        $metadata = $parsed['metadata'] ?? [];
        $mes = isset($metadata['mes']) ? (int) $metadata['mes'] : 0;
        $ano = isset($metadata['ano']) ? (int) $metadata['ano'] : 0;
        if (!self::periodoDetectadoDiverge($mes, $ano, (int) $fatura->mes, (int) $fatura->ano)) {
            return $fatura;
        }

        $text = (string) ($parsed['text'] ?? '');
        if ($text !== '' && !preg_match('/\b' . preg_quote((string) $ano, '/') . '\b/', $text)) {
            return $fatura;
        }

        $alvo = $this->findOrCreateByCartaoPeriodo(
            (int) $fatura->user_id,
            (int) $fatura->cartao_id,
            $mes,
            $ano,
            $fatura->cartao_bandeira_id !== null ? (int) $fatura->cartao_bandeira_id : null
        );

        if ((int) $alvo->id === (int) $fatura->id) {
            return $fatura;
        }

        $jaTemImportados = Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->where('importada_pdf', true)
            ->whereNull('deleted_at')
            ->exists();
        if ($jaTemImportados) {
            Log::warning('Anexo na competência errada, mas a fatura já tem extrato importado; use remover/trocar PDF', [
                'fatura_id' => $fatura->id,
                'competencia_fatura' => sprintf('%02d/%d', (int) $fatura->mes, (int) $fatura->ano),
                'competencia_arquivo' => sprintf('%02d/%d', $mes, $ano),
            ]);

            return $fatura;
        }

        $jaTemAnexo = !empty($alvo->arquivo_pdf) || !empty($alvo->arquivo_csv);
        if ($jaTemAnexo) {
            throw new Exception(
                sprintf(
                    'Este arquivo é da competência %02d/%d, que já possui anexo. Remova o PDF de %02d/%d e envie na fatura correta.',
                    $mes,
                    $ano,
                    (int) $fatura->mes,
                    (int) $fatura->ano
                ),
                422
            );
        }

        $tinhaLancamentos = Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->whereNull('deleted_at')
            ->exists();

        $alvo->arquivo_pdf = $fatura->arquivo_pdf;
        $alvo->arquivo_csv = $fatura->arquivo_csv;
        $alvo->status = 'processando';
        $alvo->erro_mensagem = null;
        $alvo->erro_codigo = null;
        $alvo->processado_em = null;
        $alvo->save();

        $fatura->arquivo_pdf = null;
        $fatura->arquivo_csv = null;
        $fatura->status = 'pendente';
        $fatura->erro_mensagem = null;
        $fatura->erro_codigo = null;
        $fatura->processado_em = null;
        $fatura->save();

        if (!$tinhaLancamentos) {
            $fatura->delete();
        }

        Log::info('Anexo realocado para a competência do arquivo', [
            'de' => $fatura->id,
            'para' => $alvo->id,
            'competencia' => sprintf('%02d/%d', $mes, $ano),
        ]);

        return $alvo->fresh() ?? $alvo;
    }

    /**
     * Fatura restaurada/apagada vira stub: sem arquivo e sem status de processamento.
     * Pago/quitação continua vindo dos pagamentos da competência seguinte.
     *
     * @return array<string, mixed>
     */
    public static function atributosStubSemAnexo(): array
    {
        return [
            'arquivo_pdf' => null,
            'arquivo_csv' => null,
            'status' => 'pendente',
            'processado_em' => null,
            'erro_mensagem' => null,
            'erro_codigo' => null,
        ];
    }

    private function limparAnexoDaFatura(Fatura $fatura): void
    {
        $this->deleteStoredAnexo($fatura->arquivo_pdf);
        $this->deleteStoredAnexo($fatura->arquivo_csv);
        $fatura->fill(self::atributosStubSemAnexo());
        $fatura->save();
    }

    private function descartarAnexoAusenteNoStorage(Fatura $fatura): void
    {
        $pdfSumiu = !empty($fatura->arquivo_pdf) && !Storage::disk('local')->exists($fatura->arquivo_pdf);
        $csvSumiu = !empty($fatura->arquivo_csv) && !Storage::disk('local')->exists($fatura->arquivo_csv);
        if (!$pdfSumiu && !$csvSumiu) {
            return;
        }

        if ($pdfSumiu) {
            $fatura->arquivo_pdf = null;
        }
        if ($csvSumiu) {
            $fatura->arquivo_csv = null;
        }
        if (empty($fatura->arquivo_pdf) && empty($fatura->arquivo_csv)) {
            $fatura->fill(self::atributosStubSemAnexo());
        }
        $fatura->save();
    }

    /**
     * Path no banco sem arquivo no disco, ou fatura "processada" sem nenhum
     * lançamento importado (stub restaurado após apagar tudo). Sem ícone/preview.
     */
    private function descartarAnexoOrfaoDoStub(Fatura $fatura): void
    {
        $this->descartarAnexoAusenteNoStorage($fatura);
        $fatura->refresh();

        if (empty($fatura->arquivo_pdf) && empty($fatura->arquivo_csv)) {
            return;
        }

        if (!in_array((string) $fatura->status, ['processada', 'erro'], true)) {
            return;
        }

        $temImportados = Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->where('importada_pdf', true)
            ->whereNull('deleted_at')
            ->exists();

        if ($temImportados) {
            return;
        }

        $this->limparAnexoDaFatura($fatura);
    }

    /**
     * Anexa arquivo à fatura.
     * PDF e CSV convivem: só substitui o anexo do mesmo tipo.
     */
    private function attachPdfToFatura(
        Fatura $fatura,
        object $atributes,
        int $userId,
        string $message = 'PDF anexado à fatura existente com sucesso!',
        ?int $cartaoNumeroIdPadrao = null
    ): object {
        if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
            throw new Exception('Arquivo da fatura é obrigatório (PDF, CSV ou XML)', 422);
        }

        $tipoAnexo = $this->resolveAnexoTipo($atributes->arquivo_pdf);
        $fatura = $this->faturaAlvoPeloPeriodoDoAnexo($fatura, $atributes, $userId);
        $path = $this->storePdf($atributes->arquivo_pdf, $userId);

        $update = [
            'status' => 'pendente',
            'erro_mensagem' => null,
            'erro_codigo' => null,
            'processado_em' => null,
        ];

        if ($tipoAnexo === 'pdf') {
            $this->deleteStoredAnexo($fatura->arquivo_pdf);
            $update['arquivo_pdf'] = $path;
        } else {
            $this->deleteStoredAnexo($fatura->arquivo_csv);
            $update['arquivo_csv'] = $path;
        }

        $fatura->update($update);

        $processar = filter_var($atributes->processar_automatico ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($processar) {
            $this->dispatchProcessamento(
                $fatura->id,
                $tipoAnexo,
                $this->extractSenhaPdfFromRequest($atributes),
                filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN),
                false,
                $cartaoNumeroIdPadrao,
                $this->extractSenhaPdfRegraFromRequest($atributes)
            );
        }

        return $this->buildFaturaProcessamentoResponse(
            $fatura->fresh(['cartao']),
            $message
        );
    }

    /**
     * @return array{
     *   arquivo_pdf: ?string,
     *   arquivo_csv: ?string,
     *   tipo_arquivo: ?string,
     *   tem_pdf: bool,
     *   tem_csv: bool,
     *   pdf_url: ?string,
     *   csv_url: ?string
     * }
     */
    private function buildAnexoMeta(?string $arquivoPdf, ?string $arquivoCsv, int $faturaId): array
    {
        $temPdf = !empty($arquivoPdf);
        $temCsv = !empty($arquivoCsv);

        return [
            'arquivo_pdf' => $arquivoPdf,
            'arquivo_csv' => $arquivoCsv,
            'tipo_arquivo' => $temPdf ? 'pdf' : ($temCsv ? 'csv' : null),
            'tem_pdf' => $temPdf,
            'tem_csv' => $temCsv,
            'pdf_url' => $temPdf ? url('/api/v1/faturas/pdf/' . $faturaId) : null,
            'csv_url' => $temCsv ? url('/api/v1/faturas/csv/' . $faturaId) : null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function anexarPodeRemoverAnexo(array $item): array
    {
        $temAnexo = !empty($item['tem_pdf']) || !empty($item['tem_csv']);
        $item['pode_remover_anexo'] = $temAnexo && (($item['status'] ?? '') !== 'processando');

        return $item;
    }

    private function deleteStoredAnexo(?string $relativePath): void
    {
        if ($relativePath && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    /**
     * @return 'pdf'|'csv'
     */
    private function resolveAnexoTipo(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());
        $resolved = $this->resolveInvoiceExtension($extension, $mime);

        return $resolved === 'pdf' ? 'pdf' : 'csv';
    }

    private function validatePeriodo(object $atributes): void
    {
        if (empty($atributes->cartao_id)) {
            throw new Exception('Cartão é obrigatório', 422);
        }

        if (empty($atributes->mes) || empty($atributes->ano)) {
            throw new Exception('Mês e ano são obrigatórios', 422);
        }

        $mes = (int) $atributes->mes;
        $ano = (int) $atributes->ano;

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mês inválido', 422);
        }

        if ($ano < 2000 || $ano > 2100) {
            throw new Exception('Ano inválido', 422);
        }
    }

    private function hasPeriodoCompleto(object $atributes): bool
    {
        return !empty($atributes->cartao_id)
            && !empty($atributes->mes)
            && !empty($atributes->ano);
    }

    /**
     * Retry do modal "cadastrar cartão" na mesma tela (sem sair para /cartoes).
     *
     * Aceita `cadastrar_cartao=true` explícito ou a intenção clara
     * (nome + bandeira + mês/ano sem cartao_id) — evita 422 quando o front
     * preenche os campos mas omite a flag.
     */
    private function hasCadastroCartaoInline(object $atributes): bool
    {
        if (!empty($atributes->cartao_id)) {
            return false;
        }

        $nome = trim((string) ($atributes->cartao_nome ?? $atributes->novo_cartao_nome ?? ''));
        $bandeira = trim((string) ($atributes->bandeira ?? ''));

        if ($nome === '' || $bandeira === '' || empty($atributes->mes) || empty($atributes->ano)) {
            return false;
        }

        return true;
    }

    /**
     * Cria o grupo de cartão + bandeira e preenche cartao_id / cartao_bandeira_id no request.
     */
    private function criarCartaoInlineNoCadastroFatura(object $atributes, int $userId): void
    {
        $nome = trim((string) ($atributes->cartao_nome ?? $atributes->novo_cartao_nome ?? ''));
        $bandeiraNome = trim((string) ($atributes->bandeira ?? ''));
        $banco = trim((string) ($atributes->banco ?? $nome));

        if ($nome === '' || $bandeiraNome === '') {
            throw new Exception('Informe o nome do cartão e a bandeira para cadastrar nesta tela', 422);
        }

        if (!BandeiraCoresPreset::isValida($bandeiraNome)) {
            throw new Exception('Bandeira inválida. Use: ' . implode(', ', BandeiraCoresPreset::nomesLookups()), 422);
        }

        $diaLimite = !empty($atributes->dia_limite_fatura) ? (int) $atributes->dia_limite_fatura : 5;
        $diaVencimento = !empty($atributes->dia_vencimento_fatura) ? (int) $atributes->dia_vencimento_fatura : 10;

        $payload = (object) [
            'nome' => $nome,
            'banco' => $banco !== '' ? $banco : $nome,
            'dia_limite_fatura' => $diaLimite,
            'dia_vencimento_fatura' => $diaVencimento,
            'ativo' => true,
            'bandeiras' => [
                [
                    'bandeira' => $bandeiraNome,
                    'ativo' => true,
                    'numeros' => [],
                ],
            ],
        ];

        if (!empty($atributes->pessoa_id)) {
            $payload->pessoa_id = (int) $atributes->pessoa_id;
        }

        $regra = trim((string) ($atributes->senha_pdf_regra ?? ''));
        if ($regra !== '') {
            $payload->senha_pdf_regra = $regra;
        }

        if (!empty($atributes->senha_pdf) && filter_var($atributes->salvar_senha_pdf ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $payload->senha_pdf = $atributes->senha_pdf;
        }

        $result = (new CartaoService())->createCartao($payload);
        $cartao = $result->data ?? null;
        $cartaoId = is_array($cartao) ? (int) ($cartao['id'] ?? 0) : (int) ($cartao->id ?? 0);

        if ($cartaoId <= 0) {
            throw new Exception('Não foi possível cadastrar o cartão a partir da fatura', 500);
        }

        $bandeiraId = CartaoBandeira::where('cartao_id', $cartaoId)
            ->where('bandeira', $bandeiraNome)
            ->whereNull('deleted_at')
            ->value('id');

        $atributes->cartao_id = $cartaoId;
        if ($bandeiraId) {
            $atributes->cartao_bandeira_id = (int) $bandeiraId;
        }
    }

    /**
     * Confirma titular quando o PDF traz nome que não bate com as pessoas da conta.
     * Não bloqueia para sempre — o front escolhe pessoa existente, cadastra nova ou confirma.
     *
     * @return int|null pessoa_id resolvida
     */
    private function assertTitularConfirmadoSeNecessario(object $atributes, int $userId, int $cartaoId): ?int
    {
        $pessoaService = new PessoaService();
        $pessoaService->ensurePrincipalForUser(User::findOrFail($userId));

        $temEscolhaExplicita = !empty($atributes->pessoa_id)
            || filter_var($atributes->cadastrar_pessoa ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($temEscolhaExplicita) {
            $resolvida = $this->resolvePessoaIdOpcional($atributes, $userId, $cartaoId, criarSeNome: true);
            if ($resolvida !== null) {
                return $resolvida;
            }
        }

        if (filter_var($atributes->confirmar_titular ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $cartao = Cartao::where('id', $cartaoId)->where('user_id', $userId)->first();
            return $cartao?->pessoa_id !== null ? (int) $cartao->pessoa_id : null;
        }

        /** @var UploadedFile $file */
        $file = $atributes->arquivo_pdf;
        $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
        $parsed = (new InvoicePdfParserService())->parseUploadedFile($file, $senhaPdf);
        $titularesPdf = $this->extractTitularesFromMetadata($parsed['metadata'] ?? [], $parsed['transactions'] ?? []);

        if ($titularesPdf === []) {
            return $this->resolvePessoaIdOpcional($atributes, $userId, $cartaoId);
        }

        $pessoas = Pessoa::where('user_id', $userId)->where('ativo', true)->get();
        $nomesConhecidos = $pessoas->map(fn (Pessoa $p) => $p->nomeCompleto())->all();

        $cartao = Cartao::where('id', $cartaoId)->where('user_id', $userId)->first();
        if ($cartao?->pessoa_id) {
            $pessoaCartao = $pessoas->firstWhere('id', (int) $cartao->pessoa_id);
            if ($pessoaCartao) {
                $nomesConhecidos[] = $pessoaCartao->nomeCompleto();
            }
        }

        // Nomes impressos nos finais do cartão também contam (adicional conhecido).
        $nomesNoCartao = CartaoNumero::query()
            ->whereHas('bandeira', function ($q) use ($cartaoId) {
                $q->where('cartao_id', $cartaoId)->whereNull('deleted_at');
            })
            ->whereNull('deleted_at')
            ->whereNotNull('nome_no_cartao')
            ->pluck('nome_no_cartao')
            ->all();
        foreach ($nomesNoCartao as $n) {
            $nomesConhecidos[] = (string) $n;
        }

        $desconhecidos = [];
        foreach ($titularesPdf as $nomePdf) {
            if (!NomeMatch::matchesAny($nomePdf, $nomesConhecidos)) {
                $desconhecidos[] = $nomePdf;
            }
        }

        if ($desconhecidos === []) {
            // Bateu com alguém: vincula à pessoa matching (primeira), senão à do cartão / principal.
            foreach ($titularesPdf as $nomePdf) {
                foreach ($pessoas as $pessoa) {
                    if (NomeMatch::matches($nomePdf, $pessoa->nomeCompleto())) {
                        return (int) $pessoa->id;
                    }
                }
            }

            return $cartao?->pessoa_id !== null
                ? (int) $cartao->pessoa_id
                : (int) $pessoas->firstWhere('eh_principal', true)?->id;
        }

        $principal = $pessoas->firstWhere('eh_principal', true);
        $perfilNome = $principal?->nomeCompleto()
            ?? trim(Auth::user()?->name . ' ' . (Auth::user()?->sobrenome ?? ''));

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_TITULAR,
            [
                'precisa_confirmar_titular' => true,
                'orientacao' => 'O nome no PDF não corresponde às pessoas cadastradas nesta conta. '
                    . 'Vincule a uma pessoa existente, cadastre uma nova (ex.: cônjuge/adicional) ou confirme que quer importar mesmo assim.',
                'titulares_detectados' => array_values($titularesPdf),
                'titulares_desconhecidos' => array_values(array_unique($desconhecidos)),
                'perfil_nome' => $perfilNome !== '' ? $perfilNome : null,
                'pessoa_sugerida_id' => null,
                'pode_cadastrar_pessoa' => true,
                'pessoas' => $pessoas->map(fn (Pessoa $p) => [
                    'value' => (int) $p->id,
                    'label' => $p->nomeCompleto(),
                    'eh_principal' => (bool) $p->eh_principal,
                ])->values()->all(),
            ],
            'Esta fatura parece estar em nome de outra pessoa. Confirme a quem pertence.'
        );
    }

    /**
     * Resolve pessoa_id do request / cartão / cadastro inline de pessoa.
     */
    private function resolvePessoaIdOpcional(
        object $atributes,
        int $userId,
        ?int $cartaoId = null,
        bool $criarSeNome = false
    ): ?int {
        $pessoaService = new PessoaService();

        if (!empty($atributes->pessoa_id)) {
            return (int) $pessoaService->assertPessoaDoUsuario((int) $atributes->pessoa_id, $userId)->id;
        }

        if ($criarSeNome && filter_var($atributes->cadastrar_pessoa ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $nome = trim((string) ($atributes->pessoa_nome ?? $atributes->novo_pessoa_nome ?? ''));
            if ($nome === '' && !empty($atributes->titular_nome)) {
                $nome = trim((string) $atributes->titular_nome);
            }
            if ($nome === '') {
                throw new Exception('Informe o nome da pessoa para cadastrar', 422);
            }

            $sobrenome = trim((string) ($atributes->pessoa_sobrenome ?? ''));
            if ($sobrenome !== '') {
                $created = $pessoaService->createPessoa((object) [
                    'nome' => $nome,
                    'sobrenome' => $sobrenome,
                    'cpf_cnpj' => $atributes->pessoa_cpf_cnpj ?? null,
                    'ativo' => true,
                ]);
                return (int) ($created->data['id'] ?? 0);
            }

            return (int) $pessoaService->createFromNomeCompleto(
                $userId,
                $nome,
                isset($atributes->pessoa_cpf_cnpj) ? (string) $atributes->pessoa_cpf_cnpj : null
            )->id;
        }

        if ($cartaoId !== null) {
            $pessoaId = Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id');
            if ($pessoaId) {
                return (int) $pessoaId;
            }
        }

        return null;
    }

    private function linkPessoaAoCartao(int $cartaoId, int $pessoaId): void
    {
        $cartao = Cartao::where('id', $cartaoId)->where('user_id', Auth::id())->first();
        if (!$cartao) {
            return;
        }

        if ($cartao->pessoa_id === null || (int) $cartao->pessoa_id !== $pessoaId) {
            $cartao->pessoa_id = $pessoaId;
            $cartao->save();
        }
    }

    private function resolveResponsavelIdParaPessoa(int $pessoaId, int $userId): int
    {
        $pessoa = Pessoa::where('id', $pessoaId)->where('user_id', $userId)->first();
        if (!$pessoa) {
            throw new Exception('Pessoa inválida', 422);
        }

        return (int) (new PessoaService())->ensureResponsavelForPessoa($pessoa)->id;
    }

    private function resolveResponsavelIdDoCartao(int $cartaoId, int $userId): ?int
    {
        $pessoaId = Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id');
        if (!$pessoaId) {
            return null;
        }

        return $this->resolveResponsavelIdParaPessoa((int) $pessoaId, $userId);
    }

    private function aplicarPessoaResponsavelDoCartao(Fatura $fatura, int $cartaoId, int $userId): void
    {
        $pessoaId = Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id');
        if (!$pessoaId) {
            return;
        }

        $pessoaId = (int) $pessoaId;
        if ($fatura->pessoa_id === null) {
            $fatura->pessoa_id = $pessoaId;
        }
        $fatura->responsavel_id = $this->resolveResponsavelIdParaPessoa((int) $fatura->pessoa_id, $userId);
        $fatura->save();
        $this->realinharTransacoesImportadasAoPadrao($fatura);
    }

    /**
     * Titular que não é o principal não pode ficar com responsável "Eu".
     * Corrige a fatura e as compras que ainda apontam para Eu.
     */
    public function ensureResponsavelPadraoFatura(Fatura $fatura): void
    {
        $pessoa = $this->resolvePessoaTitularDaFatura($fatura);
        if (!$pessoa) {
            return;
        }

        if ((int) $fatura->pessoa_id !== (int) $pessoa->id) {
            $fatura->pessoa_id = $pessoa->id;
            $fatura->save();
        }

        $responsavel = (new PessoaService())->ensureResponsavelForPessoa($pessoa);
        if ((int) $fatura->responsavel_id !== (int) $responsavel->id) {
            $fatura->responsavel_id = $responsavel->id;
            $fatura->save();
        }

        $this->realinharTransacoesImportadasAoPadrao($fatura);
    }

    /**
     * Garante responsável padrão em todas as faturas do usuário (projeção, listagens).
     */
    public function ensureResponsavelPadraoFaturasDoUsuario(int $userId): void
    {
        $faturas = Fatura::where('user_id', $userId)->get();
        foreach ($faturas as $fatura) {
            $this->ensureResponsavelPadraoFatura($fatura);
        }
    }

    private function resolvePessoaTitularDaFatura(Fatura $fatura): ?Pessoa
    {
        $pessoaFatura = $fatura->pessoa_id
            ? Pessoa::where('id', $fatura->pessoa_id)->where('user_id', $fatura->user_id)->first()
            : null;
        $pessoaCartaoId = Cartao::where('id', $fatura->cartao_id)
            ->where('user_id', $fatura->user_id)
            ->value('pessoa_id');
        $pessoaCartao = $pessoaCartaoId
            ? Pessoa::where('id', $pessoaCartaoId)->where('user_id', $fatura->user_id)->first()
            : null;

        if ($pessoaCartao && !$pessoaCartao->eh_principal) {
            return $pessoaCartao;
        }

        return $pessoaFatura ?? $pessoaCartao;
    }

    private function realinharTransacoesImportadasAoPadrao(Fatura $fatura): void
    {
        if (!$fatura->responsavel_id || !$fatura->pessoa_id) {
            return;
        }

        $pessoa = Pessoa::where('id', $fatura->pessoa_id)
            ->where('user_id', $fatura->user_id)
            ->first();
        if (!$pessoa || $pessoa->eh_principal) {
            return;
        }

        $eu = Responsavel::where('user_id', $fatura->user_id)
            ->whereRaw('LOWER(TRIM(nome)) = ?', ['eu'])
            ->first();
        if (!$eu || (int) $eu->id === (int) $fatura->responsavel_id) {
            return;
        }

        Transacao::where('fatura_id', $fatura->id)
            ->where('user_id', $fatura->user_id)
            ->where(function ($query) use ($eu) {
                $query->where('responsavel_id', $eu->id)
                    ->orWhereNull('responsavel_id');
            })
            ->update(['responsavel_id' => $fatura->responsavel_id]);
    }

    /**
     * Não sobrescreve PDF de uma fatura já anexada quando o novo arquivo é de outro titular.
     * Uma fatura por (cartão/bandeira + mês); duas pessoas = dois cartões.
     */
    private function novoAnexoConflitaComFaturaExistente(
        Fatura $existing,
        int $cartaoId,
        int $userId,
        ?int $pessoaIdResolvida,
        object $atributes
    ): bool {
        $jaTemAnexo = !empty($existing->arquivo_pdf) || !empty($existing->arquivo_csv);
        if (!$jaTemAnexo) {
            return false;
        }

        if ($pessoaIdResolvida !== null
            && $existing->pessoa_id !== null
            && (int) $existing->pessoa_id !== $pessoaIdResolvida
        ) {
            return true;
        }

        /** @var UploadedFile $file */
        $file = $atributes->arquivo_pdf;
        $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
        $parsed = (new InvoicePdfParserService())->parseUploadedFile($file, $senhaPdf);
        $titularesPdf = $this->extractTitularesFromMetadata($parsed['metadata'] ?? [], $parsed['transactions'] ?? []);

        if ($titularesPdf === []) {
            return false;
        }

        $nomesDaExistente = $this->nomesConhecidosDoCartaoEFatura($existing, $cartaoId, $userId);
        if ($nomesDaExistente === []) {
            return false;
        }

        foreach ($titularesPdf as $nomePdf) {
            if (!NomeMatch::matchesAny($nomePdf, $nomesDaExistente)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function nomesConhecidosDoCartaoEFatura(Fatura $existing, int $cartaoId, int $userId): array
    {
        $nomes = [];

        if ($existing->pessoa_id) {
            $pessoa = Pessoa::where('id', $existing->pessoa_id)->where('user_id', $userId)->first();
            if ($pessoa) {
                $nomes[] = $pessoa->nomeCompleto();
            }
        }

        $cartao = Cartao::where('id', $cartaoId)->where('user_id', $userId)->first();
        if ($cartao?->pessoa_id) {
            $pessoa = Pessoa::where('id', $cartao->pessoa_id)->where('user_id', $userId)->first();
            if ($pessoa) {
                $nomes[] = $pessoa->nomeCompleto();
            }
        }

        $nomesNoCartao = CartaoNumero::query()
            ->whereHas('bandeira', function ($q) use ($cartaoId) {
                $q->where('cartao_id', $cartaoId)->whereNull('deleted_at');
            })
            ->whereNull('deleted_at')
            ->whereNotNull('nome_no_cartao')
            ->pluck('nome_no_cartao')
            ->all();

        foreach ($nomesNoCartao as $n) {
            $nomes[] = (string) $n;
        }

        if ($nomes === []) {
            $principal = Pessoa::where('user_id', $userId)->where('eh_principal', true)->first();
            if ($principal) {
                $nomes[] = $principal->nomeCompleto();
            }
        }

        return array_values(array_unique(array_filter($nomes)));
    }

    /**
     * @return never
     */
    private function throwPrecisaCartaoDoTitular(
        Fatura $existing,
        int $cartaoId,
        int $userId,
        object $atributes,
        ?int $pessoaIdResolvida
    ): never {
        /** @var UploadedFile $file */
        $file = $atributes->arquivo_pdf;
        $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
        $parsed = (new InvoicePdfParserService())->parseUploadedFile($file, $senhaPdf);
        $titularesPdf = $this->extractTitularesFromMetadata($parsed['metadata'] ?? [], $parsed['transactions'] ?? []);
        $parser = (string) (($parsed['metadata']['parser'] ?? null) ?: ($parsed['parser'] ?? 'generico'));
        $nomeSugerido = $this->suggestedCartaoNomeFromParser($parser);

        $pessoas = Pessoa::where('user_id', $userId)->where('ativo', true)->get();
        $pessoaExistente = $existing->pessoa_id
            ? $pessoas->firstWhere('id', (int) $existing->pessoa_id)
            : null;

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_CARTAO_TITULAR,
            [
                'precisa_cartao_do_titular' => true,
                'pode_cadastrar_cartao' => true,
                'fatura_existente_id' => (int) $existing->id,
                'cartao_existente_id' => $cartaoId,
                'pessoa_existente_id' => $existing->pessoa_id !== null ? (int) $existing->pessoa_id : null,
                'pessoa_existente_nome' => $pessoaExistente?->nomeCompleto(),
                'pessoa_nova_id' => $pessoaIdResolvida,
                'titulares_detectados' => $titularesPdf,
                'orientacao' => 'Já existe fatura deste mês neste cartão'
                    . ($pessoaExistente ? ' (' . $pessoaExistente->nomeCompleto() . ')' : '')
                    . '. Faturas de pessoas diferentes precisam de cartões separados — '
                    . 'cadastre o cartão desta pessoa nesta tela para as duas coexistirem.',
                'sugestao' => [
                    'cartao_id' => null,
                    'cartao_nome_sugerido' => $nomeSugerido,
                    'mes' => (int) $existing->mes,
                    'ano' => (int) $existing->ano,
                    'parser' => $parser,
                    'bandeira_sugerida' => $parsed['metadata']['bandeira_sugerida'] ?? null,
                    'pessoa_id' => $pessoaIdResolvida,
                    'dia_limite_fatura_padrao' => 5,
                    'dia_vencimento_fatura_padrao' => 10,
                ],
                'pessoas' => $pessoas->map(fn (Pessoa $p) => [
                    'value' => (int) $p->id,
                    'label' => $p->nomeCompleto(),
                    'eh_principal' => (bool) $p->eh_principal,
                ])->values()->all(),
                'bandeiras' => collect(BandeiraCoresPreset::paresParaLookups())
                    ->map(fn (array $preset) => array_merge([
                        'value' => null,
                        'label' => $preset['label'],
                        'criar' => true,
                    ], BandeiraCoresPreset::anexar($preset['label'])))
                    ->values()
                    ->all(),
            ],
            'Já existe fatura deste mês neste cartão. Cadastre o cartão da outra pessoa para as duas coexistirem.'
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $transactions
     * @return list<string>
     */
    private function extractTitularesFromMetadata(array $metadata, array $transactions): array
    {
        $titulares = [];
        foreach ($metadata['titulares'] ?? [] as $nome) {
            $nome = trim((string) $nome);
            if ($nome !== '') {
                $titulares[$nome] = true;
            }
        }
        foreach ($transactions as $tx) {
            $nome = isset($tx['nome_no_cartao']) ? trim((string) $tx['nome_no_cartao']) : '';
            if ($nome !== '') {
                $titulares[$nome] = true;
            }
        }

        return array_keys($titulares);
    }

    /**
     * PDF/CSV no cadastro sem cartão/mês/ano: se o arquivo identifica um único
     * cartão + competência e já existe stub (fatura sem anexo) nessa combinação,
     * preenche o request e segue — anexa na existente sem o 422 de metadados.
     */
    private function preencherPeriodoDoAnexoSeStubExistente(object $atributes, int $userId): bool
    {
        if (empty($atributes->arquivo_pdf) || !($atributes->arquivo_pdf instanceof UploadedFile)) {
            return false;
        }

        try {
            $parsed = (new InvoicePdfParserService())->parseUploadedFile(
                $atributes->arquivo_pdf,
                $this->extractSenhaPdfFromRequest($atributes)
            );
        } catch (PdfPasswordException $e) {
            throw $e;
        } catch (Exception $e) {
            return false;
        }

        $metadata = $parsed['metadata'] ?? [];
        $mes = !empty($atributes->mes) ? (int) $atributes->mes : ($metadata['mes'] ?? null);
        $ano = !empty($atributes->ano) ? (int) $atributes->ano : ($metadata['ano'] ?? null);
        if ($mes === null || $ano === null || $mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
            return false;
        }

        $ultimosDigitos = $metadata['ultimos_digitos'] ?? [];
        $parser = (string) ($metadata['parser'] ?? $parsed['parser'] ?? 'generico');
        $cartaoMatch = $this->matchCartaoFromMetadata(
            $userId,
            !empty($atributes->cartao_id) ? (int) $atributes->cartao_id : null,
            is_array($ultimosDigitos) ? $ultimosDigitos : [],
            $parser
        );

        $titularesDetectados = $this->extractTitularesFromMetadata($metadata, $parsed['transactions'] ?? []);
        if ($cartaoMatch['cartao_id'] !== null
            && in_array($cartaoMatch['confianca'], ['media', 'baixa'], true)
            && $titularesDetectados !== []
            && !$this->cartaoCompativelComTitulares((int) $cartaoMatch['cartao_id'], $userId, $titularesDetectados)
        ) {
            return false;
        }

        $cartaoId = $cartaoMatch['cartao_id'];
        if ($cartaoId === null) {
            return false;
        }

        $stub = $this->stubSemAnexoDoPeriodo($userId, (int) $cartaoId, (int) $mes, (int) $ano);
        if ($stub === null) {
            return false;
        }
        $atributes->cartao_id = (int) $cartaoId;
        $atributes->mes = (int) $mes;
        $atributes->ano = (int) $ano;
        if (!empty($stub->cartao_bandeira_id) && empty($atributes->cartao_bandeira_id)) {
            $atributes->cartao_bandeira_id = (int) $stub->cartao_bandeira_id;
        }

        Log::info('Cadastro com anexo vinculado ao stub existente sem confirmação de metadados', [
            'fatura_id' => (int) $stub->id,
            'cartao_id' => (int) $cartaoId,
            'competencia' => sprintf('%02d/%d', $mes, $ano),
        ]);

        return true;
    }

    /**
     * Lê o anexo, sugere cartão/mês/ano e exige confirmação no modal do front.
     *
     * @throws FaturaSelecaoException|PdfPasswordException|Exception
     */
    private function throwConfirmacaoMetadadosDoAnexo(object $atributes, int $userId): never
    {
        /** @var UploadedFile $file */
        $file = $atributes->arquivo_pdf;
        $senhaPdf = $this->extractSenhaPdfFromRequest($atributes);
        // Upload temp (`/tmp/phpXXXX`) não tem extensão — usar nome/MIME originais.
        $parsed = (new InvoicePdfParserService())->parseUploadedFile($file, $senhaPdf);
        $metadata = $parsed['metadata'] ?? [];

        $mes = !empty($atributes->mes) ? (int) $atributes->mes : ($metadata['mes'] ?? null);
        $ano = !empty($atributes->ano) ? (int) $atributes->ano : ($metadata['ano'] ?? null);
        $ultimosDigitos = $metadata['ultimos_digitos'] ?? [];
        $parser = (string) ($metadata['parser'] ?? $parsed['parser'] ?? 'generico');
        $bandeiraSugerida = $metadata['bandeira_sugerida'] ?? null;
        $nomeSugerido = $this->suggestedCartaoNomeFromParser($parser);

        $cartaoMatch = $this->matchCartaoFromMetadata(
            $userId,
            !empty($atributes->cartao_id) ? (int) $atributes->cartao_id : null,
            is_array($ultimosDigitos) ? $ultimosDigitos : [],
            $parser
        );

        $titularesDetectados = $this->extractTitularesFromMetadata($metadata, $parsed['transactions'] ?? []);

        // Match só por nome do banco (ex.: dois Nubanks) não vale se o titular do PDF
        // não bate com a pessoa/nomes daquele cartão — senão a fatura da Maysa cai no cartão do Leonardo.
        if ($cartaoMatch['cartao_id'] !== null
            && in_array($cartaoMatch['confianca'], ['media', 'baixa'], true)
            && $titularesDetectados !== []
            && !$this->cartaoCompativelComTitulares((int) $cartaoMatch['cartao_id'], $userId, $titularesDetectados)
        ) {
            $cartaoMatch = [
                'cartao_id' => null,
                'cartao_nome' => null,
                'confianca' => 'baixa',
                'candidatos' => $cartaoMatch['candidatos'],
            ];
        }

        $detectouAlgo = ($mes !== null && $ano !== null) || $cartaoMatch['cartao_id'] !== null;
        if (!$detectouAlgo) {
            throw new Exception(
                'Não foi possível identificar cartão, mês e ano pelo arquivo. Informe esses campos manualmente.',
                422
            );
        }

        $cartaoId = $cartaoMatch['cartao_id'];
        $modo = $cartaoId !== null ? 'confirmar_cartao' : 'cadastrar_cartao';
        $bandeirasLookups = collect(BandeiraCoresPreset::paresParaLookups())
            ->map(fn (array $preset) => array_merge([
                'value' => null,
                'label' => $preset['label'],
                'criar' => true,
            ], BandeiraCoresPreset::anexar($preset['label'])))
            ->values()
            ->all();

        $bandeiras = $cartaoId !== null ? $this->buildBandeirasModalOptions($cartaoId) : $bandeirasLookups;
        $precisaBandeira = false;
        $bandeiraIdSugerida = null;

        if ($modo === 'cadastrar_cartao') {
            // Sem cartão na conta: o modal cadastra nome + bandeira na mesma tela.
            $precisaBandeira = true;
        } elseif ($cartaoId !== null) {
            $ativas = array_values(array_filter(
                $bandeiras,
                static fn (array $b) => empty($b['criar']) && !empty($b['value'])
            ));

            if (count($ativas) === 0) {
                $precisaBandeira = true;
                $bandeiras = $bandeirasLookups;
            } elseif (count($ativas) === 1) {
                $bandeiraIdSugerida = (int) $ativas[0]['value'];
            } else {
                $precisaBandeira = true;
                if (is_string($bandeiraSugerida) && $bandeiraSugerida !== '') {
                    foreach ($ativas as $opt) {
                        if (($opt['label'] ?? '') === $bandeiraSugerida) {
                            $bandeiraIdSugerida = (int) $opt['value'];
                            break;
                        }
                    }
                }
            }
        }

        $message = $modo === 'cadastrar_cartao'
            ? 'Identificamos mês e ano da fatura. Cadastre o cartão nesta mesma tela (nome e bandeira) para concluir — não é preciso sair desta tela.'
            : 'Confirme o cartão, mês e ano identificados na fatura';

        $faturaExistenteId = null;
        if ($cartaoId !== null && $mes !== null && $ano !== null) {
            $stub = $this->stubSemAnexoDoPeriodo($userId, (int) $cartaoId, (int) $mes, (int) $ano);
            $faturaExistenteId = $stub !== null ? (int) $stub->id : null;
        }

        throw new FaturaSelecaoException(
            FaturaSelecaoException::CODIGO_METADADOS,
            [
                'precisa_confirmar_metadados' => true,
                'modo' => $modo,
                'pode_cadastrar_cartao' => $modo === 'cadastrar_cartao',
                'precisa_selecionar_bandeira' => $precisaBandeira,
                'fatura_existente_id' => $faturaExistenteId,
                'orientacao' => $modo === 'cadastrar_cartao'
                    ? 'O cartão desta fatura ainda não está na sua conta. Informe o nome e a bandeira aqui no modal; o cadastro do cartão e da fatura são concluídos juntos, sem ir para outra tela.'
                    : 'Confirme os dados identificados. Se a bandeira ainda não existir no cartão, escolha-a neste mesmo modal.',
                'sugestao' => [
                    'cartao_id' => $cartaoId,
                    'cartao_nome' => $cartaoMatch['cartao_nome'] ?? $nomeSugerido,
                    'cartao_nome_sugerido' => $nomeSugerido,
                    'mes' => $mes,
                    'ano' => $ano,
                    'parser' => $parser,
                    'ultimos_digitos' => array_values($ultimosDigitos),
                    'titulares' => $titularesDetectados,
                    'bandeira_sugerida' => $bandeiraSugerida,
                    'cartao_bandeira_id' => $bandeiraIdSugerida,
                    'valor_fatura' => $parsed['valor_fatura'] ?? null,
                    'conferencia' => $parsed['conferencia'] ?? null,
                    'confianca' => $cartaoMatch['confianca'],
                    'dia_limite_fatura_padrao' => 5,
                    'dia_vencimento_fatura_padrao' => 10,
                    'fatura_existente_id' => $faturaExistenteId,
                ] + FaturaParserHomologacao::anexarParser($parser),
                // Em modo cadastrar_cartao a lista existe só como atalho opcional ("já tenho este cartão").
                'cartoes' => $this->buildCartoesModalOptions($userId, $cartaoMatch['candidatos']),
                'bandeiras' => $bandeiras,
                'candidatos_cartao' => $cartaoMatch['candidatos'],
            ],
            $message
        );
    }

    private function stubSemAnexoDoPeriodo(int $userId, int $cartaoId, int $mes, int $ano): ?Fatura
    {
        $stubs = Fatura::where('user_id', $userId)
            ->where('cartao_id', $cartaoId)
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->get()
            ->filter(fn (Fatura $f) => empty($f->arquivo_pdf) && empty($f->arquivo_csv))
            ->values();

        return $stubs->count() === 1 ? $stubs->first() : null;
    }

    /**
     * @param  list<string>  $titulares
     */
    private function cartaoCompativelComTitulares(int $cartaoId, int $userId, array $titulares): bool
    {
        if ($titulares === []) {
            return true;
        }

        $fake = new Fatura([
            'pessoa_id' => Cartao::where('id', $cartaoId)->where('user_id', $userId)->value('pessoa_id'),
        ]);
        $nomes = $this->nomesConhecidosDoCartaoEFatura($fake, $cartaoId, $userId);
        if ($nomes === []) {
            return true;
        }

        foreach ($titulares as $t) {
            if (NomeMatch::matchesAny($t, $nomes)) {
                return true;
            }
        }

        return false;
    }

    private function suggestedCartaoNomeFromParser(string $parser): ?string
    {
        $base = explode('-', $parser)[0];

        return match ($base) {
            'c6' => 'C6',
            'nubank' => 'Nubank',
            'inter' => 'Inter',
            'itau' => 'Itaú',
            'picpay' => 'PicPay',
            'sofisa' => 'Sofisa',
            default => null,
        };
    }

    /**
     * @param  list<string>  $ultimosDigitos
     * @return array{
     *   cartao_id: ?int,
     *   cartao_nome: ?string,
     *   confianca: string,
     *   candidatos: list<array{id: int, nome: string, banco: ?string, match: string, ultimos_digitos: list<string>}>
     * }
     */
    private function matchCartaoFromMetadata(
        int $userId,
        ?int $cartaoIdInformado,
        array $ultimosDigitos,
        string $parser
    ): array {
        if ($cartaoIdInformado !== null) {
            $cartao = Cartao::where('id', $cartaoIdInformado)
                ->where('user_id', $userId)
                ->first(['id', 'nome', 'banco']);
            if ($cartao) {
                return [
                    'cartao_id' => (int) $cartao->id,
                    'cartao_nome' => $cartao->nome,
                    'confianca' => 'informado',
                    'candidatos' => [[
                        'id' => (int) $cartao->id,
                        'nome' => $cartao->nome,
                        'banco' => $cartao->banco,
                        'match' => 'informado',
                        'ultimos_digitos' => [],
                    ]],
                ];
            }
        }

        $candidatos = [];

        $digitosValidos = array_values(array_filter(
            $ultimosDigitos,
            static fn ($d) => is_string($d) && preg_match('/^\d{4}$/', $d)
        ));

        if ($digitosValidos !== []) {
            $porDigitos = Cartao::query()
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->whereHas('bandeiras', function ($q) use ($digitosValidos) {
                    $q->whereNull('deleted_at')
                        ->where('ativo', true)
                        ->whereHas('numeros', function ($n) use ($digitosValidos) {
                            $n->whereNull('deleted_at')
                                ->where('ativo', true)
                                ->whereIn('ultimos_digitos', $digitosValidos);
                        });
                })
                ->with(['bandeiras' => function ($q) use ($digitosValidos) {
                    $q->whereNull('deleted_at')
                        ->where('ativo', true)
                        ->with(['numeros' => function ($n) use ($digitosValidos) {
                            $n->whereNull('deleted_at')
                                ->where('ativo', true)
                                ->whereIn('ultimos_digitos', $digitosValidos)
                                ->select('id', 'cartao_bandeira_id', 'ultimos_digitos');
                        }]);
                }])
                ->get(['id', 'nome', 'banco']);

            foreach ($porDigitos as $cartao) {
                $matchedDigits = [];
                foreach ($cartao->bandeiras as $bandeira) {
                    foreach ($bandeira->numeros as $numero) {
                        $matchedDigits[$numero->ultimos_digitos] = true;
                    }
                }
                $candidatos[(int) $cartao->id] = [
                    'id' => (int) $cartao->id,
                    'nome' => $cartao->nome,
                    'banco' => $cartao->banco,
                    'match' => 'ultimos_digitos',
                    'ultimos_digitos' => array_keys($matchedDigits),
                ];
            }
        }

        if ($candidatos === [] && $parser !== '' && $parser !== 'generico' && $parser !== 'csv') {
            $aliases = $this->parserBankAliases($parser);
            $cartoes = Cartao::query()
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->get(['id', 'nome', 'banco']);

            foreach ($cartoes as $cartao) {
                $haystack = mb_strtolower(trim(($cartao->nome ?? '') . ' ' . ($cartao->banco ?? '')));
                foreach ($aliases as $alias) {
                    if ($alias !== '' && str_contains($haystack, $alias)) {
                        $candidatos[(int) $cartao->id] = [
                            'id' => (int) $cartao->id,
                            'nome' => $cartao->nome,
                            'banco' => $cartao->banco,
                            'match' => 'banco',
                            'ultimos_digitos' => [],
                        ];
                        break;
                    }
                }
            }
        }

        $lista = array_values($candidatos);

        if (count($lista) === 1) {
            return [
                'cartao_id' => $lista[0]['id'],
                'cartao_nome' => $lista[0]['nome'],
                'confianca' => $lista[0]['match'] === 'ultimos_digitos' ? 'alta' : 'media',
                'candidatos' => $lista,
            ];
        }

        if (count($lista) > 1) {
            return [
                'cartao_id' => null,
                'cartao_nome' => null,
                'confianca' => 'ambigua',
                'candidatos' => $lista,
            ];
        }

        return [
            'cartao_id' => null,
            'cartao_nome' => null,
            'confianca' => 'baixa',
            'candidatos' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function parserBankAliases(string $parser): array
    {
        $base = explode('-', $parser)[0];

        return match ($base) {
            'c6' => ['c6', 'c6 bank', 'c6bank'],
            'nubank' => ['nubank', 'nu pagamentos'],
            'inter' => ['inter'],
            'itau' => ['itaú', 'itau', 'unibanco'],
            'picpay' => ['picpay'],
            'sofisa' => ['sofisa'],
            default => $base !== '' && $base !== 'generico' && $base !== 'csv' ? [$base] : [],
        };
    }

    /**
     * @param  list<array{id: int, nome: string, banco: ?string}>  $candidatos
     * @return list<array{value: int, label: string, banco: ?string, sugerido?: bool}>
     */
    private function buildCartoesModalOptions(int $userId, array $candidatos = []): array
    {
        $sugeridos = [];
        foreach ($candidatos as $c) {
            $sugeridos[(int) $c['id']] = true;
        }

        return Cartao::query()
            ->where('user_id', $userId)
            ->where('ativo', true)
            ->whereNull('deleted_at')
            ->orderBy('nome')
            ->get(['id', 'nome', 'banco'])
            ->map(function (Cartao $c) use ($sugeridos) {
                $item = array_merge([
                    'value' => (int) $c->id,
                    'label' => $c->nome,
                    'banco' => $c->banco,
                ], FaturaParserHomologacao::anexarCartao($c->nome, $c->banco));
                if (isset($sugeridos[(int) $c->id])) {
                    $item['sugerido'] = true;
                }

                return $item;
            })
            ->values()
            ->all();
    }

    private function assertCartaoDoUsuario(int|string $cartaoId, int $userId): void
    {
        $exists = Cartao::where('id', $cartaoId)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            throw new Exception('Cartão não encontrado', 404);
        }
    }

    private function storePdf(UploadedFile $file, int $userId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());

        $allowedExtensions = ['pdf', 'csv', 'xml', 'txt'];
        $allowedMimes = [
            'application/pdf',
            'text/csv',
            'text/plain',
            'text/xml',
            'application/xml',
            'application/vnd.ms-excel',
        ];

        if (!in_array($extension, $allowedExtensions, true) && !in_array($mime, $allowedMimes, true)) {
            throw new Exception('O arquivo deve ser PDF, CSV ou XML', 422);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new Exception('O arquivo deve ter no máximo 10MB', 422);
        }

        // CSVs do Inter/Excel costumam chegar como text/plain → Laravel salva .txt
        // e o parser rejeita. Normaliza a extensão antes de persistir.
        $extension = $this->resolveInvoiceExtension($extension, $mime);

        $filename = Str::random(40) . '.' . $extension;

        return $file->storeAs("faturas/{$userId}", $filename, 'local');
    }

    private function resolveInvoiceExtension(string $extension, string $mime): string
    {
        if (in_array($extension, ['pdf', 'csv', 'xml'], true)) {
            return $extension;
        }

        if (str_contains($mime, 'pdf') || $extension === 'pdf') {
            return 'pdf';
        }

        if (str_contains($mime, 'xml') || $extension === 'xml') {
            return 'xml';
        }

        // text/plain, application/vnd.ms-excel, .txt → CSV de fatura
        if (
            in_array($extension, ['txt', 'csv', ''], true)
            || in_array($mime, ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'], true)
        ) {
            return 'csv';
        }

        return $extension !== '' ? $extension : 'csv';
    }

    /**
     * Com QUEUE_CONNECTION=sync, falha do job virava 422 no cadastro/upload.
     * O job já grava status=erro; o cadastro deve seguir (exceto rethrowSenha no reprocessar).
     */
    private function dispatchProcessamento(
        int $faturaId,
        ?string $arquivoPreferido = null,
        ?string $senhaPdf = null,
        bool $salvarSenhaPdf = false,
        bool $rethrowSenha = false,
        ?int $cartaoNumeroIdPadrao = null,
        ?string $senhaPdfRegra = null
    ): void {
        try {
            ProcessInvoicePdfJob::dispatch(
                $faturaId,
                $arquivoPreferido,
                $senhaPdf,
                $salvarSenhaPdf,
                $cartaoNumeroIdPadrao,
                $senhaPdfRegra
            );
        } catch (PdfPasswordException $e) {
            if ($rethrowSenha) {
                throw $e;
            }

            Log::warning('Processamento automático da fatura aguarda senha do PDF', [
                'fatura_id' => $faturaId,
                'motivo' => $e->motivo,
            ]);
        } catch (Exception $e) {
            Log::warning('Processamento automático da fatura falhou', [
                'fatura_id' => $faturaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractSenhaPdfRegraFromRequest(?object $atributes): ?string
    {
        if (!$atributes || !isset($atributes->senha_pdf_regra)) {
            return null;
        }

        $regra = trim((string) $atributes->senha_pdf_regra);

        return $regra !== '' ? $regra : null;
    }

    private function extractSenhaPdfFromRequest(?object $atributes): ?string
    {
        if (!$atributes || !isset($atributes->senha_pdf)) {
            return null;
        }

        $senha = trim((string) $atributes->senha_pdf);

        return $senha === '' ? null : $senha;
    }

    private function buildFaturaProcessamentoResponse(Fatura $fatura, string $message): object
    {
        $cartao = $fatura->relationLoaded('cartao') ? $fatura->cartao : $fatura->cartao()->first();
        $data = $fatura->toArray();
        unset($data['cartao']);

        $data = array_merge($data, $this->buildAnexoMeta(
            $fatura->arquivo_pdf,
            $fatura->arquivo_csv,
            (int) $fatura->id
        ));
        $data = $this->anexarPodeRemoverAnexo($data);

        $data['senha_pdf'] = $this->buildSenhaPdfMeta(
            $fatura->erro_codigo,
            (int) $fatura->cartao_id,
            $cartao?->senha_pdf_regra,
            (bool) ($cartao?->temSenhaPdf())
        );
        $data['precisa_senha_pdf'] = $this->isSenhaPdfErro($fatura->erro_codigo);

        $pessoa = $fatura->pessoa_id
            ? Pessoa::where('id', $fatura->pessoa_id)->where('user_id', $fatura->user_id)->first()
            : null;
        $responsavel = $fatura->responsavel_id
            ? $fatura->responsavel()->first()
            : null;
        $data['pessoa_nome'] = $pessoa?->nomeCompleto();
        $data['responsavel_nome'] = $responsavel?->nome;

        if ($cartao) {
            $data['cartao'] = [
                'id' => $cartao->id,
                'nome' => $cartao->nome,
                'banco' => $cartao->banco,
                'tem_senha_pdf' => $cartao->temSenhaPdf(),
                'senha_pdf_regra' => $cartao->senha_pdf_regra,
                'senha_pdf_orientacao' => PdfSenhaRegra::orientacao($cartao->senha_pdf_regra),
            ];
        }

        return (object) [
            'data' => $data,
            'status' => true,
            'message' => $message,
            'precisa_senha_pdf' => $data['precisa_senha_pdf'],
        ];
    }

    /**
     * @return array{
     *   necessaria: bool,
     *   motivo: string,
     *   regra: ?string,
     *   orientacao: ?string,
     *   label_regra: ?string,
     *   tem_senha_cadastrada: bool,
     *   cartao_id: ?int
     * }|null
     */
    private function buildSenhaPdfMeta(
        ?string $erroCodigo,
        ?int $cartaoId,
        ?string $regra,
        bool $temSenhaCadastrada
    ): ?array {
        if (!$this->isSenhaPdfErro($erroCodigo)) {
            return null;
        }

        $regraEfetiva = $regra ?: null;

        return [
            'necessaria' => true,
            'motivo' => $erroCodigo === PdfSenhaRegra::CODIGO_SENHA_INCORRETA
                ? PdfPasswordException::MOTIVO_INCORRETA
                : PdfPasswordException::MOTIVO_AUSENTE,
            'regra' => $regraEfetiva,
            'orientacao' => PdfSenhaRegra::orientacao($regraEfetiva),
            'label_regra' => PdfSenhaRegra::label($regraEfetiva),
            'tem_senha_cadastrada' => $temSenhaCadastrada,
            'cartao_id' => $cartaoId,
        ];
    }

    private function isSenhaPdfErro(?string $erroCodigo): bool
    {
        return in_array($erroCodigo, [
            PdfSenhaRegra::CODIGO_SENHA_NECESSARIA,
            PdfSenhaRegra::CODIGO_SENHA_INCORRETA,
        ], true);
    }
}
