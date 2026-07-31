<?php

use App\Http\Controllers\EstabelecimentoController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [EstabelecimentoController::class, 'listarLookupsEstabelecimento']);
Route::get('/listar', [EstabelecimentoController::class, 'listarEstabelecimento']);
Route::get('/listar/{id}', [EstabelecimentoController::class, 'listarEstabelecimentoId']);
Route::post('/cadastrar', [EstabelecimentoController::class, 'createEstabelecimento']);
Route::put('/editar', [EstabelecimentoController::class, 'editEstabelecimento']);
Route::delete('/excluir/{id}', [EstabelecimentoController::class, 'deleteEstabelecimento']);
Route::get('/estabelecimentos-list', [EstabelecimentoController::class, 'listarEstabelecimentoAsync']);
