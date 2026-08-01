<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('dia_limite_fatura')->nullable()->after('ultimos_digitos');
            $table->unsignedTinyInteger('dia_vencimento_fatura')->nullable()->after('dia_limite_fatura');
            $table->string('cor', 20)->nullable()->after('dia_vencimento_fatura');
        });
    }

    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn(['dia_limite_fatura', 'dia_vencimento_fatura', 'cor']);
        });
    }
};
