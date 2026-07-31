<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreignId('estabelecimento_id')->nullable()->after('fatura_id')->constrained('estabelecimentos')->restrictOnDelete();
            $table->foreignId('categoria_id')->nullable()->after('tipo')->constrained('categorias')->nullOnDelete();
            $table->foreignId('subcategoria_id')->nullable()->after('categoria_id')->constrained('subcategorias')->nullOnDelete();
            $table->index(['user_id', 'estabelecimento_id']);
            $table->index(['user_id', 'categoria_id']);
            $table->index(['user_id', 'subcategoria_id']);
        });

        $this->migrateEstabelecimentosECategorias();
        $this->migrateResponsaveisDefault();

        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropColumn('estabelecimento');
        });

        DB::statement('ALTER TABLE transacoes MODIFY estabelecimento_id BIGINT UNSIGNED NOT NULL');

        // Garante responsável obrigatório após backfill
        $aindaSemResponsavel = DB::table('transacoes')
            ->whereNull('responsavel_id')
            ->whereNull('deleted_at')
            ->count();

        if ($aindaSemResponsavel === 0) {
            Schema::table('transacoes', function (Blueprint $table) {
                $table->dropForeign(['responsavel_id']);
            });

            DB::statement('ALTER TABLE transacoes MODIFY responsavel_id BIGINT UNSIGNED NOT NULL');

            Schema::table('transacoes', function (Blueprint $table) {
                $table->foreign('responsavel_id')->references('id')->on('responsaveis')->restrictOnDelete();
            });
        }

        Schema::dropIfExists('estabelecimento_categorias');
    }

    public function down(): void
    {
        Schema::create('estabelecimento_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('estabelecimento');
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'estabelecimento'], 'estabelecimento_categorias_user_estab_unique');
            $table->index(['user_id', 'categoria_id']);
        });

        Schema::table('transacoes', function (Blueprint $table) {
            $table->string('estabelecimento')->nullable()->after('fatura_id');
        });

        $estabelecimentos = DB::table('estabelecimentos')->get();
        foreach ($estabelecimentos as $estab) {
            DB::table('transacoes')
                ->where('estabelecimento_id', $estab->id)
                ->update(['estabelecimento' => $estab->nome]);

            if ($estab->categoria_padrao_id) {
                DB::table('estabelecimento_categorias')->insert([
                    'user_id' => $estab->user_id,
                    'estabelecimento' => $estab->nome,
                    'categoria_id' => $estab->categoria_padrao_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropForeign(['estabelecimento_id']);
            $table->dropForeign(['categoria_id']);
            $table->dropForeign(['subcategoria_id']);
            $table->dropIndex(['user_id', 'estabelecimento_id']);
            $table->dropIndex(['user_id', 'categoria_id']);
            $table->dropIndex(['user_id', 'subcategoria_id']);
            $table->dropColumn(['estabelecimento_id', 'categoria_id', 'subcategoria_id']);
        });

        Schema::table('transacoes', function (Blueprint $table) {
            $table->dropForeign(['responsavel_id']);
        });

        DB::statement('ALTER TABLE transacoes MODIFY responsavel_id BIGINT UNSIGNED NULL');

        Schema::table('transacoes', function (Blueprint $table) {
            $table->foreign('responsavel_id')->references('id')->on('responsaveis')->nullOnDelete();
        });
    }

    private function migrateEstabelecimentosECategorias(): void
    {
        $now = now();

        $nomes = DB::table('transacoes')
            ->select('user_id', 'estabelecimento')
            ->whereNotNull('estabelecimento')
            ->where('estabelecimento', '!=', '')
            ->distinct()
            ->get();

        $mapaCategorias = [];
        if (Schema::hasTable('estabelecimento_categorias')) {
            $rows = DB::table('estabelecimento_categorias')
                ->whereNull('deleted_at')
                ->get(['user_id', 'estabelecimento', 'categoria_id']);

            foreach ($rows as $row) {
                $chave = $row->user_id . '|' . trim((string) $row->estabelecimento);
                $mapaCategorias[$chave] = $row->categoria_id;
            }
        }

        $idPorChave = [];

        foreach ($nomes as $row) {
            $nome = trim((string) $row->estabelecimento);
            if ($nome === '') {
                continue;
            }

            $chave = $row->user_id . '|' . $nome;
            if (isset($idPorChave[$chave])) {
                continue;
            }

            $categoriaPadrao = $mapaCategorias[$chave] ?? null;

            $id = DB::table('estabelecimentos')->insertGetId([
                'user_id' => $row->user_id,
                'nome' => $nome,
                'categoria_padrao_id' => $categoriaPadrao,
                'subcategoria_padrao_id' => null,
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $idPorChave[$chave] = [
                'id' => $id,
                'categoria_id' => $categoriaPadrao,
            ];
        }

        // Estabelecimentos que só existem no mapa antigo
        foreach ($mapaCategorias as $chave => $categoriaId) {
            if (isset($idPorChave[$chave])) {
                continue;
            }

            [$userId, $nome] = explode('|', $chave, 2);
            if (trim($nome) === '') {
                continue;
            }

            $id = DB::table('estabelecimentos')->insertGetId([
                'user_id' => (int) $userId,
                'nome' => $nome,
                'categoria_padrao_id' => $categoriaId,
                'subcategoria_padrao_id' => null,
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $idPorChave[$chave] = [
                'id' => $id,
                'categoria_id' => $categoriaId,
            ];
        }

        foreach ($idPorChave as $chave => $info) {
            [$userId, $nome] = explode('|', $chave, 2);

            DB::table('transacoes')
                ->where('user_id', (int) $userId)
                ->whereRaw('TRIM(estabelecimento) = ?', [$nome])
                ->update([
                    'estabelecimento_id' => $info['id'],
                    'categoria_id' => $info['categoria_id'],
                ]);
        }

        // Fallback para nomes vazios/nulos
        $orfas = DB::table('transacoes')
            ->whereNull('estabelecimento_id')
            ->get(['id', 'user_id']);

        $desconhecidoPorUser = [];

        foreach ($orfas as $orfao) {
            if (!isset($desconhecidoPorUser[$orfao->user_id])) {
                $existente = DB::table('estabelecimentos')
                    ->where('user_id', $orfao->user_id)
                    ->where('nome', 'Desconhecido')
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->value('id');

                if (!$existente) {
                    $existente = DB::table('estabelecimentos')->insertGetId([
                        'user_id' => $orfao->user_id,
                        'nome' => 'Desconhecido',
                        'categoria_padrao_id' => null,
                        'subcategoria_padrao_id' => null,
                        'ativo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $desconhecidoPorUser[$orfao->user_id] = $existente;
            }

            DB::table('transacoes')
                ->where('id', $orfao->id)
                ->update(['estabelecimento_id' => $desconhecidoPorUser[$orfao->user_id]]);
        }
    }

    private function migrateResponsaveisDefault(): void
    {
        $userIds = DB::table('transacoes')
            ->whereNull('responsavel_id')
            ->distinct()
            ->pluck('user_id');

        $now = now();

        foreach ($userIds as $userId) {
            $responsavelId = DB::table('responsaveis')
                ->where('user_id', $userId)
                ->where('nome', 'Eu')
                ->whereNull('deleted_at')
                ->value('id');

            if (!$responsavelId) {
                $responsavelId = DB::table('responsaveis')->insertGetId([
                    'user_id' => $userId,
                    'nome' => 'Eu',
                    'tipo' => 'pessoal',
                    'ativo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('transacoes')
                ->where('user_id', $userId)
                ->whereNull('responsavel_id')
                ->update(['responsavel_id' => $responsavelId]);
        }
    }
};
