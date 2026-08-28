<?php

namespace App\Services\Estabelecimento;

use App\Models\Categoria;
use App\Models\Estabelecimento;
use App\Models\Loja;
use App\Models\Plataforma;
use App\Models\Subcategoria;
use App\Models\Transacao;
use App\Services\Categoria\CategoriaCoresTema;
use App\Services\PaginateService;
use App\Services\Plataforma\PlataformaNomeMatch;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EstabelecimentoService
{
    public function handleLookupsEstabelecimento(): array
    {
        $userId = Auth::id();

        return [
            'categorias' => CategoriaCoresTema::pintarLookups(
                Categoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor'])
            ),
            'subcategorias' => Subcategoria::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'lojas' => Loja::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'plataformas' => CategoriaCoresTema::pintarLookups(
                Plataforma::where('user_id', $userId)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'cor'])
            ),
        ];
    }

    public function handleAddEstabelecimento(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->createEstabelecimento($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditEstabelecimento(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->updateEstabelecimento($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeleteEstabelecimento(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->deleteEstabelecimento($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Soft-delete de todos os estabelecimentos, categorias e subcategorias do usuário.
     * Útil para resetar cadastros em testes. Exige confirmar=true.
     * Bloqueia se ainda houver transações (use excluir-todas das faturas antes).
     */
    public function handleDeleteTodosEstabelecimentos(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->estabelecimento = $this->deleteTodosEstabelecimentos($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Localiza estabelecimento pelo nome (trim) ou cria. Restaura soft-deleted se existir.
     *
     * Remove marcadores de parcela do nome (ex.: "Loja 1/3" → "Loja") para
     * garantir um único registro por estabelecimento real.
     */
    public function findOrCreateByNome(int $userId, string $nome): Estabelecimento
    {
        $nome = self::normalizeNome($nome);

        $record = Estabelecimento::withTrashed()
            ->where('user_id', $userId)
            ->where('nome', $nome)
            ->first();

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
                $record->ativo = true;
                $record->save();
            }

            return $this->garantirPlataformaPadrao($record);
        }

        $created = Estabelecimento::create([
            'user_id' => $userId,
            'nome' => $nome,
            'categoria_padrao_id' => null,
            'subcategoria_padrao_id' => null,
            'plataforma_padrao_id' => null,
            'ativo' => true,
        ]);

        return $this->garantirPlataformaPadrao($created);
    }

    /**
     * Normaliza nome: trim, espaços e remove parcela embutida ("1/3", "Parc 2/10").
     */
    public static function normalizeNome(string $nome): string
    {
        $nome = trim($nome);
        $nome = preg_replace('/\bPARC(?:ELA)?\s*\d{1,2}\s*\/\s*\d{1,2}\b/iu', '', $nome) ?? $nome;
        $nome = preg_replace('/\b\d{1,2}\s*\/\s*\d{1,2}\b/', '', $nome) ?? $nome;
        $nome = preg_replace('/\b\d{1,2}\s+de\s+\d{1,2}\b/iu', '', $nome) ?? $nome;
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? $nome);

        return $nome !== '' ? $nome : 'Desconhecido';
    }

    public function createEstabelecimento(object $atributes): object
    {
        try {
            if (empty($atributes->nome) || trim((string) $atributes->nome) === '') {
                throw new Exception('O nome do estabelecimento é obrigatório', 422);
            }

            $nome = trim((string) $atributes->nome);
            $userId = Auth::id();

            $exists = Estabelecimento::where('user_id', $userId)
                ->where('nome', $nome)
                ->exists();

            if ($exists) {
                throw new Exception('Já existe um estabelecimento com este nome', 422);
            }

            $lojaId = $this->normalizeNullableId($atributes->loja_id ?? null);
            $categoriaPadraoId = $this->normalizeNullableId($atributes->categoria_padrao_id ?? null);
            $subcategoriaPadraoId = $this->normalizeNullableId($atributes->subcategoria_padrao_id ?? null);
            $plataformaPadraoId = $this->normalizeNullableId($atributes->plataforma_padrao_id ?? null);

            $this->assertLojaValida($userId, $lojaId);
            $this->assertPadroesValidos($userId, $categoriaPadraoId, $subcategoriaPadraoId);
            $this->assertPlataformaPadraoValida($userId, $plataformaPadraoId);

            if ($plataformaPadraoId === null) {
                $plataformaPadraoId = $this->inferirPlataformaIdPorNome($nome, $userId);
            }

            $newData = new Estabelecimento([
                'user_id' => $userId,
                'nome' => $nome,
                'loja_id' => $lojaId,
                'categoria_padrao_id' => $categoriaPadraoId,
                'subcategoria_padrao_id' => $subcategoriaPadraoId,
                'plataforma_padrao_id' => $plataformaPadraoId,
                'ativo' => $atributes->ativo ?? true,
            ]);

            $saved = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar Estabelecimento', 500);
            }

            return (object) [
                'data' => $this->getEstabelecimentoId($newData->id),
                'status' => true,
                'message' => 'Estabelecimento cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateEstabelecimento(object $atributes): object
    {
        try {
            if (empty($atributes->id)) {
                throw new Exception('ID do estabelecimento é obrigatório', 422);
            }

            $userId = Auth::id();
            $record = Estabelecimento::where('id', $atributes->id)
                ->where('user_id', $userId)
                ->first();

            if (!$record) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            if (isset($atributes->nome) && trim((string) $atributes->nome) !== '') {
                $nome = trim((string) $atributes->nome);
                $exists = Estabelecimento::where('user_id', $userId)
                    ->where('nome', $nome)
                    ->where('id', '!=', $record->id)
                    ->exists();

                if ($exists) {
                    throw new Exception('Já existe um estabelecimento com este nome', 422);
                }

                $record->nome = $nome;
            }

            $vars = get_object_vars($atributes);

            if (array_key_exists('loja_id', $vars)) {
                $record->loja_id = $this->normalizeNullableId($atributes->loja_id);
            }

            if (array_key_exists('categoria_padrao_id', $vars)) {
                $record->categoria_padrao_id = $this->normalizeNullableId($atributes->categoria_padrao_id);
            }

            if (array_key_exists('subcategoria_padrao_id', $vars)) {
                $record->subcategoria_padrao_id = $this->normalizeNullableId($atributes->subcategoria_padrao_id);
            }

            if (array_key_exists('plataforma_padrao_id', $vars)) {
                $record->plataforma_padrao_id = $this->normalizeNullableId($atributes->plataforma_padrao_id);
            }

            if (array_key_exists('ativo', $vars)) {
                $record->ativo = filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN);
            }

            // Se limpar categoria padrão, limpa subcategoria padrão
            if ($record->categoria_padrao_id === null) {
                $record->subcategoria_padrao_id = null;
            }

            $this->assertLojaValida($userId, $record->loja_id);
            $this->assertPadroesValidos($userId, $record->categoria_padrao_id, $record->subcategoria_padrao_id);
            $this->assertPlataformaPadraoValida($userId, $record->plataforma_padrao_id !== null ? (int) $record->plataforma_padrao_id : null);

            if ($record->plataforma_padrao_id === null && !array_key_exists('plataforma_padrao_id', $vars)) {
                $this->garantirPlataformaPadrao($record);
            }

            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar Estabelecimento', 500);
            }

            return (object) [
                'data' => $this->getEstabelecimentoId($record->id),
                'status' => true,
                'message' => 'Estabelecimento alterado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteEstabelecimento(int|string $id): object
    {
        try {
            $record = Estabelecimento::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$record) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            $emUso = $record->transacoes()->exists();
            if ($emUso) {
                throw new Exception('Não é possível excluir estabelecimento vinculado a transações', 422);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir Estabelecimento', 500);
            }

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Estabelecimento excluído com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteTodosEstabelecimentos(object $atributes): object
    {
        $confirmado = filter_var($atributes->confirmar ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$confirmado) {
            throw new Exception(
                'Envie confirmar=true para excluir todos os estabelecimentos, lojas, categorias, subcategorias e plataformas',
                422
            );
        }

        $userId = Auth::id();
        if (!$userId) {
            throw new Exception('Não autenticado', 401);
        }

        $temTransacoes = Transacao::where('user_id', $userId)->exists();
        if ($temTransacoes) {
            throw new Exception(
                'Exclua as faturas e transações antes de limpar estabelecimentos e categorias',
                422
            );
        }

        $categoriaIds = Categoria::where('user_id', $userId)->pluck('id');
        $subcategoriaIds = Subcategoria::where('user_id', $userId)->pluck('id');

        if ($categoriaIds->isNotEmpty() || $subcategoriaIds->isNotEmpty()) {
            DB::table('categoria_subcategoria')
                ->where(function ($query) use ($categoriaIds, $subcategoriaIds) {
                    if ($categoriaIds->isNotEmpty()) {
                        $query->whereIn('categoria_id', $categoriaIds);
                    }
                    if ($subcategoriaIds->isNotEmpty()) {
                        $method = $categoriaIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('subcategoria_id', $subcategoriaIds);
                    }
                })
                ->delete();
        }

        // Limpa FKs de padrão/loja antes do soft-delete
        Estabelecimento::where('user_id', $userId)->update([
            'loja_id' => null,
            'categoria_padrao_id' => null,
            'subcategoria_padrao_id' => null,
            'plataforma_padrao_id' => null,
        ]);

        $estabelecimentosExcluidos = Estabelecimento::where('user_id', $userId)->delete();
        $lojasExcluidas = Loja::where('user_id', $userId)->delete();
        $categoriasExcluidas = Categoria::where('user_id', $userId)->delete();
        $subcategoriasExcluidas = Subcategoria::where('user_id', $userId)->delete();
        $plataformasExcluidas = Plataforma::where('user_id', $userId)->delete();

        return (object) [
            'data' => [
                'estabelecimentos_excluidos' => (int) $estabelecimentosExcluidos,
                'lojas_excluidas' => (int) $lojasExcluidas,
                'categorias_excluidas' => (int) $categoriasExcluidas,
                'subcategorias_excluidas' => (int) $subcategoriasExcluidas,
                'plataformas_excluidas' => (int) $plataformasExcluidas,
            ],
            'status' => true,
            'message' => 'Todos os estabelecimentos, lojas, categorias, subcategorias e plataformas foram excluídos com sucesso!',
        ];
    }

    public function getEstabelecimentoPaginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.loja_id',
            'loja.nome as loja_nome',
            'ent.categoria_padrao_id',
            'cat.nome as categoria_padrao_nome',
            'cat.cor as categoria_padrao_cor',
            'ent.subcategoria_padrao_id',
            'sub.nome as subcategoria_padrao_nome',
            'ent.plataforma_padrao_id',
            'plat.nome as plataforma_padrao_nome',
            'plat.cor as plataforma_padrao_cor',
            'ent.ativo',
            'ent.created_at',
            'ent.updated_at',
        );

        $query->from('estabelecimentos as ent');
        $query->leftJoin('lojas as loja', function ($join) {
            $join->on('loja.id', '=', 'ent.loja_id')->whereNull('loja.deleted_at');
        });
        $query->leftJoin('categorias as cat', function ($join) {
            $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
        });
        $query->leftJoin('subcategorias as sub', function ($join) {
            $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
        });
        $query->leftJoin('plataformas as plat', function ($join) {
            $join->on('plat.id', '=', 'ent.plataforma_padrao_id')->whereNull('plat.deleted_at');
        });
        $query->whereNull('ent.deleted_at');
        $query->where('ent.user_id', Auth::id());
        $query->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where('ent.nome', 'like', '%' . $chave . '%');
        }

        if (!empty($atributes->loja_id)) {
            $query->where('ent.loja_id', $atributes->loja_id);
        }

        if (!empty($atributes->categoria_padrao_id)) {
            $query->where('ent.categoria_padrao_id', $atributes->categoria_padrao_id);
        }

        if (!empty($atributes->plataforma_padrao_id)) {
            $query->where('ent.plataforma_padrao_id', $atributes->plataforma_padrao_id);
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('loja.nome', 'like', '%' . $chave . '%')
                    ->orWhere('cat.nome', 'like', '%' . $chave . '%')
                    ->orWhere('sub.nome', 'like', '%' . $chave . '%')
                    ->orWhere('plat.nome', 'like', '%' . $chave . '%');
            });
        }

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $query,
            $atributes->page,
            $atributes->perPage,
            ['path' => $atributes->url, 'query' => $atributes->query]
        );
        $resultado->appends((array) $atributes);

        $array = collect($resultado)->toArray();
        $array['data'] = $this->anexarEstatisticasListagem(
            $array['data'] ?? [],
            $atributes,
            'estabelecimento'
        );

        return $array;
    }

    public function getEstabelecimentoId(int|string $id, ?object $atributes = null): array
    {
        try {
            $query = DB::table('estabelecimentos as ent')
                ->leftJoin('lojas as loja', function ($join) {
                    $join->on('loja.id', '=', 'ent.loja_id')->whereNull('loja.deleted_at');
                })
                ->leftJoin('categorias as cat', function ($join) {
                    $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
                })
                ->leftJoin('subcategorias as sub', function ($join) {
                    $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
                })
                ->leftJoin('plataformas as plat', function ($join) {
                    $join->on('plat.id', '=', 'ent.plataforma_padrao_id')->whereNull('plat.deleted_at');
                })
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.loja_id',
                    'loja.nome as loja_nome',
                    'ent.categoria_padrao_id',
                    'cat.nome as categoria_padrao_nome',
                    'cat.cor as categoria_padrao_cor',
                    'ent.subcategoria_padrao_id',
                    'sub.nome as subcategoria_padrao_nome',
                    'ent.plataforma_padrao_id',
                    'plat.nome as plataforma_padrao_nome',
                    'plat.cor as plataforma_padrao_cor',
                    'ent.ativo',
                    'ent.created_at',
                    'ent.updated_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.user_id', Auth::id())
                ->where('ent.id', $id);

            $data = $query->first();

            if (!$data) {
                throw new Exception('Estabelecimento não encontrado', 404);
            }

            $result = $this->pintarCategoriaPadrao(collect($data)->toArray());
            $atributes = $atributes ?? (object) [];
            $stats = (new EstabelecimentoEstatisticasService())
                ->handleEstabelecimento((int) $result['id'], $atributes);
            $result['estatisticas'] = $this->extrairEstatisticasPayload((array) $stats->data);

            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getEstabelecimentoAsync(object $params): array
    {
        $query = DB::table('estabelecimentos as ent')
            ->leftJoin('lojas as loja', function ($join) {
                $join->on('loja.id', '=', 'ent.loja_id')->whereNull('loja.deleted_at');
            })
            ->leftJoin('categorias as cat', function ($join) {
                $join->on('cat.id', '=', 'ent.categoria_padrao_id')->whereNull('cat.deleted_at');
            })
            ->leftJoin('subcategorias as sub', function ($join) {
                $join->on('sub.id', '=', 'ent.subcategoria_padrao_id')->whereNull('sub.deleted_at');
            })
            ->leftJoin('plataformas as plat', function ($join) {
                $join->on('plat.id', '=', 'ent.plataforma_padrao_id')->whereNull('plat.deleted_at');
            })
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select(
                'ent.id',
                'ent.nome',
                'ent.loja_id',
                'loja.nome as loja_nome',
                'ent.categoria_padrao_id',
                'cat.nome as categoria_padrao_nome',
                'cat.cor as categoria_padrao_cor',
                'ent.subcategoria_padrao_id',
                'sub.nome as subcategoria_padrao_nome',
                'ent.plataforma_padrao_id',
                'plat.nome as plataforma_padrao_nome',
                'plat.cor as plataforma_padrao_cor',
            );

        if (!empty($params->loja_id)) {
            $query->where('ent.loja_id', $params->loja_id);
        }

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('loja.nome', 'like', '%' . $chave . '%');
            });
        }

        $query->limit(10);

        return $query->orderBy('ent.nome')->get()->map(function ($row) {
            return $this->pintarCategoriaPadrao((array) $row);
        })->all();
    }

    /**
     * @param array<int, mixed> $linhas
     * @return array<int, array<string, mixed>>
     */
    private function anexarEstatisticasListagem(array $linhas, object $atributes, string $escopo): array
    {
        $ids = [];
        foreach ($linhas as $linha) {
            $row = (array) $linha;
            if (!empty($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        $mapa = (new EstabelecimentoEstatisticasService())
            ->mapaParaListagem((int) Auth::id(), $ids, $atributes, $escopo);

        return array_map(function ($linha) use ($mapa) {
            $row = $this->pintarCategoriaPadrao((array) $linha);
            $id = (int) ($row['id'] ?? 0);
            $row['estatisticas'] = $mapa[$id] ?? null;

            return $row;
        }, $linhas);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extrairEstatisticasPayload(array $data): array
    {
        unset($data['estabelecimento_id'], $data['nome'], $data['loja_id']);

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function pintarCategoriaPadrao(array $row): array
    {
        $row['categoria_padrao_cor'] = CategoriaCoresTema::corCadastroOuNull(
            $row['categoria_padrao_cor'] ?? null,
            $row['categoria_padrao_id'] ?? null
        );
        $row['plataforma_padrao_cor'] = !empty($row['plataforma_padrao_id'])
            ? CategoriaCoresTema::normalizar($row['plataforma_padrao_cor'] ?? null)
            : null;

        return $row;
    }

    private function assertLojaValida(int $userId, ?int $lojaId): void
    {
        if ($lojaId === null) {
            return;
        }

        $exists = Loja::where('id', $lojaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Loja não encontrada', 404);
        }
    }

    private function assertPadroesValidos(int $userId, ?int $categoriaId, ?int $subcategoriaId): void
    {
        if ($categoriaId !== null) {
            $exists = Categoria::where('id', $categoriaId)->where('user_id', $userId)->exists();
            if (!$exists) {
                throw new Exception('Categoria padrão não encontrada', 404);
            }
        }

        if ($subcategoriaId !== null) {
            if ($categoriaId === null) {
                throw new Exception('Subcategoria padrão exige categoria padrão', 422);
            }

            $exists = Subcategoria::where('id', $subcategoriaId)->where('user_id', $userId)->exists();
            if (!$exists) {
                throw new Exception('Subcategoria padrão não encontrada', 404);
            }

            $vinculo = DB::table('categoria_subcategoria')
                ->where('categoria_id', $categoriaId)
                ->where('subcategoria_id', $subcategoriaId)
                ->exists();

            if (!$vinculo) {
                throw new Exception('Subcategoria padrão não está vinculada à categoria padrão', 422);
            }
        }
    }

    private function assertPlataformaPadraoValida(int $userId, ?int $plataformaId): void
    {
        if ($plataformaId === null) {
            return;
        }

        $exists = Plataforma::where('id', $plataformaId)->where('user_id', $userId)->exists();
        if (!$exists) {
            throw new Exception('Plataforma padrão não encontrada', 404);
        }
    }

    public function garantirPlataformaPadrao(Estabelecimento $record): Estabelecimento
    {
        if (!empty($record->plataforma_padrao_id)) {
            return $record;
        }

        $id = $this->inferirPlataformaIdPorNome((string) $record->nome, (int) $record->user_id);
        if ($id === null) {
            return $record;
        }

        $record->plataforma_padrao_id = $id;
        $record->save();

        return $record;
    }

    public function inferirPlataformaIdPorNome(string $nome, int $userId): ?int
    {
        return PlataformaNomeMatch::inferirId(
            $nome,
            Plataforma::where('user_id', $userId)->where('ativo', true)->get(['id', 'nome'])
        );
    }

    /**
     * Infere plataforma_padrao nos estabelecimentos sem padrão e copia para compras vazias.
     *
     * @return array{estabelecimentos: int, transacoes: int}
     */
    public function backfillPlataformaPadraoPorNome(?int $userId = null, bool $dryRun = false): array
    {
        $query = Estabelecimento::query()->whereNull('plataforma_padrao_id');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $estabelecimentos = 0;
        foreach ($query->cursor() as $record) {
            $id = $this->inferirPlataformaIdPorNome((string) $record->nome, (int) $record->user_id);
            if ($id === null) {
                continue;
            }
            $estabelecimentos++;
            if ($dryRun) {
                continue;
            }
            $record->plataforma_padrao_id = $id;
            $record->save();
        }

        $comprasQuery = Transacao::query()
            ->whereNull('plataforma_id')
            ->whereNotNull('estabelecimento_id');
        if ($userId !== null) {
            $comprasQuery->where('user_id', $userId);
        }

        $transacoes = 0;
        $comprasQuery->orderBy('id')->chunkById(200, function ($rows) use (&$transacoes, $dryRun) {
            foreach ($rows as $compra) {
                $padrao = Estabelecimento::where('id', $compra->estabelecimento_id)
                    ->value('plataforma_padrao_id');
                if (!$padrao) {
                    continue;
                }
                $transacoes++;
                if ($dryRun) {
                    continue;
                }
                $compra->plataforma_id = (int) $padrao;
                $compra->save();
            }
        });

        return [
            'estabelecimentos' => $estabelecimentos,
            'transacoes' => $transacoes,
        ];
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
