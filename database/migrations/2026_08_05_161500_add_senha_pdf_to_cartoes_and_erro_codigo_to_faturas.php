<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->text('senha_pdf')->nullable()->after('ativo');
            $table->string('senha_pdf_regra', 64)->nullable()->after('senha_pdf');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->string('erro_codigo', 64)->nullable()->after('erro_mensagem');
        });
    }

    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn(['senha_pdf', 'senha_pdf_regra']);
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropColumn('erro_codigo');
        });
    }
};
