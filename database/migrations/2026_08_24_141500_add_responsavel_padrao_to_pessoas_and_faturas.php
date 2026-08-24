<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pessoas', function (Blueprint $table) {
            $table->foreignId('responsavel_id')
                ->nullable()
                ->after('eh_principal')
                ->constrained('responsaveis')
                ->nullOnDelete();
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->foreignId('responsavel_id')
                ->nullable()
                ->after('pessoa_id')
                ->constrained('responsaveis')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_id');
        });

        Schema::table('pessoas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_id');
        });
    }
};
