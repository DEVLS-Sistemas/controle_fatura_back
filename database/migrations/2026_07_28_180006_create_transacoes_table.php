<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fatura_id')->constrained('faturas')->cascadeOnDelete();
            $table->date('data')->nullable();
            $table->string('estabelecimento');
            $table->decimal('valor', 12, 2);
            $table->unsignedInteger('parcelas_total')->nullable();
            $table->unsignedInteger('parcela_atual')->nullable();
            $table->decimal('valor_parcela', 12, 2)->nullable();
            $table->enum('tipo', ['purchase', 'payment', 'refund', 'advance'])->default('purchase');
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('responsaveis')->nullOnDelete();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'data']);
            $table->index(['user_id', 'tipo']);
            $table->index(['user_id', 'categoria_id']);
            $table->index(['user_id', 'responsavel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transacoes');
    }
};
