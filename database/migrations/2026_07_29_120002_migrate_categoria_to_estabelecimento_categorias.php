<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transacoes', 'categoria_id')) {
            $rows = DB::table('transacoes')
                ->select('user_id', 'estabelecimento', 'categoria_id')
                ->whereNotNull('categoria_id')
                ->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            $seen = [];
            $now = now();

            foreach ($rows as $row) {
                $key = $row->user_id . '|' . $row->estabelecimento;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $exists = DB::table('estabelecimento_categorias')
                    ->where('user_id', $row->user_id)
                    ->where('estabelecimento', $row->estabelecimento)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('estabelecimento_categorias')->insert([
                    'user_id' => $row->user_id,
                    'estabelecimento' => $row->estabelecimento,
                    'categoria_id' => $row->categoria_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('transacoes', function (Blueprint $table) {
                $table->dropForeign(['categoria_id']);
                $table->dropIndex(['user_id', 'categoria_id']);
                $table->dropColumn('categoria_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('transacoes', 'categoria_id')) {
            Schema::table('transacoes', function (Blueprint $table) {
                $table->foreignId('categoria_id')->nullable()->after('tipo')->constrained('categorias')->nullOnDelete();
                $table->index(['user_id', 'categoria_id']);
            });

            $rows = DB::table('estabelecimento_categorias')
                ->whereNull('deleted_at')
                ->get();

            foreach ($rows as $row) {
                DB::table('transacoes')
                    ->where('user_id', $row->user_id)
                    ->where('estabelecimento', $row->estabelecimento)
                    ->whereNull('deleted_at')
                    ->update(['categoria_id' => $row->categoria_id]);
            }
        }
    }
};
