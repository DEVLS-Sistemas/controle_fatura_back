<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE transacoes MODIFY COLUMN tipo ENUM('purchase', 'payment', 'refund', 'advance', 'fee') NOT NULL DEFAULT 'purchase'"
        );

        // Reclassifica encargos já importados como compra (juros, multa, IOF, etc.).
        DB::statement("
            UPDATE transacoes t
            INNER JOIN estabelecimentos e ON e.id = t.estabelecimento_id
            SET t.tipo = 'fee'
            WHERE t.tipo = 'purchase'
              AND t.deleted_at IS NULL
              AND (
                    LOWER(e.nome) LIKE '%juros%'
                 OR LOWER(e.nome) LIKE '%multa%'
                 OR LOWER(e.nome) LIKE '%iof%'
                 OR LOWER(e.nome) LIKE '%encargo%'
                 OR LOWER(e.nome) LIKE '%mora%'
                 OR LOWER(e.nome) LIKE '%rotativo%'
                 OR LOWER(e.nome) LIKE '%financiamento%'
              )
        ");
    }

    public function down(): void
    {
        DB::table('transacoes')
            ->where('tipo', 'fee')
            ->update(['tipo' => 'purchase']);

        DB::statement(
            "ALTER TABLE transacoes MODIFY COLUMN tipo ENUM('purchase', 'payment', 'refund', 'advance') NOT NULL DEFAULT 'purchase'"
        );
    }
};
