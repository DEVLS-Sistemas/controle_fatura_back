<?php

namespace App\Services\Fatura;

use App\Models\Transacao;
use App\Services\Transacao\ConciliacaoMatcher;
use Illuminate\Support\Collection;

/**
 * Casa linhas do extrato novo com as transações da mesma fatura (substituir PDF).
 *
 * Mesmo estabelecimento + valor + parcela → atualiza (mantém id, categoria e responsável).
 * Linha nova → cria. Importada que saiu do extrato → remove. Compra manual permanece.
 */
class FaturaReprocessarTransacoesService
{
    /**
     * @param  Collection<int, Transacao>  $existing
     * @param  list<array{
     *     estabelecimento_id: int,
     *     valor: float,
     *     parcela_atual?: mixed,
     *     parcelas_total?: mixed
     * }>  $linhasPdf
     * @return array{
     *     atualizar_ids: list<int>,
     *     criar: list<array<string, mixed>>,
     *     remover_ids: list<int>,
     *     preservar_manuais_ids: list<int>
     * }
     */
    public function classificar($existing, array $linhasPdf): array
    {
        $keptImportIds = [];
        $atualizarIds = [];
        $criar = [];

        foreach ($linhasPdf as $item) {
            $match = $this->findMatchingTransacao(
                $existing,
                $keptImportIds,
                (int) $item['estabelecimento_id'],
                (float) $item['valor'],
                $item['parcela_atual'] ?? null,
                $item['parcelas_total'] ?? null
            );

            if ($match) {
                $keptImportIds[] = (int) $match->id;
                $atualizarIds[] = (int) $match->id;
                continue;
            }

            $criar[] = $item;
        }

        $removerIds = [];
        $preservarManuaisIds = [];
        foreach ($existing as $transacao) {
            $id = (int) $transacao->id;
            $kept = in_array($id, $keptImportIds, true);
            $importada = (bool) $transacao->importada_pdf;
            $manual = (bool) $transacao->compra_manual;
            $criadaManual = (bool) $transacao->criada_como_manual;

            if ($kept) {
                continue;
            }

            if (self::deveRemoverNoReprocesso($kept, $importada, $manual, $criadaManual)) {
                $removerIds[] = $id;
                continue;
            }

            if ($manual || $criadaManual) {
                $preservarManuaisIds[] = $id;
            }
        }

        return [
            'atualizar_ids' => $atualizarIds,
            'criar' => $criar,
            'remover_ids' => $removerIds,
            'preservar_manuais_ids' => $preservarManuaisIds,
        ];
    }

    /**
     * Importada (ou stub automático) fora do extrato novo sai; compra manual fica.
     */
    public static function deveRemoverNoReprocesso(
        bool $kept,
        bool $importadaPdf,
        bool $compraManual,
        bool $criadaComoManual
    ): bool {
        if ($kept) {
            return false;
        }

        if ($importadaPdf) {
            return true;
        }

        return ! $compraManual && ! $criadaComoManual;
    }

    /**
     * Campos gravados no match. Categoria e responsável já preenchidos não entram.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function camposAtualizacaoDoMatch(
        Transacao $match,
        array $item,
        float $valor,
        int $faturaId,
        ?int $cartaoNumeroId,
        ?int $responsavelIdPadrao,
        ?int $plataformaPadraoId,
        ?int $estabelecimentoId = null
    ): array {
        $eraManual = (bool) $match->compra_manual;
        $update = [
            'data' => $item['data'] ?? $match->data,
            'valor' => $valor,
            'parcelas_total' => $item['parcelas_total'] ?? null,
            'parcela_atual' => $item['parcela_atual'] ?? null,
            'valor_parcela' => $item['valor_parcela'] ?? null,
            'tipo' => $item['tipo'] ?? $match->tipo,
            'importada_pdf' => true,
            'compra_manual' => false,
            'fatura_origem_id' => $faturaId,
        ];

        if ($estabelecimentoId !== null) {
            $update['estabelecimento_id'] = $estabelecimentoId;
        }

        if ($eraManual || (bool) $match->criada_como_manual) {
            $update['criada_como_manual'] = true;
        }
        if ($cartaoNumeroId !== null) {
            $update['cartao_numero_id'] = $cartaoNumeroId;
        }
        if ($match->responsavel_id === null && $responsavelIdPadrao !== null) {
            $update['responsavel_id'] = $responsavelIdPadrao;
        }
        if ($match->plataforma_id === null && $plataformaPadraoId) {
            $update['plataforma_id'] = $plataformaPadraoId;
        }

        return $update;
    }

    /**
     * @param  Collection<int, Transacao>  $existing
     * @param  array<int, int>  $matchedIds
     */
    public function findMatchingTransacao(
        $existing,
        array $matchedIds,
        int $estabelecimentoId,
        float $valor,
        mixed $parcelaAtual,
        mixed $parcelasTotal
    ): ?Transacao {
        foreach ($existing as $transacao) {
            if (in_array($transacao->id, $matchedIds, true)) {
                continue;
            }

            if ((int) $transacao->estabelecimento_id !== $estabelecimentoId) {
                continue;
            }

            if (! $this->valoresDoMatchCompativeis((float) $transacao->valor, $valor, $parcelasTotal)) {
                continue;
            }

            if ((int) ($transacao->parcela_atual ?? 0) !== (int) ($parcelaAtual ?? 0)) {
                continue;
            }

            if ((int) ($transacao->parcelas_total ?? 0) !== (int) ($parcelasTotal ?? 0)) {
                continue;
            }

            return $transacao;
        }

        return $this->findMatchingParcelaPorValor(
            $existing,
            $matchedIds,
            $valor,
            $parcelaAtual,
            $parcelasTotal
        );
    }

    /**
     * Stub materializado cuja maquininha mudou de nome entre faturas:
     * reusa a linha se for a única parcela N/M com o mesmo valor nesta fatura.
     *
     * @param  Collection<int, Transacao>  $existing
     * @param  array<int, int>  $matchedIds
     */
    private function findMatchingParcelaPorValor(
        $existing,
        array $matchedIds,
        float $valor,
        mixed $parcelaAtual,
        mixed $parcelasTotal
    ): ?Transacao {
        if ((int) ($parcelasTotal ?? 0) <= 1) {
            return null;
        }

        $candidatos = [];
        foreach ($existing as $transacao) {
            if (in_array($transacao->id, $matchedIds, true)) {
                continue;
            }
            if ((int) ($transacao->parcela_atual ?? 0) !== (int) ($parcelaAtual ?? 0)) {
                continue;
            }
            if ((int) ($transacao->parcelas_total ?? 0) !== (int) ($parcelasTotal ?? 0)) {
                continue;
            }
            if (! $this->valoresDoMatchCompativeis((float) $transacao->valor, $valor, $parcelasTotal)) {
                continue;
            }
            $candidatos[] = $transacao;
        }

        return count($candidatos) === 1 ? $candidatos[0] : null;
    }

    private function valoresDoMatchCompativeis(float $a, float $b, mixed $parcelasTotal): bool
    {
        if ((int) ($parcelasTotal ?? 0) > 1) {
            return ConciliacaoMatcher::valoresParcelasCompativeis($a, $b);
        }

        return ConciliacaoMatcher::valoresCompativeis($a, $b);
    }
}
