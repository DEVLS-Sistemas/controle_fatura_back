# Template Base — Novo CRUD Backend Laravel

> Sempre que for criado um novo módulo/CRUD, todos os arquivos abaixo devem ser gerados seguindo **exatamente** este template.  
> Substitua `{Entidade}` pela entidade em PascalCase (ex: `Produto`), `{entidade}` em camelCase (ex: `produto`) e `{entidades}` no plural snake_case (ex: `produtos`).

---

## Checklist de Geração

- [ ] Migration
- [ ] Model
- [ ] Controller
- [ ] Service
- [ ] Arquivo de Rota (`routes/routerFiles/{entidades}Router.php`)
- [ ] Registro no `routes/api.php`

---

## 1. Migration

**Caminho:** `database/migrations/YYYY_MM_DD_HHMMSS_create_{entidades}_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{entidades}', function (Blueprint $table) {
            $table->id();

            // --- campos da entidade ---
            $table->string('nome');
            $table->boolean('ativo')->default(true);
            // --------------------------

            $table->timestamps();
            $table->softDeletes();
        });

        // Seed inicial (remover se não necessário)
        DB::table('{entidades}')->insert([
            [
                'nome'       => '{Entidade} Padrão',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('{entidades}');
    }
};
```

---

## 2. Model

**Caminho:** `app/Models/{Entidade}.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class {Entidade} extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = '{entidades}';

    protected $fillable = [
        'nome',
        'ativo',
        // adicionar demais campos editáveis
    ];

    protected $casts = [
        'id'         => 'integer',
        'ativo'      => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // --- Relacionamentos ---
    // public function outraEntidade()
    // {
    //     return $this->belongsTo(OutraEntidade::class, 'outra_entidade_id');
    // }
}
```

---

## 3. Controller

**Caminho:** `app/Http/Controllers/{Entidade}Controller.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\{Entidade}\{Entidade}Service;
use App\Services\RequestDataService;
use Exception;
use Illuminate\Http\Request;

class {Entidade}Controller extends Controller
{
    /**
     * @var {Entidade}Service $_service
     */
    private {Entidade}Service $_service;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service        = new {Entidade}Service();
        $this->_requestService = new RequestDataService();
    }

    public function listarLookups{Entidade}()
    {
        try {
            $result = $this->_service->handleLookups{Entidade}();
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listar{Entidade}(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result          = $this->_service->get{Entidade}Paginate($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listar{Entidade}Id(string $id)
    {
        try {
            $result = $this->_service->get{Entidade}Id($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function create{Entidade}(Request $request)
    {
        try {
            $objectAtributes = (object) $request->all();
            $result          = $this->_service->handleAdd{Entidade}($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function edit{Entidade}(Request $request)
    {
        try {
            $objectAtributes = (object) $request->all();
            $result          = $this->_service->handleEdit{Entidade}($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function delete{Entidade}(string $id)
    {
        try {
            $result = $this->_service->handleDelete{Entidade}($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listar{Entidade}Async(Request $request)
    {
        try {
            $params = (object) $request->all();
            $result = $this->_service->get{Entidade}Async($params);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
```

---

## 4. Service

**Caminho:** `app/Services/{Entidade}/{Entidade}Service.php`

```php
<?php

namespace App\Services\{Entidade};

use App\Models\{Entidade};
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\DB;

class {Entidade}Service
{
    public function __construct()
    {
        // Injetar outros services se necessário
        // $this->_outraService = new OutraService();
    }

    // =========================================================
    // LOOKUPS
    // =========================================================

    public function handleLookups{Entidade}(): array
    {
        $data = [];
        // $data['outraEntidade'] = OutraEntidade::all();
        return $data;
    }

    // =========================================================
    // HANDLE FUNCTIONS (orquestração + transação)
    // =========================================================

    public function handleAdd{Entidade}(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result              = (object) [];
            $result->{entidade} = $this->create{Entidade}($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEdit{Entidade}(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result              = (object) [];
            $result->{entidade} = $this->update{Entidade}($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDelete{Entidade}(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result              = (object) [];
            $result->{entidade} = $this->delete{Entidade}($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    // =========================================================
    // CRUD FUNCTIONS
    // =========================================================

    public function create{Entidade}(object $atributes): object
    {
        try {
            $newData = new {Entidade}((array) $atributes);
            $saved   = $newData->save();

            if (!$saved) {
                throw new Exception('Não foi possível cadastrar {Entidade}', 500);
            }

            return (object) [
                'data'    => $newData,
                'status'  => true,
                'message' => '{Entidade} cadastrada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function update{Entidade}(object $atributes): object
    {
        try {
            $record = {Entidade}::where('id', $atributes->id)->first();

            if (!$record) {
                throw new Exception('{Entidade} não encontrada', 404);
            }

            $record->fill(get_object_vars($atributes));
            $saved = $record->save();

            if (!$saved) {
                throw new Exception('Não foi possível editar {Entidade}', 500);
            }

            return (object) [
                'data'    => [],
                'status'  => true,
                'message' => '{Entidade} alterada com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function delete{Entidade}(int|string $id): object
    {
        try {
            $record = {Entidade}::where('id', $id)->first();

            if (!$record) {
                throw new Exception('{Entidade} não encontrada', 404);
            }

            $saved = $record->delete();

            if (!$saved) {
                throw new Exception('Não foi possível excluir {Entidade}', 500);
            }

            return (object) [
                'data'    => [],
                'status'  => true,
                'message' => '{Entidade} excluída com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    // =========================================================
    // QUERIES
    // =========================================================

    public function get{Entidade}Paginate(object $atributes): array
    {
        $query = DB::query();

        $query->select(
            'ent.id',
            'ent.nome',
            'ent.ativo',
            'ent.created_at',
        );

        $query->from('{entidades} as ent');
        $query->whereNull('ent.deleted_at');
        $query->orderBy('ent.nome');

        // Filtros dinâmicos
        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
        }

        $paginate   = new PaginateService();
        $resultado  = $paginate->_paginate(
            $query,
            $atributes->page,
            $atributes->perPage,
            ['path' => $atributes->url, 'query' => $atributes->query]
        );
        $resultado->appends((array) $atributes);

        return collect($resultado)->toArray();
    }

    public function get{Entidade}Id(int|string $id): array
    {
        try {
            $query = DB::table('{entidades} as ent')
                ->select(
                    'ent.id',
                    'ent.nome',
                    'ent.ativo',
                    'ent.created_at',
                )
                ->whereNull('ent.deleted_at')
                ->where('ent.id', $id);

            return collect($query->first())->toArray();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function get{Entidade}Async(object $params): array
    {
        $query = DB::table('{entidades} as ent')
            ->whereNull('ent.deleted_at')
            ->select('ent.id', 'ent.nome');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->get()->toArray();
    }
}
```

---

## 5. Arquivo de Rota

**Caminho:** `routes/routerFiles/{entidades}Router.php`

```php
<?php

use App\Http\Controllers\{Entidade}Controller;
use Illuminate\Support\Facades\Route;

Route::get('/lookups',            [{Entidade}Controller::class, 'listarLookups{Entidade}']);
Route::get('/listar',             [{Entidade}Controller::class, 'listar{Entidade}']);
Route::get('/listar/{id}',        [{Entidade}Controller::class, 'listar{Entidade}Id']);
Route::post('/cadastrar',         [{Entidade}Controller::class, 'create{Entidade}']);
Route::put('/editar',             [{Entidade}Controller::class, 'edit{Entidade}']);
Route::delete('/excluir/{id}',    [{Entidade}Controller::class, 'delete{Entidade}']);
Route::get('/{entidades}-list',   [{Entidade}Controller::class, 'listar{Entidade}Async']);
```

---

## 6. Registro no api.php

**Caminho:** `routes/api.php`

```php
Route::prefix('{entidades}')->group(function () {
    require __DIR__ . '/routerFiles/{entidades}Router.php';
});
```

---

## Regras Gerais (nunca quebrar)

| Regra | Detalhe |
|---|---|
| **SoftDeletes** | Todo model usa `SoftDeletes`; toda migration usa `$table->softDeletes()` |
| **Timestamps** | Toda migration usa `$table->timestamps()` |
| **Fillable** | Todo model declara `$fillable` com os campos editáveis |
| **Casts** | Todo model declara `$casts` incluindo `id`, campos boolean e datas |
| **try/catch no controller** | Toda função do controller possui try/catch com `$statusCode` dinâmico |
| **Handle functions** | Toda operação de escrita (add/edit/delete) passa por uma função `handle*` com `DB::beginTransaction()` |
| **Retorno padrão de CRUD** | Toda função de escrita retorna `(object)['data' => ..., 'status' => true, 'message' => '...']` |
| **Service isolado** | Toda regra de negócio fica no Service, nunca no Controller |
| **Namespace do service** | `App\Services\{Entidade}\{Entidade}Service` |
| **RequestDataService** | Listagens paginadas usam `$this->_requestService->getAllParametersForQuery($request)` |
| **7 rotas padrão** | Todos os módulos possuem: `lookups`, `listar`, `listar/{id}`, `cadastrar`, `editar`, `excluir/{id}`, `{entidades}-list` |
| **toda crud criado recebe um arquivo com sua especificação dentro de Docs**