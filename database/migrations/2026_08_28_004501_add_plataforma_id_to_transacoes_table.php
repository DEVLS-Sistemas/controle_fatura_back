<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreignId('plataforma_id')
                ->nullable()
                ->after('subcategoria_id')
                ->constrained('plataformas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plataforma_id');
        });
    }
};
