<?php

use App\Http\Controllers\EstabelecimentoCategoriaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [EstabelecimentoCategoriaController::class, 'listarLookupsEstabelecimentoCategoria']);
Route::get('/listar', [EstabelecimentoCategoriaController::class, 'listarEstabelecimentoCategoria']);
Route::get('/listar/{id}', [EstabelecimentoCategoriaController::class, 'listarEstabelecimentoCategoriaId']);
Route::post('/cadastrar', [EstabelecimentoCategoriaController::class, 'createEstabelecimentoCategoria']);
Route::put('/editar', [EstabelecimentoCategoriaController::class, 'editEstabelecimentoCategoria']);
Route::delete('/excluir/{id}', [EstabelecimentoCategoriaController::class, 'deleteEstabelecimentoCategoria']);
Route::get('/estabelecimento-categorias-list', [EstabelecimentoCategoriaController::class, 'listarEstabelecimentoCategoriaAsync']);
