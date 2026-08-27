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
            $table->dropForeign(['estabelecimento_id']);
        });

        DB::statement('ALTER TABLE transacoes MODIFY estabelecimento_id BIGINT UNSIGNED NULL');

        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreign('estabelecimento_id')
                ->references('id')
                ->on('estabelecimentos')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropForeign(['estabelecimento_id']);
        });

        DB::statement('ALTER TABLE transacoes MODIFY estabelecimento_id BIGINT UNSIGNED NOT NULL');

        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreign('estabelecimento_id')
                ->references('id')
                ->on('estabelecimentos')
                ->restrictOnDelete();
        });
    }
};
