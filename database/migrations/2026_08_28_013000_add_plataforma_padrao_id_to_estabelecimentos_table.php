<?php

use App\Services\Estabelecimento\EstabelecimentoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->foreignId('plataforma_padrao_id')
                ->nullable()
                ->after('subcategoria_padrao_id')
                ->constrained('plataformas')
                ->nullOnDelete();
        });

        (new EstabelecimentoService())->backfillPlataformaPadraoPorNome();
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plataforma_padrao_id');
        });
    }
};
