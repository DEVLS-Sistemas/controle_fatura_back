<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartao_bandeiras', function (Blueprint $table) {
            $table->string('cor_principal', 20)->nullable()->after('limite_credito');
            $table->string('cor_secundaria', 20)->nullable()->after('cor_principal');
        });
    }

    public function down(): void
    {
        Schema::table('cartao_bandeiras', function (Blueprint $table) {
            $table->dropColumn(['cor_principal', 'cor_secundaria']);
        });
    }
};
