<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categoria_subcategoria', function (Blueprint $table) {
            $table->string('cor', 20)->nullable()->after('subcategoria_id');
        });
    }

    public function down(): void
    {
        Schema::table('categoria_subcategoria', function (Blueprint $table) {
            $table->dropColumn('cor');
        });
    }
};
