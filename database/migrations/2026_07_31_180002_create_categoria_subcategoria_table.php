<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categoria_subcategoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('subcategoria_id')->constrained('subcategorias')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['categoria_id', 'subcategoria_id'], 'categoria_subcategoria_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_subcategoria');
    }
};
