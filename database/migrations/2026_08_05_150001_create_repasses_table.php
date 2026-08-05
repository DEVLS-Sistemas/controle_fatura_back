<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transacao_id')->constrained('transacoes')->cascadeOnDelete();
            $table->decimal('valor', 12, 2);
            $table->date('data_pagamento');
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'transacao_id']);
            $table->index(['transacao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasses');
    }
};
