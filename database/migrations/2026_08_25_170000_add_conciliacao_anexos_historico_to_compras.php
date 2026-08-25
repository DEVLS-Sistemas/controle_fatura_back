<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->string('descricao')->nullable()->after('observacoes');
            $table->string('descricao_fatura')->nullable()->after('descricao');
            $table->string('status_conciliacao', 32)->nullable()->after('descricao_fatura');
            $table->foreignId('lancamento_id')->nullable()->after('status_conciliacao')
                ->constrained('transacoes')->nullOnDelete();
            $table->boolean('ignorar_no_total')->default(false)->after('lancamento_id');

            $table->index(['user_id', 'status_conciliacao'], 'transacoes_user_conciliacao_idx');
            $table->index(['user_id', 'ignorar_no_total'], 'transacoes_user_ignorar_total_idx');
        });

        Schema::create('compra_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transacao_id')->constrained('transacoes')->cascadeOnDelete();
            $table->uuid('compra_grupo_id')->nullable();
            $table->string('nome_original');
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('tamanho')->nullable();
            $table->string('tipo', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'transacao_id']);
            $table->index(['user_id', 'compra_grupo_id']);
        });

        Schema::create('compra_historicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transacao_id')->constrained('transacoes')->cascadeOnDelete();
            $table->uuid('compra_grupo_id')->nullable();
            $table->string('acao', 64);
            $table->text('descricao')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'transacao_id']);
            $table->index(['user_id', 'compra_grupo_id']);
        });

        DB::table('transacoes')
            ->where('importada_pdf', false)
            ->where('tipo', 'purchase')
            ->whereNull('status_conciliacao')
            ->update(['status_conciliacao' => 'nao_conciliada']);
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_historicos');
        Schema::dropIfExists('compra_anexos');

        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropForeign(['lancamento_id']);
            $table->dropIndex('transacoes_user_conciliacao_idx');
            $table->dropIndex('transacoes_user_ignorar_total_idx');
            $table->dropColumn([
                'descricao',
                'descricao_fatura',
                'status_conciliacao',
                'lancamento_id',
                'ignorar_no_total',
            ]);
        });
    }
};
