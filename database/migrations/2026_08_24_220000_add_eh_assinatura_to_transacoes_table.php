<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->boolean('eh_assinatura')->default(false)->after('origem_compra');
        });
    }

    public function down(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropColumn('eh_assinatura');
        });
    }
};
