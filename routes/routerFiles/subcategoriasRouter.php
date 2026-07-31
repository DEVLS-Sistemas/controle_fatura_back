<?php

use App\Http\Controllers\SubcategoriaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [SubcategoriaController::class, 'listarLookupsSubcategoria']);
Route::get('/listar', [SubcategoriaController::class, 'listarSubcategoria']);
Route::get('/listar/{id}', [SubcategoriaController::class, 'listarSubcategoriaId']);
Route::post('/cadastrar', [SubcategoriaController::class, 'createSubcategoria']);
Route::put('/editar', [SubcategoriaController::class, 'editSubcategoria']);
Route::delete('/excluir/{id}', [SubcategoriaController::class, 'deleteSubcategoria']);
Route::get('/subcategorias-list', [SubcategoriaController::class, 'listarSubcategoriaAsync']);
