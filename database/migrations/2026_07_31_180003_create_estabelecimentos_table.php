<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nome');
            $table->foreignId('categoria_padrao_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('subcategoria_padrao_id')->nullable()->constrained('subcategorias')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'nome'], 'estabelecimentos_user_nome_unique');
            $table->index(['user_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimentos');
    }
};
