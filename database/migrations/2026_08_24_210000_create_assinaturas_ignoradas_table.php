<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assinaturas_ignoradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo_chave', 32);
            $table->unsignedBigInteger('referencia_id');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'tipo_chave', 'referencia_id'], 'assinaturas_ignoradas_chave_unique');
            $table->index(['user_id', 'tipo_chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assinaturas_ignoradas');
    }
};
