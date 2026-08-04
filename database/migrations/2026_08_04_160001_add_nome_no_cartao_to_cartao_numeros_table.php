<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartao_numeros', function (Blueprint $table) {
            $table->string('nome_no_cartao')->nullable()->after('apelido');
        });
    }

    public function down(): void
    {
        Schema::table('cartao_numeros', function (Blueprint $table) {
            $table->dropColumn('nome_no_cartao');
        });
    }
};
