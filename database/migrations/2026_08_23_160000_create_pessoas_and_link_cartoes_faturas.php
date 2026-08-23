<?php

use App\Models\Pessoa;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nome');
            $table->string('sobrenome')->nullable();
            $table->string('cpf_cnpj', 14)->nullable();
            $table->boolean('eh_principal')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'eh_principal']);
        });

        Schema::table('cartoes', function (Blueprint $table) {
            $table->foreignId('pessoa_id')
                ->nullable()
                ->after('user_id')
                ->constrained('pessoas')
                ->nullOnDelete();
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->foreignId('pessoa_id')
                ->nullable()
                ->after('user_id')
                ->constrained('pessoas')
                ->nullOnDelete();
            $table->index(['user_id', 'pessoa_id']);
        });

        User::query()->orderBy('id')->each(function (User $user) {
            Pessoa::create([
                'user_id' => $user->id,
                'nome' => $user->name ?: 'Usuário',
                'sobrenome' => $user->sobrenome,
                'cpf_cnpj' => $user->cpf_cnpj,
                'eh_principal' => true,
                'ativo' => true,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pessoa_id');
        });

        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pessoa_id');
        });

        Schema::dropIfExists('pessoas');
    }
};
