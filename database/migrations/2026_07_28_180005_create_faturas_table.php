<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cartao_id')->constrained('cartoes')->cascadeOnDelete();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('ano');
            $table->decimal('valor_total', 12, 2)->default(0);
            $table->string('arquivo_pdf')->nullable();
            $table->enum('status', ['pendente', 'processando', 'processada', 'erro'])->default('pendente');
            $table->text('erro_mensagem')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'cartao_id', 'mes', 'ano'], 'faturas_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};
