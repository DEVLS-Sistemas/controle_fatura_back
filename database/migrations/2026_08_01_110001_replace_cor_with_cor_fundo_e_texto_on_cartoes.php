<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->string('cor_fundo', 20)->nullable()->after('dia_vencimento_fatura');
            $table->string('cor_texto', 20)->nullable()->after('cor_fundo');
        });

        if (Schema::hasColumn('cartoes', 'cor')) {
            DB::table('cartoes')->update([
                'cor_fundo' => DB::raw('cor'),
                'cor_texto' => DB::raw("CASE WHEN cor IS NOT NULL THEN '#ffffff' ELSE NULL END"),
            ]);

            Schema::table('cartoes', function (Blueprint $table) {
                $table->dropColumn('cor');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->string('cor', 20)->nullable()->after('dia_vencimento_fatura');
        });

        DB::table('cartoes')->update([
            'cor' => DB::raw('cor_fundo'),
        ]);

        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn(['cor_fundo', 'cor_texto']);
        });
    }
};
