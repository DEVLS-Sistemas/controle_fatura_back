<?php

namespace App\Console\Commands;

use App\Models\Estabelecimento;
use App\Models\Transacao;
use App\Services\Estabelecimento\EstabelecimentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizarEstabelecimentosParcelas extends Command
{
    protected $signature = 'estabelecimentos:normalizar-parcelas
                            {--dry-run : Apenas lista o que seria alterado}
                            {--user= : Limita a um user_id}';

    protected $description = 'Mescla estabelecimentos cujo nome contém parcela (ex.: "Loja 1/3") no nome limpo e realoca transações';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $query = Estabelecimento::withTrashed();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $candidatos = $query->orderBy('user_id')->orderBy('nome')->get()
            ->filter(function (Estabelecimento $e) {
                return EstabelecimentoService::normalizeNome($e->nome) !== $e->nome;
            });

        $alterados = 0;
        $removidos = 0;

        foreach ($candidatos as $sujo) {
            $nomeLimpo = EstabelecimentoService::normalizeNome($sujo->nome);

            $this->line(sprintf(
                '[user %d] "%s" → "%s" (id %d)',
                $sujo->user_id,
                $sujo->nome,
                $nomeLimpo,
                $sujo->id
            ));

            if ($dryRun) {
                $alterados++;
                continue;
            }

            DB::transaction(function () use ($sujo, $nomeLimpo, &$alterados, &$removidos) {
                $limpo = Estabelecimento::withTrashed()
                    ->where('user_id', $sujo->user_id)
                    ->where('nome', $nomeLimpo)
                    ->first();

                if (!$limpo) {
                    $sujo->nome = $nomeLimpo;
                    $sujo->save();
                    $alterados++;
                    return;
                }

                if ($limpo->trashed()) {
                    $limpo->restore();
                    $limpo->ativo = true;
                    $limpo->save();
                }

                if ($limpo->id === $sujo->id) {
                    return;
                }

                $dirty = false;
                if (!$limpo->categoria_padrao_id && $sujo->categoria_padrao_id) {
                    $limpo->categoria_padrao_id = $sujo->categoria_padrao_id;
                    $limpo->subcategoria_padrao_id = $sujo->subcategoria_padrao_id;
                    $dirty = true;
                }
                if (!$limpo->plataforma_padrao_id && $sujo->plataforma_padrao_id) {
                    $limpo->plataforma_padrao_id = $sujo->plataforma_padrao_id;
                    $dirty = true;
                }
                if ($dirty) {
                    $limpo->save();
                }

                Transacao::withTrashed()
                    ->where('estabelecimento_id', $sujo->id)
                    ->update(['estabelecimento_id' => $limpo->id]);

                $sujo->delete();
                $removidos++;
                $alterados++;
            });
        }

        $this->info($dryRun
            ? "Dry-run: {$alterados} estabelecimento(s) seriam normalizados."
            : "Concluído: {$alterados} normalizado(s), {$removidos} duplicata(s) removida(s)."
        );

        return self::SUCCESS;
    }
}
