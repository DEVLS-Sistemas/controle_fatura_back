<?php

use App\Models\User;
use App\Services\Categoria\CatalogoCategoriasNativas;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (User $user) {
                CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);
            });
    }

    public function down(): void
    {
        // A categoria antiga já foi rebaixada; não há como recolocar sem marca de origem.
    }
};
