<?php

namespace App\Console\Commands;

use App\Services\Categoria\CategoriaCorVariacao;
use Illuminate\Console\Command;

class BackfillCoresCategorias extends Command
{
    protected $signature = 'categorias:backfill-cores
                            {--dry-run : Só conta o que seria alterado}
                            {--user= : Limita a um user_id}';

    protected $description = 'Preenche categorias sem cor (preto) e tons das subcategorias ainda sem HEX';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $user = $this->option('user');
        $userId = $user !== null && $user !== '' ? (int) $user : null;

        $resultado = CategoriaCorVariacao::backfill($userId, $dryRun);

        $prefixo = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%s%d categoria(s) sem cor → #000000; %d vínculo(s) de subcategoria preenchido(s).',
            $prefixo,
            $resultado['categorias'],
            $resultado['vinculos']
        ));

        return self::SUCCESS;
    }
}
