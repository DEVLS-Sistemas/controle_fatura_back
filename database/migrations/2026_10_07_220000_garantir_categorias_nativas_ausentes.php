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
                CatalogoCategoriasNativas::garantirCategoriasAusentes((int) $user->id);
            });
    }

    public function down(): void
    {
        // Restauração e cadastro das categorias que faltavam; não há como desfazer sem marca de origem.
    }
};
