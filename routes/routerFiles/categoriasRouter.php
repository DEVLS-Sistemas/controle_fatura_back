<?php

use App\Http\Controllers\CategoriaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [CategoriaController::class, 'listarLookupsCategoria']);
Route::get('/listar', [CategoriaController::class, 'listarCategoria']);
Route::get('/listar/{id}', [CategoriaController::class, 'listarCategoriaId']);
Route::post('/cadastrar', [CategoriaController::class, 'createCategoria']);
Route::post('/cadastrar-rapido', [CategoriaController::class, 'cadastrarRapidoCategoria']);
Route::put('/editar', [CategoriaController::class, 'editCategoria']);
Route::delete('/excluir/{id}', [CategoriaController::class, 'deleteCategoria']);
Route::get('/categorias-list', [CategoriaController::class, 'listarCategoriaAsync']);
