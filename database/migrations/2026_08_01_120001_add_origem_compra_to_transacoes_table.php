<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->enum('origem_compra', [
                'COMPRAS_ONLINE',
                'COMPRAS_PRESENCIAL',
                'PAGAMENTO_SERVICOS',
                'PAGAMENTO_FATURA',
            ])->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropColumn('origem_compra');
        });
    }
};
