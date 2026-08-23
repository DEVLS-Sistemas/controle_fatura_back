<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE transacoes MODIFY COLUMN tipo ENUM('purchase', 'payment', 'refund', 'advance', 'fee', 'carryover') NOT NULL DEFAULT 'purchase'"
        );

        DB::statement("
            UPDATE transacoes t
            INNER JOIN estabelecimentos e ON e.id = t.estabelecimento_id
            SET t.tipo = 'carryover'
            WHERE t.deleted_at IS NULL
              AND t.tipo = 'purchase'
              AND (
                    LOWER(e.nome) LIKE '%saldo restante da fatura anterior%'
                 OR LOWER(e.nome) LIKE '%saldo da fatura anterior%'
              )
        ");
    }

    public function down(): void
    {
        DB::table('transacoes')
            ->where('tipo', 'carryover')
            ->update(['tipo' => 'purchase']);

        DB::statement(
            "ALTER TABLE transacoes MODIFY COLUMN tipo ENUM('purchase', 'payment', 'refund', 'advance', 'fee') NOT NULL DEFAULT 'purchase'"
        );
    }
};
