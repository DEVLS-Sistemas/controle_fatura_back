<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->boolean('compra_manual')->default(false)->after('importada_pdf');
            $table->index(['user_id', 'compra_manual'], 'transacoes_user_compra_manual_idx');
        });

        $gruposComPdf = DB::table('transacoes')
            ->where('importada_pdf', true)
            ->whereNotNull('compra_grupo_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('compra_grupo_id')
            ->all();

        $manuais = DB::table('transacoes')
            ->where('tipo', 'purchase')
            ->where('importada_pdf', false)
            ->whereNull('deleted_at');

        if ($gruposComPdf !== []) {
            $manuais->where(function ($q) use ($gruposComPdf) {
                $q->whereNull('compra_grupo_id')
                    ->orWhereNotIn('compra_grupo_id', $gruposComPdf);
            });
        }

        $manuais->update(['compra_manual' => true]);

        DB::table('transacoes')
            ->where('compra_manual', false)
            ->where('status_conciliacao', 'nao_conciliada')
            ->whereNull('lancamento_id')
            ->update(['status_conciliacao' => null]);
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropIndex('transacoes_user_compra_manual_idx');
            $table->dropColumn('compra_manual');
        });
    }
};
