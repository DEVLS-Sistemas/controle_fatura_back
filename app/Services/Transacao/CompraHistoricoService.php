<?php

namespace App\Services\Transacao;

use App\Models\CompraHistorico;
use App\Models\Transacao;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CompraHistoricoService
{
    public function registrar(
        Transacao $transacao,
        string $acao,
        ?string $descricao = null,
        ?array $payload = null
    ): CompraHistorico {
        return CompraHistorico::create([
            'user_id' => (int) $transacao->user_id,
            'transacao_id' => (int) $transacao->id,
            'compra_grupo_id' => $transacao->compra_grupo_id ?: null,
            'acao' => $acao,
            'descricao' => $descricao,
            'payload' => $payload,
        ]);
    }

    public function handleListar(string $identificador): object
    {
        $userId = (int) Auth::id();
        $ancora = $this->resolverAncora($userId, $identificador);

        $query = CompraHistorico::where('user_id', $userId)
            ->orderByDesc('id');

        if (!empty($ancora->compra_grupo_id)) {
            $query->where(function ($q) use ($ancora) {
                $q->where('compra_grupo_id', $ancora->compra_grupo_id)
                    ->orWhere('transacao_id', $ancora->id);
            });
        } else {
            $query->where('transacao_id', $ancora->id);
        }

        $itens = $query->get()->map(fn (CompraHistorico $item) => [
            'id' => (int) $item->id,
            'acao' => $item->acao,
            'descricao' => $item->descricao,
            'payload' => $item->payload,
            'created_at' => $item->created_at?->toIso8601String(),
        ])->values()->all();

        return (object) [
            'data' => $itens,
            'status' => true,
            'message' => 'Histórico carregado com sucesso!',
        ];
    }

    private function resolverAncora(int $userId, string $identificador): Transacao
    {
        $identificador = trim($identificador);

        $query = Transacao::where('user_id', $userId)->where('tipo', Transacao::TIPO_PURCHASE);

        if (Str::isUuid($identificador)) {
            $record = (clone $query)->where('compra_grupo_id', $identificador)
                ->orderBy('parcela_atual')
                ->first();
        } elseif (ctype_digit($identificador)) {
            $record = (clone $query)->where('id', (int) $identificador)->first();
        } else {
            $record = null;
        }

        if (!$record) {
            throw new Exception('Compra não encontrada', 404);
        }

        return $record;
    }
}
