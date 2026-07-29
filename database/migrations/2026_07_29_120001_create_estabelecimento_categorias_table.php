<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('estabelecimento');
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'estabelecimento'], 'estabelecimento_categorias_user_estab_unique');
            $table->index(['user_id', 'categoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_categorias');
    }
};
