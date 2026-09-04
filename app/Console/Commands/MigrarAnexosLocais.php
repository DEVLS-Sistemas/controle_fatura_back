<?php

namespace App\Console\Commands;

use App\Enums\AnexoOrigem;
use App\Services\Anexo\AnexoMigracaoLocalService;
use Illuminate\Console\Command;

class MigrarAnexosLocais extends Command
{
    protected $signature = 'anexos:migrar-local
                            {--dry-run : Lista o que seria enviado sem gravar}
                            {--origem= : fatura ou compra}
                            {--limit= : Máximo de arquivos a processar}
                            {--purge : Apaga o arquivo local só depois de status=enviado}';

    protected $description = 'Migra PDFs/CSVs de fatura e anexos de compra do disk local para o Azure';

    public function handle(AnexoMigracaoLocalService $service): int
    {
        $origem = $this->resolverOrigem();
        if ($origem === false) {
            return self::FAILURE;
        }

        $limit = $this->resolverLimit();
        if ($limit === false) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');

        $relatorio = $service->executar($dryRun, $origem, $limit, $purge);

        if ($relatorio['itens'] !== []) {
            $this->table(
                ['Origem', 'Dono', 'ID', 'Path', 'Status', 'Motivo'],
                array_map(fn (array $item) => [
                    $item['origem'],
                    $item['dono'],
                    $item['dono_id'],
                    $item['path'],
                    $item['status'],
                    $item['motivo'],
                ], $relatorio['itens'])
            );
        }

        $prefixo = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%sMigrados: %d | Falhou: %d | Pulados: %d',
            $prefixo,
            $relatorio['migrados'],
            $relatorio['falhou'],
            $relatorio['pulados']
        ));

        return $relatorio['falhou'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolverOrigem(): AnexoOrigem|false|null
    {
        $raw = trim((string) $this->option('origem'));
        if ($raw === '') {
            return null;
        }

        $origem = AnexoOrigem::tryFrom(strtolower($raw));
        if ($origem === null) {
            $this->error('Use --origem=fatura ou --origem=compra.');

            return false;
        }

        return $origem;
    }

    private function resolverLimit(): int|false|null
    {
        $raw = $this->option('limit');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('Use --limit com um inteiro positivo.');

            return false;
        }

        return (int) $raw;
    }
}
