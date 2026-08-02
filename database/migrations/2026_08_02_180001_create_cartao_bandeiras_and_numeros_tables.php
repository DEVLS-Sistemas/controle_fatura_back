<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartao_bandeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cartao_id')->constrained('cartoes')->cascadeOnDelete();
            $table->string('bandeira');
            $table->decimal('limite_credito', 12, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cartao_id', 'bandeira'], 'cartao_bandeiras_cartao_bandeira_idx');
        });

        Schema::create('cartao_numeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cartao_bandeira_id')->constrained('cartao_bandeiras')->cascadeOnDelete();
            $table->string('ultimos_digitos', 4);
            $table->enum('tipo', ['fisico', 'virtual', 'adicional'])->nullable();
            $table->string('apelido')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cartao_bandeira_id', 'ultimos_digitos'], 'cartao_numeros_bandeira_digitos_idx');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->foreignId('cartao_bandeira_id')
                ->nullable()
                ->after('cartao_id')
                ->constrained('cartao_bandeiras')
                ->nullOnDelete();
        });

        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreignId('cartao_numero_id')
                ->nullable()
                ->after('fatura_id')
                ->constrained('cartao_numeros')
                ->nullOnDelete();
        });

        $this->migrateLegacyCartoes();

        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn(['bandeira', 'ultimos_digitos', 'limite_credito']);
        });
    }

    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->string('bandeira')->nullable()->after('nome');
            $table->string('ultimos_digitos', 4)->nullable()->after('banco');
            $table->decimal('limite_credito', 12, 2)->nullable()->after('ultimos_digitos');
        });

        // Restaura valores legados a partir da primeira bandeira/número
        $bandeiras = DB::table('cartao_bandeiras')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->groupBy('cartao_id');

        foreach ($bandeiras as $cartaoId => $items) {
            $first = $items->first();
            $numero = DB::table('cartao_numeros')
                ->where('cartao_bandeira_id', $first->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first();

            DB::table('cartoes')->where('id', $cartaoId)->update([
                'bandeira' => $first->bandeira,
                'limite_credito' => $first->limite_credito,
                'ultimos_digitos' => $numero?->ultimos_digitos,
            ]);
        }

        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cartao_numero_id');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cartao_bandeira_id');
        });

        Schema::dropIfExists('cartao_numeros');
        Schema::dropIfExists('cartao_bandeiras');
    }

    private function migrateLegacyCartoes(): void
    {
        $cartoes = DB::table('cartoes')->whereNull('deleted_at')->get();

        foreach ($cartoes as $cartao) {
            $bandeiraNome = !empty($cartao->bandeira) ? $cartao->bandeira : 'Outra';

            $bandeiraId = DB::table('cartao_bandeiras')->insertGetId([
                'cartao_id' => $cartao->id,
                'bandeira' => $bandeiraNome,
                'limite_credito' => $cartao->limite_credito,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($cartao->ultimos_digitos) && preg_match('/^\d{4}$/', (string) $cartao->ultimos_digitos)) {
                DB::table('cartao_numeros')->insert([
                    'cartao_bandeira_id' => $bandeiraId,
                    'ultimos_digitos' => $cartao->ultimos_digitos,
                    'tipo' => 'fisico',
                    'apelido' => null,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('faturas')
                ->where('cartao_id', $cartao->id)
                ->whereNull('deleted_at')
                ->whereNull('cartao_bandeira_id')
                ->update([
                    'cartao_bandeira_id' => $bandeiraId,
                    'updated_at' => now(),
                ]);
        }
    }
};
