<?php

use App\Models\Plataforma;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plataformas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nome');
            $table->string('cor', 20)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        User::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (User $user) {
                Plataforma::seedPadroesParaUser((int) $user->id);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataformas');
    }
};
