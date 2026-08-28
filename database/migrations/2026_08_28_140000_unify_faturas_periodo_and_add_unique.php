<?php

use App\Models\Fatura;
use App\Services\Fatura\FaturaPeriodoUnicidadeService;
use App\Services\Fatura\FaturaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $unicidade = new FaturaPeriodoUnicidadeService(app(FaturaService::class));
        $userIds = Fatura::withTrashed()->distinct()->pluck('user_id');
        foreach ($userIds as $userId) {
            $unicidade->consolidarDuplicatasDoUsuario((int) $userId);
        }

        Schema::table('faturas', function (Blueprint $table) {
            $table->unsignedBigInteger('bandeira_periodo_key')
                ->storedAs('IFNULL(cartao_bandeira_id, 0)')
                ->after('cartao_bandeira_id');
            $table->unique(
                ['user_id', 'cartao_id', 'bandeira_periodo_key', 'mes', 'ano'],
                'faturas_periodo_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropUnique('faturas_periodo_unique');
            $table->dropColumn('bandeira_periodo_key');
        });
    }
};
