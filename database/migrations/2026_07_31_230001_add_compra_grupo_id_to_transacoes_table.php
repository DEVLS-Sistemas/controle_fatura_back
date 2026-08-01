<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->uuid('compra_grupo_id')->nullable()->after('valor_parcela');
            $table->index(['user_id', 'compra_grupo_id'], 'transacoes_user_compra_grupo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropIndex('transacoes_user_compra_grupo_idx');
            $table->dropColumn('compra_grupo_id');
        });
    }
};
