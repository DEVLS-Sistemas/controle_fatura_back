# Padrão de Desenvolvimento Backend Laravel

## Objetivo

Toda nova tela criada no sistema deve seguir obrigatoriamente este padrão de arquitetura backend.

O objetivo é manter:
- padronização
- organização
- reaproveitamento
- manutenção simplificada
- geração automática consistente via IA

---

# Estrutura de Rotas

Toda nova tela/módulo deverá:

1. possuir um prefixo registrado no `api.php`
2. possuir um arquivo próprio dentro de `routes/routerFiles`

Exemplo:

```php
Route::prefix('clientes')->group(function () {
    require __DIR__ . '/routerFiles/clientesRouter.php';
});

Padrão de Arquivo de Rotas

Todo arquivo de rota deverá possuir obrigatoriamente as 7 rotas padrão abaixo:

Route::get('/lookups', [ClientesController::class, 'listarLookupsClientes']);

Route::get('/listar', [ClientesController::class, 'listarClientes']);

Route::get('/listar/{id}', [ClientesController::class, 'listarClientesId']);

Route::post('/cadastrar', [ClientesController::class, 'createClientes']);

Route::put('/editar', [ClientesController::class, 'editClientes']);

Route::delete('/excluir/{id}', [ClientesController::class, 'deleteClientes']);

Route::get('/clientes-list', [ClientesController::class, 'listarClientesAsync']);

Estrutura dos Controllers

Todo controller deverá:

possuir integração com Service
possuir integração com RequestDataService
retornar sempre JSON
tratar exceptions padronizadas
utilizar status code dinâmico
Exemplo de construtor

use App\Services\RequestDataService;

protected $_service;
protected $_requestService;

public function __construct()
{
    $this->_service = new ClientesService();

    $this->_requestService = new RequestDataService();
}

Padrão das Funções Controller

Todas as funções devem seguir este padrão:

try {

    $objectAtributes = (object) $request->all();

    $result = $this->_service->handleAddClientes($objectAtributes);

    return response()->json($result, 200);

} catch (\Exception $ex) {

    $statusCode = is_numeric($ex->getCode())
        ? (int) $ex->getCode()
        : 500;

    $statusCode = ($statusCode >= 100 && $statusCode <= 599)
        ? $statusCode
        : 500;

    return response()->json([
        'error' => true,
        'message' => $ex->getMessage()
    ], $statusCode);
}

Estrutura dos Services

Toda regra de negócio deverá ficar no Service.

Organização

Todos os services deverão ficar dentro de sua pasta oficial.

Exemplo:

app/Services/Clientes/ClientesService.php

Padrão Handle Functions

Toda operação deverá possuir uma função handle.

public function handleEditClientes(object $atributes)
{
    try {

        DB::beginTransaction();

        $result = (object)[];

        $result->clientes = $this->updateClientes($atributes);

        DB::commit();

        return $result;

    } catch (Exception $e) {

        DB::rollback();

        throw $e;
    }
}

Padrão Funções CRUD
Exemplo Update

public function updateClientes(object $atributes)
{
    try {

        $queryUpdate = Cliente::where('id', $atributes->id)->first();

        $queryUpdate->fill(get_object_vars($atributes));

        $saved = $queryUpdate->save();

        if (!$saved) {
            throw new Exception('Não foi possível editar o Clientes');
        }

        return (object)[
            'data' => [],
            'status' => true,
            'message' => 'Clientes alterada com sucesso!',
        ];

    } catch (Exception $e) {
        throw $e;
    }
}

Estrutura de Models

Toda nova tela deverá possuir:

migration
model
service
controller
arquivo de rotas
Padrão de Models

Todos os models deverão:

utilizar SoftDeletes
possuir fillable
possuir casts
possuir relacionamentos
possuir protected table
Exemplo

use Illuminate\Database\Eloquent\SoftDeletes;

class Clientes extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'nome',
        'telefone',
        'ativo'
    ];

    protected $casts = [
        'id' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];
}

Padrão de Migrations

Todas as migrations deverão:

possuir timestamps()
possuir softDeletes()
possuir tipos corretos
possuir seed inicial quando necessário
Exemplo

Padrão de Migrations

Todas as migrations deverão:

possuir timestamps()
possuir softDeletes()
possuir tipos corretos
possuir seed inicial quando necessário
Exemplo

Seed Inicial na Migration

Quando necessário:
DB::table('clientes')->insert([
    [
        'nome' => 'Cliente Padrão',
        'telefone' => '99999999999'
    ],
]);

DB::table('clientes')->insert([
    [
        'nome' => 'Cliente Padrão',
        'telefone' => '99999999999'
    ],
]);

Padrão de Geração de Novas Funcionalidades

Sempre que for solicitada uma nova tela:

A IA deverá gerar:

Migration
Model
Controller
Service
Arquivo de rota
Registro no api.php
Métodos CRUD padrão
Lookups
Async List
Relacionamentos
Fillable
Casts
SoftDeletes

Objetivo Final

Toda nova funcionalidade do sistema deve seguir exatamente esta arquitetura.
Nenhuma implementação deve fugir deste padrão.


Esse documento vira uma “fonte da verdade” para o Cursor.

Depois você pode criar também:
- `Docs/frontend-patterns.md`
- `Docs/specification-pattern.md`
- `Docs/database-patterns.md`

E aí fazer o Cursor gerar telas completas automaticamente com:
- migration
- model
- API
- hooks
- services front
- páginas
- formulários
- tabelas
- filtros
- modais
- validações
- permissões
- etc.

