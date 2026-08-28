<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->char('anexo_hash', 64)->nullable()->after('arquivo_csv');
            $table->index(['user_id', 'anexo_hash'], 'faturas_user_anexo_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropIndex('faturas_user_anexo_hash_idx');
            $table->dropColumn('anexo_hash');
        });
    }
};
