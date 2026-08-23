<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sobrenome')->nullable()->after('name');
            $table->string('cpf_cnpj', 14)->nullable()->after('sobrenome');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sobrenome', 'cpf_cnpj']);
        });
    }
};
