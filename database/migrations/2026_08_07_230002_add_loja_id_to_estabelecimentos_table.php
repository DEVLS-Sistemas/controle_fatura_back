<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->foreignId('loja_id')
                ->nullable()
                ->after('nome')
                ->constrained('lojas')
                ->nullOnDelete();

            $table->index(['user_id', 'loja_id']);
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'loja_id']);
            $table->dropConstrainedForeignId('loja_id');
        });
    }
};
