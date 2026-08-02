<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->decimal('limite_credito', 12, 2)->nullable()->after('ultimos_digitos');
        });
    }

    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn('limite_credito');
        });
    }
};
