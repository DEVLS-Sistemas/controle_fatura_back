<?php

namespace App\Services\Fatura;

use App\Models\Fatura;
use App\Models\Transacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uma fatura ativa por (usuário, cartão, bandeira, mês, ano).
 * Stub sem bandeira conta na mesma chave da bandeira do cartão.
 */
class FaturaPeriodoUnicidadeService
{
    public function __construct(private readonly FaturaService $faturaService) {}

    /**
     * Encontra a fatura do período, funde duplicatas e opcionalmente restaura a apagada.
     */
    public function obterEConsolidar(
        int $userId,
        int $cartaoId,
        int $mes,
        int $ano,
        ?int $bandeiraId,
        bool $restaurarSeApagada = false
    ): ?Fatura {
        return DB::transaction(function () use ($userId, $cartaoId, $mes, $ano, $bandeiraId, $restaurarSeApagada) {
            $existentes = $this->queryExistentes($userId, $cartaoId, $mes, $ano, $bandeiraId)
                ->lockForUpdate()
                ->get();

            if ($existentes->isEmpty()) {
                return null;
            }

            return $this->consolidarColecao($existentes, $bandeiraId, $restaurarSeApagada);
        });
    }

    public function consolidarDuplicatasDoUsuario(int $userId): int
    {
        $faturas = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->get(['id', 'cartao_id', 'cartao_bandeira_id', 'mes', 'ano']);

        $grupos = $faturas->groupBy(
            fn (Fatura $f) => (int) $f->cartao_id.'|'.(int) $f->mes.'|'.(int) $f->ano
        );

        $fundidas = 0;
        foreach ($grupos as $grupo) {
            if ($grupo->count() < 2) {
                continue;
            }

            $bandeiras = $grupo->pluck('cartao_bandeira_id')->unique()->all();
            foreach ($bandeiras as $bandeira) {
                $bandeiraId = $bandeira !== null ? (int) $bandeira : null;
                $antes = $this->queryExistentes(
                    $userId,
                    (int) $grupo->first()->cartao_id,
                    (int) $grupo->first()->mes,
                    (int) $grupo->first()->ano,
                    $bandeiraId
                )->count();
                if ($antes < 2) {
                    continue;
                }

                $this->obterEConsolidar(
                    $userId,
                    (int) $grupo->first()->cartao_id,
                    (int) $grupo->first()->mes,
                    (int) $grupo->first()->ano,
                    $bandeiraId,
                    false
                );
                $fundidas++;
            }
        }

        return $fundidas;
    }

    /**
     * @param  Collection<int, Fatura>  $faturas
     */
    public static function escolherCanonico(Collection $faturas): ?Fatura
    {
        if ($faturas->isEmpty()) {
            return null;
        }

        return $faturas->sortBy(function (Fatura $f) {
            $attrs = $f->getAttributes();
            $ativa = empty($attrs['deleted_at'] ?? null) ? 0 : 1;
            $temAnexo = (! empty($attrs['arquivo_pdf'] ?? null) || ! empty($attrs['arquivo_csv'] ?? null)) ? 0 : 1;
            $processada = (string) ($attrs['status'] ?? $f->status) === 'processada' ? 0 : 1;
            $id = (int) ($attrs['id'] ?? $f->id);

            return sprintf('%d%d%d%020d', $ativa, $temAnexo, $processada, $id);
        })->first();
    }

    public static function chaveTransacao(Transacao $t): string
    {
        $attrs = $t->getAttributes();
        $data = $attrs['data'] ?? $t->data;
        $dataStr = $data instanceof \DateTimeInterface
            ? $data->format('Y-m-d')
            : (string) $data;

        return implode('|', [
            (string) ($attrs['tipo'] ?? $t->tipo ?? ''),
            (string) ((int) ($attrs['estabelecimento_id'] ?? $t->estabelecimento_id ?? 0)),
            $dataStr,
            number_format((float) ($attrs['valor'] ?? $t->valor ?? 0), 2, '.', ''),
            (string) ((int) ($attrs['parcela_atual'] ?? $t->parcela_atual ?? 0)),
            (string) ((int) ($attrs['parcelas_total'] ?? $t->parcelas_total ?? 0)),
        ]);
    }

    /**
     * @return Builder<Fatura>
     */
    public function queryExistentes(
        int $userId,
        int $cartaoId,
        int $mes,
        int $ano,
        ?int $bandeiraId
    ): Builder {
        $query = Fatura::withTrashed()
            ->where('user_id', $userId)
            ->where('cartao_id', $cartaoId)
            ->where('mes', $mes)
            ->where('ano', $ano);

        if ($bandeiraId !== null) {
            $query->where(function ($q) use ($bandeiraId) {
                $q->where('cartao_bandeira_id', $bandeiraId)
                    ->orWhereNull('cartao_bandeira_id');
            });
        } else {
            $query->whereNull('cartao_bandeira_id');
        }

        return $query->orderByRaw('deleted_at IS NULL DESC')->orderBy('id');
    }

    /**
     * @param  Collection<int, Fatura>  $existentes
     */
    private function consolidarColecao(
        Collection $existentes,
        ?int $bandeiraId,
        bool $restaurarSeApagada
    ): Fatura {
        $canonico = self::escolherCanonico($existentes);
        if ($canonico === null) {
            throw new \RuntimeException('Coleção de faturas do período vazia');
        }

        $perdedoras = $existentes->filter(
            fn (Fatura $f) => (int) $f->id !== (int) $canonico->id
        );

        foreach ($perdedoras as $perdedora) {
            $this->fundirFaturaNaCanonico($perdedora, $canonico);
        }

        if ($bandeiraId !== null && $canonico->cartao_bandeira_id === null) {
            $canonico->cartao_bandeira_id = $bandeiraId;
            $canonico->save();
        }

        if ($canonico->trashed()) {
            if ($restaurarSeApagada) {
                $canonico->restore();
                $canonico->fill(FaturaService::atributosStubSemAnexo());
                $canonico->save();
            }
        }

        $canonico = $canonico->fresh() ?? $canonico;
        if (! $canonico->trashed()) {
            $this->faturaService->recalculateValorTotal((int) $canonico->id);
        }

        if ($perdedoras->isNotEmpty()) {
            Log::info('Faturas duplicadas do mesmo período foram unificadas', [
                'canonico' => (int) $canonico->id,
                'removidas' => $perdedoras->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'competencia' => sprintf('%02d/%d', (int) $canonico->mes, (int) $canonico->ano),
            ]);
        }

        return $canonico->fresh() ?? $canonico;
    }

    private function fundirFaturaNaCanonico(Fatura $perdedora, Fatura $canonico): void
    {
        $this->herdarAnexoSeFaltar($perdedora, $canonico);
        $this->moverTransacoes($perdedora, $canonico);

        if ($canonico->pessoa_id === null && $perdedora->pessoa_id !== null) {
            $canonico->pessoa_id = $perdedora->pessoa_id;
            $canonico->responsavel_id = $perdedora->responsavel_id ?? $canonico->responsavel_id;
            $canonico->save();
        }

        $this->apagarArquivosNaoTransferidos($perdedora);
        $perdedora->forceDelete();
    }

    private function herdarAnexoSeFaltar(Fatura $perdedora, Fatura $canonico): void
    {
        $alterou = false;

        if (empty($canonico->arquivo_pdf) && ! empty($perdedora->arquivo_pdf)) {
            $canonico->arquivo_pdf = $perdedora->arquivo_pdf;
            $perdedora->arquivo_pdf = null;
            $alterou = true;
        }
        if (empty($canonico->arquivo_csv) && ! empty($perdedora->arquivo_csv)) {
            $canonico->arquivo_csv = $perdedora->arquivo_csv;
            $perdedora->arquivo_csv = null;
            $alterou = true;
        }
        if (empty($canonico->anexo_hash) && ! empty($perdedora->anexo_hash)) {
            $canonico->anexo_hash = $perdedora->anexo_hash;
            $alterou = true;
        }
        if ((string) $canonico->status !== 'processada' && (string) $perdedora->status === 'processada') {
            $canonico->status = 'processada';
            $canonico->processado_em = $perdedora->processado_em ?? $canonico->processado_em;
            $alterou = true;
        }

        if ($alterou) {
            $canonico->save();
            $perdedora->save();
        }
    }

    private function moverTransacoes(Fatura $perdedora, Fatura $canonico): void
    {
        $ocupadas = [];
        $doCanonico = Transacao::withTrashed()
            ->where('fatura_id', $canonico->id)
            ->get();
        foreach ($doCanonico as $tx) {
            $ocupadas[self::chaveTransacao($tx)] = true;
        }

        $daPerdedora = Transacao::withTrashed()
            ->where('fatura_id', $perdedora->id)
            ->orderBy('id')
            ->get();

        foreach ($daPerdedora as $tx) {
            $chave = self::chaveTransacao($tx);
            if (isset($ocupadas[$chave])) {
                if ($tx->deleted_at === null) {
                    $tx->delete();
                }

                continue;
            }

            $tx->fatura_id = $canonico->id;
            $tx->save();
            if ($tx->deleted_at === null) {
                $ocupadas[$chave] = true;
            }
        }

        Transacao::withTrashed()
            ->where('fatura_origem_id', $perdedora->id)
            ->update(['fatura_origem_id' => $canonico->id]);
    }

    private function apagarArquivosNaoTransferidos(Fatura $perdedora): void
    {
        foreach ([$perdedora->arquivo_pdf, $perdedora->arquivo_csv] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
