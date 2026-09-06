<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('origem', 32);
            $table->unsignedBigInteger('referencia_id');
            $table->string('nome_original');
            $table->string('mime', 120)->nullable();
            $table->string('extensao', 16)->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->nullable();
            $table->char('hash', 64)->nullable();
            $table->text('url')->nullable();
            $table->string('container', 128)->nullable();
            $table->string('blob_path', 1024)->nullable();
            $table->string('disk', 32)->default('azure');
            $table->string('status', 32)->default('pendente');
            $table->text('erro_mensagem')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['origem', 'referencia_id'], 'anexos_origem_referencia_idx');
            $table->index('hash', 'anexos_hash_idx');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->foreignId('anexo_pdf_id')->nullable()->unique()->after('anexo_hash')
                ->constrained('anexos')->nullOnDelete();
            $table->foreignId('anexo_csv_id')->nullable()->unique()->after('anexo_pdf_id')
                ->constrained('anexos')->nullOnDelete();
        });

        Schema::table('compra_anexos', function (Blueprint $table) {
            $table->foreignId('anexo_id')->nullable()->unique()->after('tipo')
                ->constrained('anexos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compra_anexos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anexo_id');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anexo_csv_id');
            $table->dropConstrainedForeignId('anexo_pdf_id');
        });

        Schema::dropIfExists('anexos');
    }
};
