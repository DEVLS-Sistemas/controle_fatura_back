<?php

namespace App\Console\Commands;

use App\Services\Estabelecimento\EstabelecimentoService;
use Illuminate\Console\Command;

class InferirPlataformaEstabelecimentos extends Command
{
    protected $signature = 'plataformas:inferir-estabelecimentos
                            {--dry-run : Só conta o que seria alterado}
                            {--user= : Limita a um user_id}';

    protected $description = 'Infere plataforma padrão pelo nome do estabelecimento (Mercadolivre*Mercadol → Mercado Livre) e preenche compras vazias';

    public function handle(EstabelecimentoService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $user = $this->option('user');
        $userId = $user !== null && $user !== '' ? (int) $user : null;

        $resultado = $service->backfillPlataformaPadraoPorNome($userId, $dryRun);

        $prefixo = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%s%d estabelecimento(s) com plataforma inferida; %d compra(s) preenchida(s).',
            $prefixo,
            $resultado['estabelecimentos'],
            $resultado['transacoes']
        ));

        return self::SUCCESS;
    }
}
