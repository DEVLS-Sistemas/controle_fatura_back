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
            $table->unsignedBigInteger('fatura_origem_id')->nullable()->after('fatura_id');
            $table->boolean('criada_como_manual')->default(false)->after('compra_manual');
            $table->foreign('fatura_origem_id')
                ->references('id')
                ->on('faturas')
                ->nullOnDelete();
            $table->index(['user_id', 'fatura_origem_id'], 'transacoes_user_fatura_origem_idx');
        });

        DB::table('transacoes')
            ->where('compra_manual', true)
            ->update(['criada_como_manual' => true]);

        $idsCriadas = DB::table('compra_historicos')
            ->where('acao', 'criada')
            ->pluck('transacao_id')
            ->all();
        if ($idsCriadas !== []) {
            DB::table('transacoes')
                ->whereIn('id', $idsCriadas)
                ->update(['criada_como_manual' => true]);
        }

        $idsMatchExato = DB::table('compra_historicos')
            ->where('acao', 'conciliada')
            ->where('descricao', 'like', 'Conciliada automaticamente%')
            ->pluck('transacao_id')
            ->all();
        if ($idsMatchExato !== []) {
            DB::table('transacoes')
                ->whereIn('id', $idsMatchExato)
                ->update(['criada_como_manual' => true]);
        }

        DB::table('transacoes')
            ->where('importada_pdf', true)
            ->whereNull('fatura_origem_id')
            ->update(['fatura_origem_id' => DB::raw('fatura_id')]);

        $primeiraImportadaPorGrupo = DB::table('transacoes')
            ->select('compra_grupo_id', DB::raw('MIN(id) as id'))
            ->where('importada_pdf', true)
            ->whereNotNull('compra_grupo_id')
            ->whereNull('deleted_at')
            ->groupBy('compra_grupo_id')
            ->get();

        foreach ($primeiraImportadaPorGrupo as $grupo) {
            $origemId = DB::table('transacoes')->where('id', $grupo->id)->value('fatura_id');
            if (!$origemId) {
                continue;
            }

            DB::table('transacoes')
                ->where('compra_grupo_id', $grupo->compra_grupo_id)
                ->where('importada_pdf', false)
                ->where('compra_manual', false)
                ->whereNull('fatura_origem_id')
                ->whereNull('deleted_at')
                ->update(['fatura_origem_id' => $origemId]);
        }
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropIndex('transacoes_user_fatura_origem_idx');
            $table->dropForeign(['fatura_origem_id']);
            $table->dropColumn(['fatura_origem_id', 'criada_como_manual']);
        });
    }
};
